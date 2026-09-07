<?php

namespace Formhawk\Integrations;

final class MailMonitor {
	public function register() {
		add_action( 'wp_mail_failed', array( $this, 'failed' ), 10, 1 );
		add_action( 'wp_mail_succeeded', array( $this, 'succeeded' ), 10, 1 );
	}

	public function failed( $error ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$health                 = $this->health();
		$health['last_failure'] = current_time( 'mysql' );
		$health['failures']     = isset( $health['failures'] ) ? absint( $health['failures'] ) + 1 : 1;
		update_option( 'formhawk_mail_health', $health, false );
	}

	public function succeeded( $mail_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$health                 = $this->health();
		$health['last_success'] = current_time( 'mysql' );
		$health['successes']    = isset( $health['successes'] ) ? absint( $health['successes'] ) + 1 : 1;
		update_option( 'formhawk_mail_health', $health, false );
	}

	private function health() {
		$health = get_option( 'formhawk_mail_health', array() );
		return is_array( $health ) ? $health : array();
	}
}
