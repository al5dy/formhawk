<?php

namespace Formhawk\Infrastructure;

final class Privacy {
	public function register() {
		add_action( 'admin_init', array( $this, 'policy_content' ) );
	}

	public function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . esc_html__( 'Formhawk stores local aggregate statistics about form usage, including form/page identifiers, field names or labels, views, starts, submissions, abandonment, validation errors, failure counts and timing aggregates. It does not intentionally store visitor IP addresses, cookies, visitor identifiers, form field values, email recipients, email subjects or email message bodies. No Formhawk analytics data is sent to an external Formhawk service.', 'formhawk' ) . '</p>';
		wp_add_privacy_policy_content( 'Formhawk', wp_kses_post( wpautop( $content ) ) );
	}
}
