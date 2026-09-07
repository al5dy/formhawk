<?php

namespace Formhawk\Integrations;

use Formhawk\Contracts\EventRecorderInterface;
use Formhawk\Contracts\FormIntegrationInterface;
use Formhawk\Domain\ProviderCatalog;
use Formhawk\Support\Sanitizer;

final class WPForms implements FormIntegrationInterface {
	private $events;
	private $completed           = array();
	private $validation_recorded = array();

	public function __construct( EventRecorderInterface $events ) {
		$this->events = $events;
	}

	public function id() {
		return ProviderCatalog::WPFORMS;
	}

	public function label() {
		return __( 'WPForms', 'formhawk' );
	}

	public function is_available() {
		return defined( 'WPFORMS_VERSION' ) || function_exists( 'wpforms' );
	}

	public function capabilities() {
		return array(
			ProviderCatalog::CAP_FRONTEND_TRACKING,
			ProviderCatalog::CAP_SERVER_SUCCESS,
			ProviderCatalog::CAP_SERVER_VALIDATION,
			ProviderCatalog::CAP_MULTI_STEP,
			ProviderCatalog::CAP_STABLE_FIELD_IDS,
			ProviderCatalog::CAP_DYNAMIC_RENDERING,
			ProviderCatalog::CAP_SUBMISSION_ATTRIBUTION,
			ProviderCatalog::CAP_FIELD_ROI,
		);
	}

	public function register() {
		// This hook fires only after successful processing; Lite legitimately supplies entry_id=0.
		add_action( 'wpforms_process_complete', array( $this, 'process_complete' ), PHP_INT_MAX, 4 );
		add_filter( 'wpforms_process_initial_errors', array( $this, 'initial_errors' ), PHP_INT_MAX, 2 );
		add_action( 'wpforms_process_after', array( $this, 'process_after' ), PHP_INT_MAX, 3 );
	}

	public function process_complete( $fields, $entry, $form_data, $entry_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$metadata = $this->metadata( $form_data );
		if ( ! $metadata || ! empty( $this->completed[ $metadata['id'] ] ) ) {
			return;
		}

		// Neither submitted values nor entry_id are needed to establish provider-confirmed success.
		$this->completed[ $metadata['id'] ] = true;
		$this->events->record_success(
			$this->id(),
			$metadata['id'],
			$metadata['title'],
			$this->submission_path(),
			array(
				'provider_entry_id' => is_scalar( $entry_id ) && absint( $entry_id ) ? (string) absint( $entry_id ) : '',
				'fields'            => $this->structural_fields( $form_data ),
			)
		);
	}

	public function initial_errors( $errors, $form_data ) {
		if ( is_array( $errors ) ) {
			$this->record_validation_errors( $errors, $form_data );
		}

		// Formhawk is an observer and must never alter WPForms validation behavior.
		return $errors;
	}

	public function process_after( $fields, $entry_id, $form_data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$metadata = $this->metadata( $form_data );
		if ( ! $metadata || ! empty( $this->completed[ $metadata['id'] ] ) || ! empty( $this->validation_recorded[ $metadata['id'] ] ) ) {
			return;
		}

		$errors = $this->current_process_errors();
		if ( ! empty( $errors ) ) {
			$this->record_validation_errors( $errors, $form_data );
		}
	}

	private function record_validation_errors( array $errors, $form_data ) {
		$metadata = $this->metadata( $form_data );
		if ( ! $metadata || ! empty( $this->validation_recorded[ $metadata['id'] ] ) ) {
			return;
		}

		$form_errors = isset( $errors[ $metadata['numeric_id'] ] ) && is_array( $errors[ $metadata['numeric_id'] ] )
			? $errors[ $metadata['numeric_id'] ]
			: array();
		// WPForms 2.0.1.1 filters its form-ID-indexed errors even on a valid submission.
		// An empty collection, or another form's errors, is not rejection evidence.
		if ( empty( $form_errors ) ) {
			return;
		}
		$fields = $this->field_metadata( $form_errors, $form_data );

		$this->validation_recorded[ $metadata['id'] ] = true;
		$this->events->record_validation_failure( $this->id(), $metadata['id'], $metadata['title'], $this->submission_path(), $fields );
	}

