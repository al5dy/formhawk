<?php

namespace Formhawk\Infrastructure;

final class Cleanup {
	const CONTINUE_HOOK = 'formhawk_cleanup_continue';
	const BATCH_SIZE    = 5000;

	public function register() {
		add_action( Activator::CRON_HOOK, array( $this, 'run' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'run' ) );
		if ( ! wp_next_scheduled( Activator::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Activator::CRON_HOOK );
		}
	}

	public function run() {
		global $wpdb;
		$settings = get_option( 'formhawk_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$days     = isset( $settings['retention_days'] ) ? absint( $settings['retention_days'] ) : 90;
		$days     = max( 30, min( 365, $days ) );
		$today    = new \DateTimeImmutable( 'today', wp_timezone() );
		$cutoff   = $today->modify( '-' . $days . ' days' )->format( 'Y-m-d' );

		$more = false;
		foreach ( array( Database::daily_table(), Database::fields_table(), Database::placement_daily_table() ) as $table ) {
			$deleted = $this->delete_batch( $table, $cutoff );
			$more    = self::BATCH_SIZE === $deleted || $more;
		}
		if ( Database::cro_schema_is_current() ) {
			$deleted = $this->delete_cro_batch( $cutoff );
			$more    = self::BATCH_SIZE === $deleted || $more;
		}

		if ( $more && ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
		}
	}

	private function delete_batch( $table, $cutoff ) {
		global $wpdb;

		$sql = $wpdb->prepare( 'DELETE FROM %i WHERE stat_date < %s LIMIT %d', $table, $cutoff, self::BATCH_SIZE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded retention deletion from an allowlisted Formhawk aggregate table.
		$deleted = $wpdb->query( $sql );

		return is_int( $deleted ) ? $deleted : 0;
	}

	private function delete_cro_batch( $cutoff ) {
		global $wpdb;

		// Active/paused experiments retain their whole analysis window. Finished
		// arm aggregates follow normal retention; the immutable decision survives.
		$sql = $wpdb->prepare(
			'DELETE FROM %i WHERE id IN (
				SELECT id FROM (
					SELECT d.id FROM %i d LEFT JOIN %i e ON e.id = d.experiment_id
					WHERE d.stat_date < %s AND (e.id IS NULL OR e.status NOT IN (%s, %s, %s, %s, %s, %s))
					LIMIT %d
				) stale
			)',
			Database::experiment_daily_table(),
			Database::experiment_daily_table(),
			Database::experiments_table(),
			$cutoff,
			'suggested',
			'awaiting_approval',
			'running',
			'paused_guardrail',
			'paused_manual',
			'promoted_monitoring',
			self::BATCH_SIZE
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded aggregate retention with explicit active-experiment protection.
		$deleted = $wpdb->query( $sql );
		return is_int( $deleted ) ? $deleted : 0;
	}
}
