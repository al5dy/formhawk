<?php

namespace Formhawk\Infrastructure;

final class IngestionDiagnostics {
	const COUNTERS = array( 'rejected_events', 'rejected_requests', 'throttled_events', 'throttled_requests', 'cardinality_rejected_events', 'storage_rejected_events' );

	public function increment( $counter, $amount = 1 ) {
		global $wpdb;
		if ( ! in_array( $counter, self::COUNTERS, true ) || $amount < 1 ) {
			return;
		}
		// Fixed labels and one UTC-day slot per label. No request data or visitor dimensions.
		$day = (int) floor( time() / DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic bounded aggregate diagnostics, saturating on prolonged abuse.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (scope_key, window_id, used) VALUES (%s, %d, %d)
				ON DUPLICATE KEY UPDATE used = IF(window_id < VALUES(window_id), VALUES(used), LEAST(1000000000, used + VALUES(used))), window_id = GREATEST(window_id, VALUES(window_id))',
				Database::budgets_table(),
				'diagnostic_' . $counter,
				$day,
				min( 1000000000, (int) $amount )
			)
		);
	}

	public function today() {
		global $wpdb;
		$result = array_fill_keys( self::COUNTERS, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Six fixed diagnostic keys; no visitor-level data.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT scope_key, used FROM %i WHERE scope_key LIKE %s AND window_id = %d LIMIT 6', Database::budgets_table(), $wpdb->esc_like( 'diagnostic_' ) . '%', (int) floor( time() / DAY_IN_SECONDS ) ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$key = substr( $row['scope_key'], strlen( 'diagnostic_' ) );
			if ( isset( $result[ $key ] ) ) {
				$result[ $key ] = (int) $row['used'];
			}
		}
		return $result;
	}
}