	private function field_metadata( array $errors, $form_data ) {
		$fields      = array();
		$definitions = is_array( $form_data ) && isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ? $form_data['fields'] : array();
		$general     = array( 'header', 'footer', 'header_styled', 'footer_styled', 'recaptcha' );

		foreach ( $errors as $field_id => $field_errors ) {
			if ( in_array( (string) $field_id, $general, true ) || ! isset( $definitions[ $field_id ] ) ) {
				continue;
			}

			$definition = is_array( $definitions[ $field_id ] ) ? $definitions[ $field_id ] : array();
			$base_key   = Sanitizer::identifier( $field_id, 'unknown' );
			$label      = isset( $definition['label'] ) ? Sanitizer::field_label( $definition['label'] ) : $base_key;
			$type       = isset( $definition['type'] ) ? Sanitizer::field_type( $definition['type'] ) : '';

			if ( is_array( $field_errors ) ) {
				$subfields = array_filter(
					array_keys( $field_errors ),
					static function ( $subfield ) {
						return is_string( $subfield ) && '' !== $subfield;
					}
				);
				foreach ( $subfields as $subfield ) {
					$fields[] = array(
						'key'   => $base_key . '.' . Sanitizer::identifier( $subfield, 'part' ),
						'label' => $label,
						'type'  => $type,
					);
				}
				if ( ! empty( $subfields ) ) {
					continue;
				}
			}

			$fields[] = array(
				'key'   => $base_key,
				'label' => $label,
				'type'  => $type,
			);
		}

		return $fields;
	}

	private function structural_fields( $form_data ) {
		$fields      = array();
		$definitions = is_array( $form_data ) && isset( $form_data['fields'] ) && is_array( $form_data['fields'] ) ? $form_data['fields'] : array();
		foreach ( array_slice( $definitions, 0, 50, true ) as $field_id => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue; }
			$type = Sanitizer::field_type( isset( $definition['type'] ) ? $definition['type'] : '' );
			if ( in_array( $type, array( 'html', 'divider', 'pagebreak', 'captcha', 'hidden', 'entry-preview' ), true ) ) {
				continue; }
			$key = Sanitizer::identifier( isset( $definition['id'] ) ? $definition['id'] : $field_id, '' );
			if ( '' === $key ) {
				continue; }
			$fields[] = array(
				'key'      => $key,
				'label'    => Sanitizer::field_label( isset( $definition['label'] ) ? $definition['label'] : $key ),
				'type'     => $type,
				'required' => ! empty( $definition['required'] ),
				'position' => count( $fields ),
			);
		}
		return $fields;
	}

	/**
	 * WPForms exposes no error argument on wpforms_process_after. Keep its
	 * compatibility access here so an upstream property change fails closed.
	 */
	private function current_process_errors() {
		if ( ! function_exists( 'wpforms' ) ) {
			return array();
		}

		$application = wpforms();
		if ( ! is_object( $application ) || ! is_callable( array( $application, 'obj' ) ) ) {
			return array();
		}

		$process = $application->obj( 'process' );
		if ( ! is_object( $process ) ) {
			return array();
		}

		if ( is_callable( array( $process, 'get_errors' ) ) ) {
			$errors = $process->get_errors();
			return is_array( $errors ) ? $errors : array();
		}

		$public = get_object_vars( $process );
		return isset( $public['errors'] ) && is_array( $public['errors'] ) ? $public['errors'] : array();
	}

	private function metadata( $form_data ) {
		if ( ! is_array( $form_data ) || ! isset( $form_data['id'] ) || ! is_scalar( $form_data['id'] ) ) {
			return null;
		}

		$form_id = absint( $form_data['id'] );
		if ( 0 === $form_id ) {
			return null;
		}
		$title = isset( $form_data['settings'] )
			&& is_array( $form_data['settings'] )
			&& isset( $form_data['settings']['form_title'] )
			? Sanitizer::title( $form_data['settings']['form_title'] )
			: '';

		return array(
			'id'         => (string) $form_id,
			'numeric_id' => $form_id,
			'title'      => $title,
		);
	}

	private function submission_path() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is type-checked and sanitized on the next line.
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '/';
		$referer = is_scalar( $referer ) ? esc_url_raw( (string) $referer ) : '/';
		return Sanitizer::path( $referer );
	}
}
