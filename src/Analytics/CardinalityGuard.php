<?php

namespace Formhawk\Analytics;

use Formhawk\Contracts\BudgetStoreInterface;
use Formhawk\Domain\FormIdentity;
use Formhawk\Infrastructure\AtomicBudgetStore;
use Formhawk\Infrastructure\Database;

final class CardinalityGuard {
	private $budgets;

	public function __construct( BudgetStoreInterface $budgets = null ) {
		$this->budgets = $budgets ? $budgets : new AtomicBudgetStore();
	}

	/** Returns accepted, cardinality, or storage. No visitor identity is involved. */
	public function admit( array $event ) {
		global $wpdb;
		$form       = FormIdentity::key( $event['provider'], $event['provider_form_id'], $event['page_path'] );
		$dimensions = array(
			hash( 'sha256', 'form|' . $form ) => 'forms',
			hash( 'sha256', 'path|' . $event['page_path'] ) => 'paths',
			hash( 'sha256', 'placement|' . $form . '|' . $event['page_path'] ) => 'placements',
		);
		$fields     = isset( $event['fields'] ) ? $event['fields'] : array();
		if ( isset( $event['field'] ) ) {
			$fields[] = $event['field'];
		}
		foreach ( $fields as $field ) {
			$dimensions[ hash( 'sha256', 'field|' . $form . '|' . $field['key'] ) ] = 'fields';
		}
		$missing = $this->missing( $dimensions );
		if ( null === $missing ) {
			return 'storage';
		}
		if ( empty( $missing ) ) {
			return 'accepted';
		}

		// Only first admissions serialize. Named locks avoid committing a provider's open transaction.
		// A zero timeout drops analytics under contention instead of blocking the customer's form.
		$lock = Database::dimension_lock_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database-wide coordination for new structural dimensions, scoped to this site.
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return 'storage';
		}
		try {
			$missing = $this->missing( $dimensions );
			if ( null === $missing ) {
				return 'storage';
			}
			$limits       = IngestionLimits::all();
			$charges      = array_count_values( $missing );
			$reservations = array();
			foreach ( $charges as $kind => $count ) {
				$reservations[] = array( 'dimensions|' . $kind . '|total', $count, $limits[ $kind . '_total' ], 0 );
				$reservations[] = array( 'dimensions|' . $kind . '|day', $count, $limits[ $kind . '_per_day' ], $limits['dimension_window_seconds'] );
			}
			foreach ( array( 'placements', 'fields' ) as $kind ) {
				if ( ! empty( $charges[ $kind ] ) ) {
					$reservations[] = array( 'form|' . $form . '|' . $kind, $charges[ $kind ], $limits[ $kind . '_per_form' ], 0 );
				}
			}
			$per_form = ( $charges['fields'] ?? 0 ) + ( $charges['placements'] ?? 0 );
			if ( $per_form ) {
				$reservations[] = array( 'form|' . $form . '|day', $per_form, $limits['dimensions_per_form_per_day'], $limits['dimension_window_seconds'] );
			}
			// All dimension-budget callers hold this same site lock. Rejecting any limit must
			// not drain another lifetime budget, or repeated rejected requests could exhaust it.
			foreach ( $reservations as $reservation ) {
				if ( ! $this->budgets->can_reserve( ...$reservation ) ) {
					return 'storage' === $this->budgets->last_failure() ? 'storage' : 'cardinality';
				}
			}
			foreach ( $reservations as $reservation ) {
				if ( ! $this->budgets->reserve( ...$reservation ) ) {
					return 'storage';
				}
			}
			foreach ( $missing as $key => $kind ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only bounded hashes of form structure are registered; no raw event log.
				if ( false === $wpdb->insert(
					Database::dimensions_table(),
					array(
						'dimension_key' => $key,
						'kind'          => $kind,
					),
					array( '%s', '%s' )
				) ) {
					return 'storage';
				}
			}
			return 'accepted';
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Always release this connection's structural-admission lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function missing( array $dimensions ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $dimensions ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only a bounded list of fixed placeholders is interpolated.
		$sql = $wpdb->prepare( 'SELECT dimension_key FROM %i WHERE dimension_key IN (' . $placeholders . ')', array_merge( array( Database::dimensions_table() ), array_keys( $dimensions ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One bounded primary-key lookup; fresh reads are required across concurrent requests.
		$known = $wpdb->get_col( $sql );
		if ( $wpdb->last_error ) {
			return null;
		}
		return array_diff_key( $dimensions, array_fill_keys( $known, true ) );
	}
}
