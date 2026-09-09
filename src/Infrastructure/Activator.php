<?php

namespace Formhawk\Infrastructure;

use Formhawk\CRO\AutopilotManager;
use Formhawk\CRO\Attribution\ContextCleanup;
use Formhawk\ROI\FieldROIScheduler;

final class Activator {
	const CRON_HOOK = 'formhawk_daily_cleanup';

	public static function activate() {
		Database::install();
		if ( Database::cro_schema_is_current() ) {
			( new ContextCleanup() )->register();
		}
		if ( ! get_option( 'formhawk_install_time' ) ) {
			add_option( 'formhawk_install_time', time(), '', false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( AutopilotManager::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', AutopilotManager::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( FieldROIScheduler::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', FieldROIScheduler::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( ContextCleanup::HOOK );
		wp_clear_scheduled_hook( ContextCleanup::CONTINUE_HOOK );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Cleanup::CONTINUE_HOOK );
		wp_clear_scheduled_hook( AutopilotManager::CRON_HOOK );
		wp_clear_scheduled_hook( FieldROIScheduler::CRON_HOOK );
	}
}
