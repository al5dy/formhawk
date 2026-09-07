<?php

namespace Formhawk\Analytics;

final class IngestionLimits {
	const DEFAULTS = array(
		'body_bytes'                  => 65536,
		'event_bytes'                 => 16384,
		'batch_size'                  => 20,
		'fields_per_event'            => 50,
		'dimension_window_seconds'    => 86400,
		'requests_per_minute'         => 600,
		'events_per_minute'           => 3000,
		'cost_per_minute'             => 12000,
		'forms_total'                 => 5000,
		'placements_total'            => 20000,
		'paths_total'                 => 10000,
		'fields_total'                => 50000,
		'forms_per_day'               => 100,
		'placements_per_day'          => 500,
		'paths_per_day'               => 500,
		'fields_per_day'              => 1000,
		'placements_per_form'         => 100,
		'fields_per_form'             => 100,
		'dimensions_per_form_per_day' => 100,
	);

	public static function all() {
		$defaults                        = self::DEFAULTS;
		$defaults['requests_per_minute'] = apply_filters( 'formhawk_event_request_limit', $defaults['requests_per_minute'] );
		$configured                      = apply_filters( 'formhawk_ingestion_limits', $defaults );
		$limits                          = self::DEFAULTS;
		foreach ( $limits as $key => $default ) {
			$value          = is_array( $configured ) && isset( $configured[ $key ] ) ? $configured[ $key ] : $default;
			$ceiling        = in_array( $key, array( 'body_bytes', 'event_bytes', 'batch_size', 'fields_per_event' ), true ) ? $default : 1000000;
			$limits[ $key ] = is_numeric( $value ) && $value >= 1 ? min( $ceiling, (int) $value ) : $default;
		}
		return $limits;
	}

	public static function cost( array $events ) {
		$cost = 0;
		foreach ( $events as $event ) {
			// Identity resolution, two aggregate writes, and each potential field-row write.
			$cost += 4 + ( isset( $event['field'] ) ? 1 : 0 ) + ( isset( $event['fields'] ) ? count( $event['fields'] ) : 0 );
		}
		return $cost;
	}
}
