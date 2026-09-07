<?php

namespace Formhawk\Support;

use Formhawk\Domain\ProviderCatalog;

final class Sanitizer {
	public static function provider( $value ) {
		$value = strtolower( sanitize_key( self::string_value( $value ) ) );
		return ProviderCatalog::is_known( $value ) ? $value : ProviderCatalog::GENERIC;
	}

	public static function identifier( $value, $fallback = 'anonymous' ) {
		$value = sanitize_text_field( self::string_value( $value ) );
		$value = preg_replace( '/[^A-Za-z0-9_:\-.]/', '-', $value );
		$value = trim( (string) $value, '-_' );
		return substr( '' !== $value ? $value : $fallback, 0, 191 );
	}

	public static function title( $value ) {
		$value = self::redact_personal_data( trim( wp_strip_all_tags( self::string_value( $value ), true ) ) );
		return substr( $value, 0, 255 );
	}

	public static function field_label( $value ) {
		$value = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::string_value( $value ), true ) ) );
		$value = self::redact_personal_data( $value );
		return substr( $value, 0, 191 );
	}

	public static function field_type( $value ) {
		return substr( sanitize_key( self::string_value( $value ) ), 0, 32 );
	}

	public static function path( $value ) {
		$value = self::string_value( $value );
		$path  = wp_parse_url( $value, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$path = preg_replace( '#/+#', '/', $path );
		return substr( $path, 0, 500 );
	}

	public static function duration_ms( $value ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return 0;
		}

		$value = absint( $value );
		return min( $value, HOUR_IN_SECONDS * 1000 );
	}

	private static function redact_personal_data( $value ) {
		$value = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted]', (string) $value );
		$value = preg_replace( '/(?<![A-Za-z0-9])\+?\d(?:[\s().-]*\d){7,}(?![A-Za-z0-9])/', '[redacted]', (string) $value );

		return (string) $value;
	}

	/**
	 * Converts only scalar input to text.
	 *
	 * REST and provider metadata are untrusted. Ignoring arrays and objects avoids
	 * PHP warnings and prevents serialized payloads from becoming dimensions.
	 *
	 * @param mixed $value Candidate value.
	 * @return string
	 */
	private static function string_value( $value ) {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
