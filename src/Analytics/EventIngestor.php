<?php

namespace Formhawk\Analytics;

use Formhawk\Contracts\EventRecorderInterface;
use Formhawk\Domain\ProviderCatalog;
use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\IngestionDiagnostics;
use Formhawk\Support\Sanitizer;

final class EventIngestor implements EventRecorderInterface {
	private $forms;
	private $normalizer;
	private $guard;
	private $diagnostics;
	private $rejection      = '';
	private $write_failed   = false;
	private $identity_cache = array();

	public function __construct( FormRepository $forms, EventNormalizer $normalizer = null, CardinalityGuard $guard = null, IngestionDiagnostics $diagnostics = null ) {
		$this->forms       = $forms;
		$this->guard       = $guard ? $guard : new CardinalityGuard();
		$this->diagnostics = $diagnostics ? $diagnostics : new IngestionDiagnostics();
		$this->normalizer  = $normalizer ? $normalizer : new EventNormalizer();
	}

	public function last_rejection() {
		return $this->rejection;
	}

	private function reject( $reason ) {
		$this->rejection = $reason;
		$this->diagnostics->increment( 'rejected_events' );
		if ( in_array( $reason, array( 'cardinality', 'storage' ), true ) ) {
			$this->diagnostics->increment( $reason . '_rejected_events' );
		}
		return false;
	}

	public function ingest_client( array $event ) {
		$normalized = $this->normalizer->client( $event );
		if ( ! is_array( $normalized ) ) {
			return $this->reject( 'schema' );
		}

		return $this->ingest( $normalized, false );
	}

	public function record_success( $provider, $provider_form_id, $title, $page_path, array $context = array() ) {
		return $this->ingest_server(
			'form_success',
			array_merge(
				$context,
				$this->provider_event( $provider, $provider_form_id, $title, $page_path )
			)
		);
	}

	public function record_failure( $provider, $provider_form_id, $title, $page_path, $code ) {
		$event                 = $this->provider_event( $provider, $provider_form_id, $title, $page_path );
		$event['failure_code'] = $code;

		return $this->ingest_server( 'form_failure', $event );
	}

	public function record_validation_failure( $provider, $provider_form_id, $title, $page_path, array $fields = array() ) {
		$event           = $this->provider_event( $provider, $provider_form_id, $title, $page_path );
		$event['fields'] = $fields;

		return $this->ingest_server( 'validation_failure', $event );
	}

	public function record_mail_success( $provider, $provider_form_id, $title, $page_path ) {
		return $this->ingest_server( 'mail_success', $this->provider_event( $provider, $provider_form_id, $title, $page_path ) );
	}

	public function record_mail_failure( $provider, $provider_form_id, $title, $page_path ) {
		return $this->ingest_server( 'mail_failure', $this->provider_event( $provider, $provider_form_id, $title, $page_path ) );
	}

	/**
	 * Backward-compatible wrappers for code using the 0.1.x integration seam.
	 */
	public function cf7_success( $form_id, $title, $page_path ) {
		return $this->record_success( ProviderCatalog::CF7, (string) absint( $form_id ), $title, $page_path, array( 'mail_success' => true ) );
	}

	public function cf7_validation_error( $form_id, $title, $page_path, $field_key ) {
		return $this->record_validation_failure(
			ProviderCatalog::CF7,
			(string) absint( $form_id ),
			$title,
			$page_path,
			array(
				array(
					'key'   => $field_key,
					'label' => $field_key,
					'type'  => '',
				),
			)
		);
	}

	public function cf7_failure( $form_id, $title, $page_path, $code = 'aborted' ) {
		return $this->record_failure( ProviderCatalog::CF7, (string) absint( $form_id ), $title, $page_path, $code );
	}

	public function cf7_mail_failure( $form_id, $title, $page_path ) {
		return $this->record_mail_failure( ProviderCatalog::CF7, (string) absint( $form_id ), $title, $page_path );
	}

	private function ingest_server( $type, array $event ) {
		return $this->ingest( $this->normalizer->server( $type, $event ), true );
	}

	private function provider_event( $provider, $provider_form_id, $title, $page_path ) {
		return array(
			'provider'         => $provider,
			'provider_form_id' => $provider_form_id,
			'title'            => $title,
			'page_path'        => $page_path,
		);
	}

