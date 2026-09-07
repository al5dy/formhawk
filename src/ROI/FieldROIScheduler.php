<?php

namespace Formhawk\ROI;

final class FieldROIScheduler {
	const CRON_HOOK             = 'formhawk_field_roi_evaluate';
	const LOCK_OPTION           = 'formhawk_field_roi_lock';
	const CURSOR_OPTION         = 'formhawk_field_roi_cursor';
	const OUTCOME_CURSOR_OPTION = 'formhawk_field_roi_outcome_cursor';
	private $engine;

	public function __construct( FieldROIEngine $engine = null ) {
		$this->engine = $engine ? $engine : new FieldROIEngine(); }
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	public function run() {
		$settings = get_option( 'formhawk_field_roi_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['enabled'] ) ) {
			return;
		}
		$lock = absint( get_option( self::LOCK_OPTION, 0 ) );
		if ( $lock && $lock > time() - 15 * MINUTE_IN_SECONDS ) {
			return; }
		if ( $lock ) {
			delete_option( self::LOCK_OPTION ); }
		if ( ! add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return; }
		try {
			$outcome_cursor      = absint( get_option( self::OUTCOME_CURSOR_OPTION, 0 ) );
			$next_outcome_cursor = $this->engine->refresh_daily_aggregates( $outcome_cursor );
			if ( false === $next_outcome_cursor ) {
				return;
			}
			update_option( self::OUTCOME_CURSOR_OPTION, $next_outcome_cursor, false );
			$cursor = absint( get_option( self::CURSOR_OPTION, 0 ) );
			$count  = $this->engine->run_batch( $cursor, 25 );
			if ( false === $count ) {
				return;
			}
			update_option( self::CURSOR_OPTION, $count < 25 ? 0 : $cursor + $count, false );
			update_option( 'formhawk_field_roi_last_evaluated', current_time( 'mysql', true ), false );
			if ( $count < 25 ) {
				delete_option( 'formhawk_field_roi_dirty' ); }
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}
}
