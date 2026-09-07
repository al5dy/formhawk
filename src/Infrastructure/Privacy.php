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

		$content  = '<p>' . esc_html__( 'Formhawk stores local aggregate statistics about form usage, including form/page identifiers, structural field names or labels, views, starts, submissions, abandonment, validation errors, failure counts and timing aggregates. When Field ROI is enabled, Formhawk also stores a random opaque ID for each provider-confirmed submission, structural field-presence records, business outcome status, integer minor-unit value, currency, and hashed external references. The opaque ID contains no email, phone, user ID, IP address or browser fingerprint and is deleted after the configured attribution window; aggregate ROI history may remain for reporting.', 'formhawk' ) . '</p>';
		$content .= '<p>' . esc_html__( 'Formhawk does not intentionally store visitor IP addresses, analytics cookies, persistent visitor or session identifiers, form field values, names, email addresses, phone numbers, messages, raw CRM payloads, email recipients, email subjects or email message bodies. No Formhawk analytics data is sent to an external Formhawk service by the free plugin.', 'formhawk' ) . '</p>';
		wp_add_privacy_policy_content( 'Formhawk', wp_kses_post( wpautop( $content ) ) );
	}
}
