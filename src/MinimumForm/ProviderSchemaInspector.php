<?php

namespace Formhawk\MinimumForm;

use Formhawk\Infrastructure\Database;
use Formhawk\Support\Sanitizer;

/** Reads only provider configuration and stored structural metadata, never submitted values. */
final class ProviderSchemaInspector {
	public function inspect( array $form ) {
		$provider = isset( $form['provider'] ) ? sanitize_key( $form['provider'] ) : '';
		$schema   = null;
		if ( 'cf7' === $provider ) {
			$schema = $this->contact_form_7( $form );
		} elseif ( 'wpforms' === $provider ) {
			$schema = $this->wpforms( $form );
		}
		if ( ! $schema ) {
			$schema = $this->observed( $form );
		}
		if ( ! $schema || empty( $schema['fields'] ) ) {
			return null;
		}

		/**
		 * Allows provider add-ons to contribute value-free dependency metadata.
		 * An invalid projection fails closed in the caller.
		 *
		 * @param array $schema Structural schema.
		 * @param array $form   Form definition metadata.
		 */
		$filtered = apply_filters( 'formhawk_minimum_form_provider_schema', $schema, $form );
		if ( ! is_array( $filtered ) || empty( $filtered['fields'] ) || ! is_array( $filtered['fields'] ) ) {
			return null;
		}
		$filtered['fields']                 = array_values( $filtered['fields'] );
		$filtered['dependency_graph']       = new FieldDependencyGraph( $filtered['fields'] );
		$filtered['dependency_hash']        = $filtered['dependency_graph']->hash();
		$filtered['schema_fingerprint']     = $this->fingerprint( $filtered['fields'] );
		$filtered['provider_schema_source'] = isset( $filtered['provider_schema_source'] ) ? sanitize_key( $filtered['provider_schema_source'] ) : 'unknown';
		return $filtered;
	}

	private function contact_form_7( array $form ) {
		if ( ! class_exists( 'WPCF7_ContactForm' ) || ! is_callable( array( 'WPCF7_ContactForm', 'get_instance' ) ) ) {
			return null;
		}
		$contact_form = \WPCF7_ContactForm::get_instance( absint( $form['provider_form_id'] ) );
		if ( ! $contact_form || ! is_callable( array( $contact_form, 'scan_form_tags' ) ) || ! is_callable( array( $contact_form, 'prop' ) ) ) {
			return null;
		}
		$template            = (string) $contact_form->prop( 'form' );
		$conditional_unknown = (bool) preg_match( '/\[(?:group|conditional|show|hide)\b/i', $template );
		$mail_text           = '';
		foreach ( array( 'mail', 'mail_2' ) as $property ) {
			$mail = $contact_form->prop( $property );
			if ( is_array( $mail ) ) {
				$mail_text .= ' ' . wp_json_encode( $mail );
			}
		}
		$core_types = array( 'text', 'email', 'url', 'tel', 'number', 'date', 'textarea', 'select', 'checkbox', 'radio', 'acceptance', 'file', 'hidden' );
		$fields     = array();
		foreach ( array_slice( (array) $contact_form->scan_form_tags(), 0, 100 ) as $position => $tag ) {
			if ( ! is_object( $tag ) ) {
				continue;
			}
			$public = get_object_vars( $tag );
			$key    = Sanitizer::identifier( isset( $public['name'] ) ? $public['name'] : '', '' );
			$type   = Sanitizer::field_type( isset( $public['basetype'] ) ? $public['basetype'] : '' );
			if ( '' === $key || 'submit' === $type ) {
				continue;
			}
			$fields[] = array(
				'key'                       => $key,
				'normalized_key'            => $key,
				'label'                     => $key,
				'type'                      => $type,
				'field_type'                => $type,
				'required'                  => is_callable( array( $tag, 'is_required' ) ) && $tag->is_required(),
				'provider_required'         => is_callable( array( $tag, 'is_required' ) ) && $tag->is_required(),
				'position'                  => absint( $position ),
				'dependency_unknown'        => $conditional_unknown || ! in_array( $type, $core_types, true ),
				'email_template_dependency' => (bool) preg_match( '/\[' . preg_quote( $key, '/' ) . '\b/', $mail_text ),
				'hidden_technical'          => 'hidden' === $type,
			);
		}
		return array(
			'fields'                 => $fields,
			'provider_schema_source' => 'provider_definition',
		);
	}

