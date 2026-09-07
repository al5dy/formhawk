<?php

namespace Formhawk\CRO;

use Formhawk\Infrastructure\Database;

final class CRODiagnostics {
	const COUNTERS = array( 'rejected_config_requests', 'throttled_config_requests', 'rejected_event_requests', 'throttled_event_requests', 'storage_failures' );

	public function increment( $counter ) {
		global $wpdb;
		if ( ! in_array( $counter, self::COUNTERS, true ) ) {
			return;
		}
		// A fixed UTC-day aggregate contains no request, visitor or assignment dimensions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic bounded aggregate diagnostic.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (scope_key, window_id, used) VALUES (%s, %d, 1)
				ON DUPLICATE KEY UPDATE used = IF(window_id < VALUES(window_id), 1, LEAST(1000000000, used + 1)), window_id = GREATEST(window_id, VALUES(window_id))',
				Database::budgets_table(),
				'cro_diagnostic_' . $counter,
				(int) floor( time() / DAY_IN_SECONDS )
			)
		);
	}

	public function today() {
		global $wpdb;
		$result = array_fill_keys( self::COUNTERS, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Five fixed aggregate keys; no visitor dimensions.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT scope_key, used FROM %i WHERE scope_key LIKE %s AND window_id = %d LIMIT 5', Database::budgets_table(), $wpdb->esc_like( 'cro_diagnostic_' ) . '%', (int) floor( time() / DAY_IN_SECONDS ) ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$key = substr( $row['scope_key'], strlen( 'cro_diagnostic_' ) );
			if ( isset( $result[ $key ] ) ) {
				$result[ $key ] = absint( $row['used'] );
			}
		}
		return $result;
	}
}
