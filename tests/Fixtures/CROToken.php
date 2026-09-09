<?php

namespace Formhawk\Tests\Fixtures;

/** Test-only signing of deliberately invalid claims, without weakening production verification. */
final class CROToken {
	public static function claims( $token ) {
		$body = explode( '.', $token )[0];
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode synthetic test claims, never executable content.
		return json_decode( base64_decode( strtr( $body, '-_', '+/' ) ), true );
	}

	public static function sign( array $claims ) {
		$body = self::encode( wp_json_encode( $claims ) );
		$key  = hash_hmac( 'sha256', 'formhawk-cro-context-v2|' . home_url( '/' ), wp_salt( 'auth' ), true );
		return $body . '.' . self::encode( hash_hmac( 'sha256', $body, $key, true ) );
	}

	private static function encode( $text ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required URL-safe encoding of synthetic HMAC claims.
		return rtrim( strtr( base64_encode( $text ), '+/', '-_' ), '=' );
	}
}
