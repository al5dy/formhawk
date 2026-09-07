<?php

namespace Formhawk\Http;

use Formhawk\Analytics\EventNormalizer;
use Formhawk\Analytics\IngestionLimits;
use Formhawk\Domain\ProviderCatalog;
use Formhawk\Support\Sanitizer;

final class ClientEventSchema {
	public static function event() {
		$field = array(
			'type'                 => 'object',
			'required'             => array( 'key' ),
			'additionalProperties' => false,
			'properties'           => array(
				'key'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'label' => array(
					'type'      => 'string',
					'maxLength' => 191,
				),
				'type'  => array(
					'type'      => 'string',
					'maxLength' => 32,
				),
			),
		);
		return array(
			'type'                 => 'object',
			'required'             => array( 'type', 'provider', 'provider_form_id', 'page_path' ),
			'additionalProperties' => false,
			'properties'           => array(
				'schema_version'   => array(
					'type' => 'integer',
					'enum' => array( 1, 2 ),
				),
				'type'             => array(
					'type' => 'string',
					'enum' => EventNormalizer::CLIENT_EVENTS,
				),
				'provider'         => array(
					'type' => 'string',
					'enum' => ProviderCatalog::ids(),
				),
				'provider_form_id' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'page_path'        => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 500,
					'pattern'   => '^/(?!/)[^?#]*$',
				),
				'title'            => array(
					'type'      => 'string',
					'maxLength' => 255,
				),
				'duration_ms'      => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 3600000,
				),
				'field'            => $field,
				'fields'           => array(
					'type'     => 'array',
					'maxItems' => IngestionLimits::all()['fields_per_event'],
					'items'    => $field,
				),
			),
		);
	}

	public static function payload() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'token', 'events' ),
			'additionalProperties' => false,
			'properties'           => array(
				'token'  => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
				'events' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => IngestionLimits::all()['batch_size'],
					'items'    => self::event(),
				),
			),
		);
	}

	public static function valid_event( $event ) {
		if ( ! self::strict_types( $event, self::event() ) || is_wp_error( rest_validate_value_from_schema( $event, self::event() ) ) ) {
			return false;
		}
		$type = $event['type'];
		if ( '' === Sanitizer::identifier( $event['provider_form_id'], '' ) ) {
			return false;
		}
		$fields = $event['fields'] ?? array();
		if ( isset( $event['field'] ) ) {
			$fields[] = $event['field'];
		}
		foreach ( $fields as $field ) {
			if ( '' === Sanitizer::identifier( $field['key'], '' ) ) {
				return false;
			}
		}
		if ( in_array( $type, array( 'field_interaction', 'validation_error' ), true ) && ! isset( $event['field'] ) ) {
			return false;
		}
		if ( isset( $event['field'] ) && ! in_array( $type, array( 'field_interaction', 'validation_error', 'form_abandon' ), true ) ) {
			return false;
		}
		return ! isset( $event['fields'] ) || in_array( $type, array( 'validation_failure', 'client_validation_failure' ), true );
	}

	public static function wire_types( $value, array $schema ) {
		if ( 'object' === $schema['type'] ) {
			if ( ! is_object( $value ) ) {
				return false;
			}
			foreach ( get_object_vars( $value ) as $key => $item ) {
				if ( ! isset( $schema['properties'][ $key ] ) || ! self::wire_types( $item, $schema['properties'][ $key ] ) ) {
					return false;
				}
			}
		} elseif ( 'array' === $schema['type'] ) {
			if ( ! is_array( $value ) || count( $value ) > $schema['maxItems'] ) {
				return false;
			}
			foreach ( $value as $item ) {
				if ( ! self::wire_types( $item, $schema['items'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** WordPress schemas accept some coercible values; ingestion deliberately does not. */
	public static function strict_types( $value, array $schema ) {
		switch ( $schema['type'] ) {
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value );
			case 'array':
				if ( ! is_array( $value ) || ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) || count( $value ) > $schema['maxItems'] ) {
					return false;
				}
				foreach ( $value as $item ) {
					if ( ! self::strict_types( $item, $schema['items'] ) ) {
						return false;
					}
				}
				return true;
			case 'object':
				if ( ! is_array( $value ) || array_diff_key( $value, $schema['properties'] ) ) {
					return false;
				}
				foreach ( $value as $key => $item ) {
					if ( ! self::strict_types( $item, $schema['properties'][ $key ] ) ) {
						return false;
					}
				}
				return true;
		}
		return false;
	}
}
