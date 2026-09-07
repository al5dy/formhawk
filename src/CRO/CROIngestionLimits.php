<?php

namespace Formhawk\CRO;

final class CROIngestionLimits {
	const DEFAULTS = array(
		'body_bytes'                 => 16384,
		'context_bytes'              => 2048,
		'forms_per_request'          => 20,
		'config_requests_per_minute' => 600,
		'event_requests_per_minute'  => 1200,
	);

	public static function all() {
		/** @param array $limits Site-wide, privacy-safe CRO endpoint limits. */
		$configured = apply_filters( 'formhawk_cro_ingestion_limits', self::DEFAULTS );
		$limits     = self::DEFAULTS;
		foreach ( $limits as $key => $default ) {
			$value          = is_array( $configured ) && isset( $configured[ $key ] ) ? $configured[ $key ] : $default;
			$ceiling        = in_array( $key, array( 'body_bytes', 'context_bytes', 'forms_per_request' ), true ) ? $default : 1000000;
			$limits[ $key ] = is_numeric( $value ) && $value >= 1 ? min( $ceiling, (int) $value ) : $default;
		}
		return $limits;
	}
}
