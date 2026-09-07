<?php

namespace Formhawk\CRO\Attribution;

final class RequestContext {
	const FIELD_NAME = '_formhawk_cro';
	private $signer;
	private $context;

	public function __construct( ContextSigner $signer = null ) {
		$this->signer = $signer ? $signer : new ContextSigner();
	}

	public function register() {
		// This precedes provider request processing. The marker is captured and
		// removed before providers build entry, CRM or mail field collections.
		add_action( 'init', array( $this, 'capture' ), -9999 );
	}

	public function capture() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Signed technical attribution marker, never visitor content.
		$token = isset( $_POST[ self::FIELD_NAME ] ) ? wp_unslash( $_POST[ self::FIELD_NAME ] ) : '';
		if ( is_scalar( $token ) ) {
			$this->context = $this->signer->verify( (string) $token );
		}
		unset( $_POST[ self::FIELD_NAME ], $_REQUEST[ self::FIELD_NAME ] );
	}

	public function get( $provider = '', $provider_form_id = '' ) {
		if ( ! is_array( $this->context ) ) {
			return null;
		}
		if ( '' !== $provider && $provider !== $this->context['provider'] ) {
			return null;
		}
		if ( '' !== $provider_form_id && (string) $provider_form_id !== (string) $this->context['provider_form_id'] ) {
			return null;
		}
		return $this->context;
	}

	/** Test seam for provider lifecycle tests; production input still requires HMAC. */
	public function set_verified_context( array $context ) {
		$this->context = $context;
	}
}