	private function ingest( array $event, $trusted ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			return $this->write_event( $event, $trusted );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	private function write_event( array $event, $trusted ) {
		if ( ! Database::ingestion_ready() ) {
			return $this->reject( 'storage' );
		}
		$this->rejection    = '';
		$this->write_failed = false;
		$admission          = $this->guard->admit( $event );
		if ( 'accepted' !== $admission ) {
			return $this->reject( $admission );
		}
		$type         = isset( $event['type'] ) ? $event['type'] : '';
		$identity_key = implode(
			'|',
			array(
				isset( $event['provider'] ) ? $event['provider'] : '',
				isset( $event['provider_form_id'] ) ? $event['provider_form_id'] : '',
				isset( $event['page_path'] ) ? $event['page_path'] : '/',
			)
		);
		if ( ! isset( $this->identity_cache[ $identity_key ] ) ) {
			$this->identity_cache[ $identity_key ] = $this->forms->resolve( $event );
		}
		$identity     = $this->identity_cache[ $identity_key ];
		$form_id      = $identity['form_id'];
		$placement_id = $identity['placement_id'];
		if ( ! $form_id || ! $placement_id ) {
			return $this->reject( 'storage' );
		}

		$duration = Sanitizer::duration_ms( isset( $event['duration_ms'] ) ? $event['duration_ms'] : 0 );
		$field    = isset( $event['field'] ) && is_array( $event['field'] ) ? $event['field'] : array();
		$fields   = isset( $event['fields'] ) && is_array( $event['fields'] ) ? $event['fields'] : array();

		switch ( $type ) {
			case 'form_view':
				$this->increment_aggregates( $form_id, $placement_id, array( 'views' => 1 ) );
				break;
			case 'form_start':
				$this->increment_aggregates( $form_id, $placement_id, array( 'starts' => 1 ) );
				break;
			case 'field_interaction':
				$this->increment_field( $form_id, $field, 'interactions' );
				break;
			case 'validation_error':
				$this->increment_field( $form_id, $field, 'client_validation_errors' );
				break;
			case 'client_validation_failure':
				$this->increment_aggregates( $form_id, $placement_id, array( 'client_validation_failures' => 1 ) );
				$this->increment_fields( $form_id, $fields, 'client_validation_errors' );
				break;
			case 'validation_failure':
				if ( ! $trusted ) {
					return $this->reject( 'schema' );
				}
				$this->increment_aggregates(
					$form_id,
					$placement_id,
					array(
						'provider_validation_failures' => 1,
						'provider_validation_outcomes' => 1,
					)
				);
				$this->increment_fields( $form_id, $fields, 'provider_validation_errors' );
				break;
			case 'form_abandon':
				$increments = array( 'abandons' => 1 );
				if ( $duration > 0 ) {
					$increments['duration_total_ms'] = $duration;
					$increments['duration_samples']  = 1;
				}
				$this->increment_aggregates( $form_id, $placement_id, $increments );
				if ( ! empty( $field ) ) {
					$this->increment_field( $form_id, $field, 'abandonments' );
				}
				break;
			case 'form_submit':
				$increments = array( 'submit_attempts' => 1 );
				if ( $duration > 0 ) {
					$increments['duration_total_ms'] = $duration;
					$increments['duration_samples']  = 1;
				}
				$this->increment_aggregates( $form_id, $placement_id, $increments );
				break;
			case 'form_success':
				if ( ! $trusted ) {
					return false;
				}
				$increments = array(
					'provider_validation_outcomes' => 1,
					'confirmed_successes'          => 1,
				);
				if ( ! empty( $event['mail_success'] ) ) {
					$increments['mail_successes'] = 1;
				}
				$this->increment_aggregates( $form_id, $placement_id, $increments );
				$this->forms->mark_success( $form_id );
				if ( ! empty( $event['mail_success'] ) ) {
					$this->forms->mark_mail_success( $form_id );
				}
				break;
			case 'form_failure':
				if ( ! $trusted ) {
					return false;
				}
				$this->increment_aggregates( $form_id, $placement_id, array( 'failures' => 1 ) );
				$this->forms->mark_failure( $form_id, isset( $event['failure_code'] ) ? $event['failure_code'] : 'form_failure', false );
				break;
			case 'mail_success':
				if ( ! $trusted ) {
					return false;
				}
				$this->increment_aggregates( $form_id, $placement_id, array( 'mail_successes' => 1 ) );
				$this->forms->mark_mail_success( $form_id );
				break;
			case 'mail_failure':
				if ( ! $trusted ) {
					return false;
				}
				$this->increment_aggregates(
					$form_id,
					$placement_id,
					array(
						'failures'      => 1,
						'mail_failures' => 1,
					)
				);
				$this->forms->mark_failure( $form_id, 'mail_failure', true );
				break;
			default:
				return false;
		}

		if ( $this->has_write_failed() ) {
			return $this->reject( 'storage' );
		}
		do_action( 'formhawk_event_recorded', $type, $form_id, $event );
		return true;
	}

	private function has_write_failed() {
		return $this->write_failed;
	}

	private function increment_aggregates( $form_id, $placement_id, array $increments ) {
		$this->increment_daily( Database::daily_table(), 'form_id', $form_id, $increments );
		if ( $placement_id > 0 ) {
			$this->increment_daily( Database::placement_daily_table(), 'placement_id', $placement_id, $increments );
		}
	}

	private function increment_daily( $table, $identity_column, $identity_id, array $increments ) {
		global $wpdb;
		$allowed    = array( 'views', 'starts', 'submit_attempts', 'confirmed_successes', 'abandons', 'client_validation_failures', 'provider_validation_failures', 'provider_validation_outcomes', 'failures', 'mail_successes', 'mail_failures', 'duration_total_ms', 'duration_samples' );
		$increments = array_intersect_key( $increments, array_flip( $allowed ) );
		if ( ! $increments ) {
			return;
		}
		$columns = array_keys( $increments );
		$args    = array_merge( array( $table, $identity_column ), $columns, array( absint( $identity_id ), current_time( 'Y-m-d' ) ), array_map( 'absint', array_values( $increments ) ) );
		$updates = array();
		foreach ( $columns as $column ) {
			$updates[] = '%i = %i + VALUES(%i)';
			array_push( $args, $column, $column, $column );
		}
		$query = 'INSERT INTO %i (%i, stat_date, ' . implode( ', ', array_fill( 0, count( $columns ), '%i' ) )
			. ') VALUES (%d, %s, ' . implode( ', ', array_fill( 0, count( $columns ), '%d' ) )
			. ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only fixed placeholder fragments are composed; columns are selected from the internal counter allowlist.
		$sql = $wpdb->prepare( $query, $args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared above from fixed placeholder fragments and allowlisted counter columns; every identifier/value is bound. Paired validation counters share one atomic statement.
		$result             = $wpdb->query( $sql );
		$this->write_failed = false === $result || $this->write_failed;
	}

	private function increment_field( $form_id, array $field, $column ) {
		$this->increment_fields( $form_id, array( $field ), $column );
	}

	private function increment_fields( $form_id, array $fields, $column ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'interactions', 'abandonments', 'client_validation_errors', 'provider_validation_errors' ), true ) || empty( $fields ) ) {
			return;
		}

