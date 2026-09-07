<?php

namespace Formhawk\Analytics;

use Formhawk\Domain\ProviderCatalog;
use Formhawk\Support\Sanitizer;

final class EventNormalizer {
	const CLIENT_EVENTS = array(
		'form_view',
		'form_start',
		'field_interaction',
		'validation_error',
		'validation_failure',
		'client_validation_failure',
		'form_abandon',
		'form_submit',
	);

	public function client( array $event ) {
		$type = isset( $event['type'] ) && is_scalar( $event['type'] ) ? sanitize_key( (string) $event['type'] ) : '';
		if ( ! in_array( $type, self::CLIENT_EVENTS, true ) ) {
			return null;
		}

		$provider = isset( $event['provider'] ) && is_scalar( $event['provider'] )
			? sanitize_key( (string) $event['provider'] )
			: '';
		if ( ! ProviderCatalog::is_known( $provider ) ) {
			return null;
		}

		$provider_form_id = Sanitizer::identifier(
			isset( $event['provider_form_id'] ) ? $event['provider_form_id'] : '',
			''
		);
		if ( '' === $provider_form_id ) {
			return null;
		}

		$event['provider']         = $provider;
		$event['provider_form_id'] = $provider_form_id;

		$normalized             = $this->common( $event );
		$normalized['type']     = 'validation_failure' === $type ? 'client_validation_failure' : $type;
		$normalized['source']   = 'browser';
		$normalized['evidence'] = 'observed';

		if ( isset( $event['duration_ms'] ) ) {
			$normalized['duration_ms'] = Sanitizer::duration_ms( $event['duration_ms'] );
		}

		if ( isset( $event['field'] ) && is_array( $event['field'] ) ) {
			$normalized['field'] = $this->field( $event['field'] );
		}

		if ( isset( $event['fields'] ) && is_array( $event['fields'] ) ) {
			$normalized['fields'] = $this->fields( $event['fields'] );
		}

		return $normalized;
	}

	public function server( $type, array $event ) {
		$normalized             = $this->common( $event );
		$normalized['type']     = sanitize_key( $type );
		$normalized['source']   = 'provider';
		$normalized['evidence'] = 'provider_confirmed';

		if ( isset( $event['failure_code'] ) ) {
			$normalized['failure_code'] = Sanitizer::identifier( $event['failure_code'], 'form_failure' );
		}

		if ( isset( $event['fields'] ) && is_array( $event['fields'] ) ) {
			$normalized['fields'] = $this->fields( $event['fields'] );
		}

		if ( ! empty( $event['mail_success'] ) ) {
			$normalized['mail_success'] = true;
		}

		return $normalized;
	}

	private function common( array $event ) {
		return array(
			'provider'         => Sanitizer::provider( isset( $event['provider'] ) ? $event['provider'] : '' ),
			'provider_form_id' => Sanitizer::identifier( isset( $event['provider_form_id'] ) ? $event['provider_form_id'] : '' ),
			'title'            => Sanitizer::title( isset( $event['title'] ) ? $event['title'] : '' ),
			'page_path'        => Sanitizer::path( isset( $event['page_path'] ) ? $event['page_path'] : '/' ),
		);
	}

	private function fields( array $fields ) {
		$normalized = array();
		foreach ( array_slice( $fields, 0, 50 ) as $field ) {
			if ( is_array( $field ) ) {
				$item                       = $this->field( $field );
				$normalized[ $item['key'] ] = $item;
			}
		}

		return array_values( $normalized );
	}

	private function field( array $field ) {
		$key = Sanitizer::identifier( isset( $field['key'] ) ? $field['key'] : '', 'unknown' );

		return array(
			'key'   => $key,
			'label' => Sanitizer::field_label( isset( $field['label'] ) ? $field['label'] : $key ),
			'type'  => Sanitizer::field_type( isset( $field['type'] ) ? $field['type'] : '' ),
		);
	}
}
