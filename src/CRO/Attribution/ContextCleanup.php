<?php

namespace Formhawk\CRO\Attribution;

use Formhawk\Infrastructure\Database;

final class ContextCleanup {
	const HOOK          = 'formhawk_cro_context_cleanup';
	const CONTINUE_HOOK = 'formhawk_cro_context_cleanup_continue';

	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( self::CONTINUE_HOOK, array( $this, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 900, 'formhawk_fifteen_minutes', self::HOOK );
		}
	}

	public static function schedules( $schedules ) {
		$schedules['formhawk_fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => __( 'Every fifteen minutes', 'formhawk' ),
		);
		return $schedules;
	}

	public function run() {
		if ( ! Database::cro_schema_is_current() ) {
			return;
		}
		if ( 5000 === ContextStore::cleanup() && ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CONTINUE_HOOK );
		}
	}
}
