<?php

namespace Formhawk\CRO\Attribution;

use Formhawk\Domain\ProviderCatalog;
use Formhawk\Support\Sanitizer;

final class ContextSigner {
	const VERSION = 1;

	public function sign( array $context, $ttl = 7200 ) {
		$payload = array(
			'v'   => self::VERSION,
			'e'   => absint( $context['experiment_id'] ),
			'r'   => absint( $context['variant_id'] ),
			'f'   => absint( $context['form_id'] ),
			'p'   => ProviderCatalog::is_known( $context['provider'] ) ? $context['provider'] : ProviderCatalog::GENERIC,
			'pid' => Sanitizer::identifier( $context['provider_form_id'] ),
			's'   => in_array( $context['segment'], array( 'desktop', 'mobile' ), true ) ? $context['segment'] : 'desktop',
			'exp' => time() + max( 300, min( DAY_IN_SECONDS, absint( $ttl ) ) ),
		);
		$json    = wp_json_encode( $payload );
		$body    = $this->base64url_encode( $json );
		$mac     = hash_hmac( 'sha256', $body, $this->key(), true );
		return $body . '.' . $this->base64url_encode( $mac );
	}

	public function verify( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) > 1000 || ! preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token ) ) {
			return null;
		}
		$parts = explode( '.', $token, 2 );
		$mac   = $this->base64url_decode( $parts[1] );
		if ( false === $mac || ! hash_equals( hash_hmac( 'sha256', $parts[0], $this->key(), true ), $mac ) ) {
			return null;
		}
		$json = $this->base64url_decode( $parts[0] );
		$data = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || self::VERSION !== (int) ( $data['v'] ?? 0 ) || time() > (int) ( $data['exp'] ?? 0 ) ) {
			return null;
		}
		$required = array( 'v', 'e', 'r', 'f', 'p', 'pid', 's', 'exp' );
		if ( count( $data ) !== count( $required ) || array_diff( $required, array_keys( $data ) ) || ! is_int( $data['e'] ) || ! is_int( $data['r'] ) || ! is_int( $data['f'] ) || $data['e'] < 1 || $data['r'] < 1 || $data['f'] < 1 || ! is_string( $data['p'] ) || ! ProviderCatalog::is_known( $data['p'] ) || ! is_string( $data['pid'] ) || Sanitizer::identifier( $data['pid'] ) !== $data['pid'] || ! in_array( $data['s'], array( 'desktop', 'mobile' ), true ) ) {
			return null;
		}
		return array(
			'experiment_id'    => absint( $data['e'] ),
			'variant_id'       => absint( $data['r'] ),
			'form_id'          => absint( $data['f'] ),
			'provider'         => $data['p'],
			'provider_form_id' => $data['pid'],
			'segment'          => $data['s'],
		);
	}

	private function key() {
		return hash_hmac( 'sha256', 'formhawk-cro-context-v1|' . home_url( '/' ), wp_salt( 'auth' ), true );
	}

	private function base64url_encode( $value ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe encoding of authenticated structural context, not obfuscation.
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private function base64url_decode( $value ) {
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding authenticated structural context, not executable content.
		return base64_decode( strtr( $value, '-_', '+/' ), true );
	}
}
