<?php

namespace Formhawk\Infrastructure;

use Formhawk\Contracts\BudgetStoreInterface;

final class AtomicBudgetStore implements BudgetStoreInterface {
	private $clock;
	private $failure = '';

	public function last_failure() {
		return $this->failure;
	}

	public function __construct( callable $clock = null ) {
		$this->clock = $clock ? $clock : 'time';
	}

	public function can_reserve( $scope, $cost, $limit, $window_seconds ) {
		global $wpdb;
		$this->failure = '';
		if ( $cost < 1 || $limit < $cost ) {
			$this->failure = 'limit';
			return false;
		}
		$window = $window_seconds > 0 ? (int) floor( call_user_func( $this->clock ) / $window_seconds ) : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Preflight under the caller's site-wide dimension lock; never used as the rate-limiter decision.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT window_id, used FROM %i WHERE scope_key = %s', Database::budgets_table(), hash( 'sha256', $scope ) ), ARRAY_A );
		if ( $wpdb->last_error ) {
			$this->failure = 'storage';
			return false;
		}
		if ( $row && ( (int) $row['window_id'] > $window || ( (int) $row['window_id'] === $window && (int) $row['used'] > $limit - $cost ) ) ) {
			$this->failure = 'limit';
			return false;
		}
		return true;
	}

	public function reserve( $scope, $cost, $limit, $window_seconds ) {
		global $wpdb;
		$this->failure = '';
		$cost          = (int) $cost;
		$limit         = (int) $limit;
		if ( $cost < 1 || $limit < $cost ) {
			$this->failure = 'limit';
			return false;
		}
		$window = $window_seconds > 0 ? (int) floor( call_user_func( $this->clock ) / $window_seconds ) : 0;
		$key    = hash( 'sha256', $scope );
		// A stable key is reused across windows: even a sustained attack cannot create minute rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Initialize an internal budget slot; INSERT IGNORE is race-safe.
		$created = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (scope_key, window_id, used) VALUES (%s, %d, 0)', Database::budgets_table(), $key, $window ) );
		if ( false === $created ) {
			$this->failure = 'storage';
			return false;
		}
		// Conditional UPDATE is the admission decision. No PHP read/modify/write or object-cache dependency.
		// Monotonic windows prevent a delayed request from resetting a newer window backwards.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic bounded reservation on one primary-key row.
		$updated       = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET used = IF(window_id < %d, %d, used + %d), window_id = %d
				WHERE scope_key = %s AND window_id <= %d AND (window_id < %d OR used <= %d)',
				Database::budgets_table(),
				$window,
				$cost,
				$cost,
				$window,
				$key,
				$window,
				$window,
				$limit - $cost
			)
		);
		$this->failure = false === $updated ? 'storage' : ( 1 === $updated ? '' : 'limit' );
		return 1 === $updated;
	}
}
