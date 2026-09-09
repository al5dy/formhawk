<?php

namespace Formhawk\CRO\Http;

/** Browser defense in depth, never authentication or a substitute for issued state. */
final class RequestOrigin {
	public static function allows( \WP_REST_Request $request ) {
		if ( 'cross-site' === strtolower( trim( (string) $request->get_header( 'sec-fetch-site' ) ) ) ) {
			return false;
		}
		$source = $request->get_header( 'origin' );
		if ( ! $source ) {
			$source = $request->get_header( 'referer' );
		}
		// Privacy tools can omit these optional headers; replay admission remains authoritative.
		return ! $source || self::origin( home_url( '/' ) ) === self::origin( $source );
	}

	private static function origin( $url ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) || ! $host ) {
			return '';
		}
		return $scheme . '://' . $host . ':' . ( $port ? absint( $port ) : ( 'https' === $scheme ? 443 : 80 ) );
	}
}
