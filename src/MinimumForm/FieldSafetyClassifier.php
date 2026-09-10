<?php

namespace Formhawk\MinimumForm;

/** Structural, value-blind safety classification for semantic field changes. */
final class FieldSafetyClassifier {
	public function classify( array $field ) {
		$key      = strtolower( (string) ( $field['normalized_key'] ?? $field['key'] ?? '' ) );
		$type     = strtolower( (string) ( $field['field_type'] ?? $field['type'] ?? '' ) );
		$label    = strtolower( (string) ( $field['label'] ?? '' ) );
		$haystack = $key . ' ' . $type . ' ' . $label;

		$forbidden_types = array( 'password', 'file', 'signature', 'captcha', 'recaptcha', 'recaptcha_v3', 'hcaptcha', 'turnstile', 'acceptance' );
		$forbidden       = '/(?:pass(?:word)?|card(?:[_ -]?number)?|credit|debit|cvv|cvc|expir(?:y|ation)|bank|iban|swift|otp|2fa|captcha|recaptcha|hcaptcha|turnstile|nonce|csrf|honeypot|security[_ -]?token|signature|terms|privacy|gdpr|consent|medical|health|diagnos|ssn|social[_ -]?security)/i';
		if ( in_array( $type, $forbidden_types, true ) || preg_match( $forbidden, $haystack ) ) {
			return $this->result( FieldSafety::FORBIDDEN, 'sensitive_security_or_legal_field' );
		}
		if ( ! empty( $field['required'] ) || ! empty( $field['provider_required'] ) ) {
			return $this->result( FieldSafety::PROTECTED, 'required_semantics' );
		}
		if ( ! empty( $field['dependency_unknown'] ) ) {
			return $this->result( FieldSafety::PROTECTED, 'dependency_unknown' );
		}
		if ( ! empty( $field['depends_on'] ) || ! empty( $field['controls_visibility_of'] ) || ! empty( $field['dependencies'] ) ) {
			return $this->result( FieldSafety::PROTECTED, 'field_dependency' );
		}
		if ( ! empty( $field['hidden_technical'] ) || ! empty( $field['integration_mapping'] ) || ! empty( $field['email_template_dependency'] ) || ! empty( $field['calculation_dependency'] ) || ! empty( $field['multi_step_dependency'] ) ) {
			return $this->result( FieldSafety::PROTECTED, 'provider_configuration_dependency' );
		}

		$caution_types = array( 'tel', 'phone', 'number', 'date', 'datetime', 'select', 'address', 'url' );
		$caution       = '/(?:phone|mobile|budget|company|organisation|organization|address|date|country|state|province|city|postal|zip|job[_ -]?title|role|qualification)/i';
		$safety        = in_array( $type, $caution_types, true ) || preg_match( $caution, $haystack ) ? FieldSafety::CAUTION : FieldSafety::SAFE;
		$reason        = FieldSafety::CAUTION === $safety ? 'business_or_contact_field' : 'optional_independent_field';

		/**
		 * Filters a structural field safety result. FORBIDDEN remains an absolute
		 * floor and cannot be weakened by an extension.
		 *
		 * @param array $result Safety and reason.
		 * @param array $field  Value-free structural field metadata.
		 */
		$filtered = apply_filters( 'formhawk_minimum_form_field_safety', $this->result( $safety, $reason ), $field );
		if ( ! is_array( $filtered ) || ! isset( $filtered['safety'] ) || ! in_array( $filtered['safety'], FieldSafety::all(), true ) ) {
			return $this->result( FieldSafety::PROTECTED, 'invalid_safety_filter' );
		}
		return $filtered;
	}

	private function result( $safety, $reason ) {
		return array(
			'safety' => $safety,
			'reason' => $reason,
		);
	}
}