		$values = array();
		$args   = array( Database::fields_table(), $column );
		$date   = current_time( 'Y-m-d' );
		foreach ( array_slice( $fields, 0, 50 ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key      = Sanitizer::identifier( isset( $field['key'] ) ? $field['key'] : '', 'unknown' );
			$label    = Sanitizer::field_label( isset( $field['label'] ) ? $field['label'] : $key );
			$type     = Sanitizer::field_type( isset( $field['type'] ) ? $field['type'] : '' );
			$values[] = '(%d, %s, %s, %s, %s, 1)';
			array_push( $args, absint( $form_id ), $date, $key, $label, $type );
		}
		if ( empty( $values ) ) {
			return;
		}
		$args[] = $column;
		$args[] = $column;

		$query = 'INSERT INTO %i (form_id, stat_date, field_key, field_label, field_type, %i) VALUES '
			. implode( ', ', $values )
			. ' ON DUPLICATE KEY UPDATE
				field_label = IF(
					field_label = \'\' OR (field_label = field_key AND VALUES(field_label) <> VALUES(field_key)),
					VALUES(field_label),
					field_label
				),
				field_type = IF(VALUES(field_type) <> \'\', VALUES(field_type), field_type),
				%i = %i + 1';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The dynamic fragment is a bounded list of fixed placeholder groups; values remain prepared below.
		$sql = $wpdb->prepare( $query, $args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Atomic counter upsert; the table/column identifiers are allowlisted and all field values were prepared above.
		$result             = $wpdb->query( $sql );
		$this->write_failed = false === $result || $this->write_failed;
	}
}
