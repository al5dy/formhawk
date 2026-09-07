<?php

namespace Formhawk\Outcomes;

/** Captures and removes the opaque technical marker before providers build entries or mail. */
final class SubmissionContext {
	const FIELD_NAME   = '_formhawk_submission';
	private $public_id = '';

	public function register() {
		add_action( 'init', array( $this, 'capture' ), -10000 );
	}

	public function capture() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Opaque technical linkage only; validated below and removed before provider processing.
		$value = isset( $_POST[ self::FIELD_NAME ] ) ? wp_unslash( $_POST[ self::FIELD_NAME ] ) : '';
		if ( is_scalar( $value ) && preg_match( '/^fh_[A-Za-z0-9_-]{22,43}$/', (string) $value ) ) {
			$this->public_id = (string) $value;
		}
		unset( $_POST[ self::FIELD_NAME ], $_REQUEST[ self::FIELD_NAME ] );
	}

	public function get() {
		return $this->public_id; }
	public function set_verified( $public_id ) {
		$this->public_id = preg_match( '/^fh_[A-Za-z0-9_-]{22,43}$/', (string) $public_id ) ? (string) $public_id : ''; }
}