	private function wpforms( array $form ) {
		if ( ! function_exists( 'wpforms' ) ) {
			return null;
		}
		$application = wpforms();
		$repository  = is_object( $application ) && is_callable( array( $application, 'obj' ) ) ? $application->obj( 'form' ) : null;
		if ( ! $repository || ! is_callable( array( $repository, 'get' ) ) ) {
			return null;
		}
		$data = $repository->get(
			absint( $form['provider_form_id'] ),
			array(
				'content_only' => true,
				'cap'          => false,
			)
		);
		if ( ! is_array( $data ) || empty( $data['fields'] ) || ! is_array( $data['fields'] ) ) {
			return null;
		}
		$external_dependencies_unknown = $this->wpforms_external_dependencies_unknown( $data );
		$fields                        = array();
		foreach ( array_slice( $data['fields'], 0, 100, true ) as $field_id => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$key  = Sanitizer::identifier( isset( $definition['id'] ) ? $definition['id'] : $field_id, '' );
			$type = Sanitizer::field_type( isset( $definition['type'] ) ? $definition['type'] : '' );
			if ( '' === $key || in_array( $type, array( 'html', 'divider', 'pagebreak', 'entry-preview' ), true ) ) {
				continue;
			}
			$depends_on          = $this->conditional_field_ids( $definition );
			$has_conditionals    = ! empty( $definition['conditionals'] );
			$conditional_unknown = isset( $definition['conditionals'] ) && ( ! is_array( $definition['conditionals'] ) || ( $has_conditionals && ! $depends_on ) );
			$fields[]            = array(
				'key'                    => $key,
				'normalized_key'         => $key,
				'label'                  => Sanitizer::field_label( isset( $definition['label'] ) ? $definition['label'] : $key ),
				'type'                   => $type,
				'field_type'             => $type,
				'required'               => ! empty( $definition['required'] ),
				'provider_required'      => ! empty( $definition['required'] ),
				'position'               => count( $fields ),
				'depends_on'             => $depends_on,
				'dependency_unknown'     => $external_dependencies_unknown || $conditional_unknown,
				'integration_mapping'    => $this->wpforms_field_is_mapped( $data, $key ),
				'calculation_dependency' => ! empty( $definition['calculation_is_enabled'] ),
				'hidden_technical'       => 'hidden' === $type,
			);
		}
		$controls = array();
		foreach ( $fields as $field ) {
			foreach ( $field['depends_on'] as $dependency ) {
				$controls[ $dependency ][] = $field['key'];
			}
		}
		foreach ( $fields as &$field ) {
			$field['controls_visibility_of'] = isset( $controls[ $field['key'] ] ) ? array_values( array_unique( $controls[ $field['key'] ] ) ) : array();
		}
		unset( $field );
		return array(
			'fields'                 => $fields,
			'provider_schema_source' => 'provider_definition',
		);
	}

	private function conditional_field_ids( array $definition ) {
		$output = array();
		$walk   = function ( $value, $key = '' ) use ( &$walk, &$output ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $child_key => $child ) {
					$walk( $child, (string) $child_key );
				}
				return;
			}
			if ( in_array( $key, array( 'field', 'field_id' ), true ) && is_scalar( $value ) ) {
				$field_key = Sanitizer::identifier( $value, '' );
				if ( '' !== $field_key ) {
					$output[] = $field_key;
				}
			}
		};
		if ( isset( $definition['conditionals'] ) ) {
			$walk( $definition['conditionals'] );
		}
		return array_values( array_unique( $output ) );
	}

	private function wpforms_field_is_mapped( array $data, $key ) {
		$configuration = array_intersect_key( $data, array_flip( array( 'settings', 'providers', 'payments' ) ) );
		$json          = (string) wp_json_encode( $configuration );
		return (bool) preg_match( '/(?:field_id|field)[^0-9]{0,12}["\']?' . preg_quote( $key, '/' ) . '(?:["\']|\b)/i', $json );
	}

	private function wpforms_external_dependencies_unknown( array $data ) {
		foreach ( array( 'providers', 'payments' ) as $section ) {
			if ( ! empty( $data[ $section ] ) ) {
				return true;
			}
		}
		return false;
	}

	private function observed( array $form ) {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT schema_json FROM %i WHERE form_id=%d ORDER BY id DESC LIMIT 1', Database::form_versions_table(), absint( $form['id'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Latest bounded structural snapshot; no field values are stored.
		$json   = $wpdb->get_var( $sql );
		$fields = json_decode( (string) $json, true );
		if ( ! is_array( $fields ) ) {
			return null;
		}
		foreach ( $fields as &$field ) {
			if ( is_array( $field ) ) {
				$field['dependency_unknown'] = true;
			}
		}
		unset( $field );
		return array(
			'fields'                 => $fields,
			'provider_schema_source' => 'observed_submission',
		);
	}

	private function fingerprint( array $fields ) {
		$canonical = array();
		foreach ( $fields as $field ) {
			$depends_on = isset( $field['depends_on'] ) && is_array( $field['depends_on'] ) ? array_map( 'strval', $field['depends_on'] ) : array();
			$controls   = isset( $field['controls_visibility_of'] ) && is_array( $field['controls_visibility_of'] ) ? array_map( 'strval', $field['controls_visibility_of'] ) : array();
			sort( $depends_on, SORT_STRING );
			sort( $controls, SORT_STRING );
			$canonical[] = array(
				'key'                       => (string) ( $field['key'] ?? '' ),
				'label_hash'                => hash( 'sha256', Sanitizer::field_label( $field['label'] ?? '' ) ),
				'type'                      => (string) ( $field['field_type'] ?? $field['type'] ?? '' ),
				'required'                  => ! empty( $field['required'] ),
				'provider_required'         => ! empty( $field['provider_required'] ),
				'position'                  => absint( $field['position'] ?? 0 ),
				'depends_on'                => $depends_on,
				'controls_visibility_of'    => $controls,
				'dependency_unknown'        => ! empty( $field['dependency_unknown'] ),
				'integration_mapping'       => ! empty( $field['integration_mapping'] ),
				'email_template_dependency' => ! empty( $field['email_template_dependency'] ),
				'calculation_dependency'    => ! empty( $field['calculation_dependency'] ),
				'multi_step_dependency'     => ! empty( $field['multi_step_dependency'] ),
				'hidden_technical'          => ! empty( $field['hidden_technical'] ),
			);
		}
		return hash( 'sha256', (string) wp_json_encode( $canonical ) );
	}
}
