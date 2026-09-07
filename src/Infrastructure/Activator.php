<?php

namespace Formhawk\Infrastructure;

final class Activator {
	const CRON_HOOK = 'formhawk_daily_cleanup';

	public static function activate() {
		Database::install();
		if ( ! get_option( 'formhawk_install_time' ) ) {
			add_option( 'formhawk_install_time', time(), '', false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Cleanup::CONTINUE_HOOK );
	}
}
