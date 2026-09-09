<?php

namespace Formhawk\CRO\Attribution;

use Formhawk\Contracts\CROContextStoreInterface;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\Infrastructure\Database;

/** Short-lived assignment admission. Both context state and counters are transactional. */
final class ContextStore implements CROContextStoreInterface {

	const MAX_CONTEXTS = 50000;
	private $experiments;

	public function __construct( ExperimentRepository $experiments = null ) {
		$this->experiments = $experiments ? $experiments : new ExperimentRepository();
	}

	public function issue( array $context ) {
		global $wpdb;
		// One stable site lock bounds capacity even during concurrent configuration requests.
		$database = defined( 'DB_NAME' ) ? constant( 'DB_NAME' ) : '';
		$lock     = 'fh_cro_' . substr( hash( 'sha256', $database . ':' . Database::cro_contexts_table() ), 0, 48 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Database lock, not cached data.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return false;
		}
		try {
			self::cleanup( 1000 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Table capacity is bounded; checked under the issuance lock.
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::cro_contexts_table() ) );
			if ( null === $count || (int) $count >= self::MAX_CONTEXTS || ! $this->transaction( 'START TRANSACTION' ) ) {
				return false;
			}
			$row = $this->identity( $context );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- JTI hash is the primary key; an existing issuance is never counted again.
			$inserted = $wpdb->insert( Database::cro_contexts_table(), $row, array( '%s', '%d', '%d', '%d', '%s', '%s', '%s' ) );
			if ( ! $inserted || ! $this->experiments->increment( $context['experiment_id'], $context['variant_id'], $context['segment'], array( 'assignments' => 1 ) ) ) {
				$this->transaction( 'ROLLBACK' );
				return false;
			}
			return $this->commit();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Release the same fixed site lock on every exit path.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public function is_issued( array $context ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live, indexed authorization check; expired assignments cannot be attributed.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE context_hash=%s', Database::cro_contexts_table(), hash( 'sha256', $context['jti'] ) ), ARRAY_A );
		return is_array( $row ) && $this->matches( $row, $context ) && $context['exp'] > time();
	}

	public function consume( array $context, $type, $attempt = 0, $latency = null, $successful = false ) {
		global $wpdb;
		if ( ! $this->transaction( 'START TRANSACTION' ) ) {
			return 'storage_failures';
		}
		// The row lock serializes simultaneous identical events, including the aggregate update.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh transactional state cannot use the object cache.
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE context_hash=%s FOR UPDATE', Database::cro_contexts_table(), hash( 'sha256', $context['jti'] ) ), ARRAY_A );
		$result = $wpdb->last_error ? 'storage_failures' : 'unknown_context';
		if ( is_array( $row ) ) {
			$result = ! $this->matches( $row, $context ) ? 'invalid_context_lifecycle' : ( $context['exp'] <= time() ? 'expired_context' : 'accepted' );
		}
		if ( 'accepted' !== $result ) {
			$this->transaction( 'ROLLBACK' );
			return $result;
		}
		foreach ( array( 'attempts', 'terminal_attempt', 'observed_attempt', 'latency_attempt', 'resumed_attempt' ) as $column ) {
			$row[ $column ] = (int) $row[ $column ];
		}
		$transition = ClientEventLifecycle::transition( $row, $type, $attempt, $latency, $successful );
		if ( 'accepted' !== $transition['result'] ) {
			$this->transaction( 'ROLLBACK' );
			return $transition['result'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The locked lifecycle and its aggregates commit or roll back together.
		$updated = $wpdb->update( Database::cro_contexts_table(), $transition['changes'], array( 'context_hash' => $row['context_hash'] ), array_fill( 0, count( $transition['changes'] ), '%d' ), array( '%s' ) );
		if ( false === $updated || ( $transition['increments'] && ! $this->experiments->increment( $context['experiment_id'], $context['variant_id'], $context['segment'], $transition['increments'] ) ) ) {
			$this->transaction( 'ROLLBACK' );
			return 'storage_failures';
		}
		return $this->commit() ? 'accepted' : 'storage_failures';
	}

	public static function cleanup( $limit = 5000 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Expiry-indexed, bounded deletion of disposable context state only.
		return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at_utc <= %s ORDER BY expires_at_utc LIMIT %d', Database::cro_contexts_table(), gmdate( 'Y-m-d H:i:s' ), max( 1, min( 5000, (int) $limit ) ) ) );
	}

	private function identity( array $context ) {
		return array(
			'context_hash'   => hash( 'sha256', $context['jti'] ),
			'experiment_id'  => $context['experiment_id'],
			'variant_id'     => $context['variant_id'],
			'form_id'        => $context['form_id'],
			'segment'        => $context['segment'],
			'issued_at_utc'  => gmdate( 'Y-m-d H:i:s', $context['iat'] ),
			'expires_at_utc' => gmdate( 'Y-m-d H:i:s', $context['exp'] ),
		);
	}

	private function matches( array $row, array $context ) {
		foreach ( $this->identity( $context ) as $key => $value ) {
			if ( (string) $row[ $key ] !== (string) $value ) {
				return false;
			}
		}
		return true;
	}

	private function transaction( $statement ) {
		global $wpdb;
		switch ( $statement ) {
			case 'START TRANSACTION':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic lifecycle admission requires a database transaction.
				return false !== $wpdb->query( 'START TRANSACTION' );
			case 'COMMIT':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Commit both state and aggregate together.
				return false !== $wpdb->query( 'COMMIT' );
			case 'ROLLBACK':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A failed counter write must not consume the lifecycle event.
				return false !== $wpdb->query( 'ROLLBACK' );
		}
		return false;
	}

	private function commit() {
		if ( $this->transaction( 'COMMIT' ) ) {
			return true;
		}
		$this->transaction( 'ROLLBACK' );
		return false;
	}
}
