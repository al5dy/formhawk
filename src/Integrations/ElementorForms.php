<?php

namespace Formhawk\Integrations;

use Formhawk\Contracts\EventRecorderInterface;
use Formhawk\Contracts\FormIntegrationInterface;
use Formhawk\Domain\ProviderCatalog;
use Formhawk\Support\Sanitizer;

final class ElementorForms implements FormIntegrationInterface {
	private $events;
	private $terminal_recorded   = array();
	private $terminal_succeeded  = array();
	private $validation_recorded = array();
	private $mail_observed       = array();

	public function __construct( EventRecorderInterface $events ) {
		$this->events = $events;
	}

	public function id() {
		return ProviderCatalog::ELEMENTOR;
	}

	public function label() {
		return __( 'Elementor', 'formhawk' );
	}

	public function is_available() {
		return defined( 'ELEMENTOR_PRO_VERSION' )
			&& class_exists( 'ElementorPro\Modules\Forms\Classes\Form_Record' );
	}

	public function capabilities() {
		return array(
			ProviderCatalog::CAP_FRONTEND_TRACKING,
			ProviderCatalog::CAP_SERVER_SUCCESS,
			ProviderCatalog::CAP_SERVER_FAILURE,
			ProviderCatalog::CAP_SERVER_VALIDATION,
			ProviderCatalog::CAP_MAIL_SUCCESS,
			ProviderCatalog::CAP_MULTI_STEP,
			ProviderCatalog::CAP_STABLE_FIELD_IDS,
			ProviderCatalog::CAP_DYNAMIC_RENDERING,
		);
	}

	public function register() {
		// The late observer sees Elementor's built-in and extension validation errors without changing them.
		add_action( 'elementor_pro/forms/validation', array( $this, 'validation' ), PHP_INT_MAX, 2 );
		// new_record runs after Actions After Submit and is the provider's accepted-form lifecycle signal.
		add_action( 'elementor_pro/forms/new_record', array( $this, 'new_record' ), PHP_INT_MAX, 2 );
		// Elementor fires this before its Email action has converted wp_mail() failure into a form error.
		add_action( 'elementor_pro/forms/mail_sent', array( $this, 'mail_sent' ), PHP_INT_MAX, 2 );
	}

	public function validation( $record, $ajax_handler ) {
		$metadata = $this->metadata( $record );
		$key      = $this->record_key( $record, $metadata );
		if ( ! $metadata || ! $key || ! empty( $this->validation_recorded[ $key ] ) ) {
			return;
		}

		$errors = $this->handler_errors( $ajax_handler );
		if ( empty( $errors ) ) {
			return;
		}

		$fields = $this->validation_fields( $record, array_keys( $errors ) );

		$this->validation_recorded[ $key ] = true;
		$this->events->record_validation_failure( $this->id(), $metadata['id'], $metadata['title'], $this->submission_path(), $fields );
	}

	public function new_record( $record, $ajax_handler ) {
		$metadata = $this->metadata( $record );
		$key      = $this->record_key( $record, $metadata );
		if ( ! $metadata || ! $key || ! empty( $this->terminal_recorded[ $key ] ) ) {
			return;
		}

		$this->terminal_recorded[ $key ] = true;
		if ( $this->handler_failed( $ajax_handler ) ) {
			$this->events->record_failure( $this->id(), $metadata['id'], $metadata['title'], $this->submission_path(), 'action_error' );
			return;
		}

		$this->terminal_succeeded[ $key ] = true;
		$this->events->record_success( $this->id(), $metadata['id'], $metadata['title'], $this->submission_path() );
		if ( ! empty( $this->mail_observed[ $key ] ) ) {
			$this->events->record_mail_success( $this->id(), $metadata['id'], $metadata['title'], $this->submission_path() );
		}
	}

	public function mail_sent( $settings, $record ) {
		$metadata = $this->metadata( $record, $settings );
		$key      = $this->record_key( $record, $metadata );
		if ( ! $metadata || ! $key || ! empty( $this->mail_observed[ $key ] ) ) {
			return;
		}

		// Defer the mail metric until new_record confirms that the full action pipeline succeeded.
		$this->mail_observed[ $key ] = true;
		if ( ! empty( $this->terminal_succeeded[ $key ] ) ) {
			$this->events->record_mail_success( $this->id(), $metadata['id'], $metadata['title'], $this->submission_path() );
		}
	}

	private function metadata( $record, $settings = array() ) {
		if ( ! is_object( $record ) || ! is_callable( array( $record, 'get_form_settings' ) ) ) {
			return null;
		}

		$widget_id = Sanitizer::identifier( $record->get_form_settings( 'id' ), '' );
		if ( '' === $widget_id ) {
			return null;
		}

		$form_post_id = $record->get_form_settings( 'form_post_id' );
		$post_id      = is_scalar( $form_post_id ) ? absint( $form_post_id ) : 0;
		if ( 0 === $post_id ) {
			$post_id = $this->submitted_post_id();
		}
		$id    = $post_id ? (string) $post_id . ':' . $widget_id : 'widget:' . $widget_id;
		$title = Sanitizer::title( $record->get_form_settings( 'form_name' ) );
		if ( '' === $title && is_array( $settings ) && isset( $settings['form_name'] ) ) {
			$title = Sanitizer::title( $settings['form_name'] );
		}

		return array(
			'id'    => $id,
			'title' => $title,
		);
	}

	private function submitted_post_id() {
		// post_id is Elementor's technical document context, not a visitor-entered field.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Elementor validated the request; raw technical context is type-checked and normalized next.
		$post_id = isset( $_POST['post_id'] ) ? wp_unslash( $_POST['post_id'] ) : 0;
		return is_scalar( $post_id ) ? absint( $post_id ) : 0;
	}

	private function record_key( $record, $metadata ) {
		if ( ! $metadata ) {
			return '';
		}
		return is_object( $record ) ? spl_object_hash( $record ) : $metadata['id'];
	}

	/**
	 * Maps validation error keys to static widget configuration without reading
	 * Form_Record's submitted fields, raw values, or processed values.
	 */
	private function validation_fields( $record, array $error_keys ) {
		$definitions = $record->get_form_settings( 'form_fields' );
		$indexed     = array();
		$ignored     = array();
		if ( is_array( $definitions ) ) {
			foreach ( $definitions as $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}

				$key  = Sanitizer::identifier( isset( $definition['custom_id'] ) ? $definition['custom_id'] : '', '' );
				$type = Sanitizer::field_type( isset( $definition['field_type'] ) ? $definition['field_type'] : '' );
				if ( '' === $key ) {
					continue;
				}
				if ( $this->is_non_trackable_field_type( $type ) ) {
					$ignored[ $key ] = true;
					continue;
				}

				$indexed[ $key ] = array(
					'key'   => $key,
					'label' => Sanitizer::field_label( isset( $definition['field_label'] ) ? $definition['field_label'] : $key ),
					'type'  => $type,
				);
			}
		}

		$fields = array();
		$seen   = array();
		foreach ( $error_keys as $field_id ) {
			$field_key = Sanitizer::identifier( $field_id, '' );
			if ( '' === $field_key || isset( $seen[ $field_key ] ) || isset( $ignored[ $field_key ] ) ) {
				continue;
			}

			$seen[ $field_key ] = true;
			$fields[]           = isset( $indexed[ $field_key ] )
				? $indexed[ $field_key ]
				: array(
					'key'   => $field_key,
					'label' => $field_key,
					'type'  => '',
				);
		}

		return $fields;
	}

	private function is_non_trackable_field_type( $type ) {
		return in_array( $type, array( 'hidden', 'html', 'step', 'recaptcha', 'recaptcha_v3', 'honeypot' ), true );
	}

	/**
	 * Errors is public in supported Elementor Pro releases. get_object_vars()
	 * avoids a fatal if a future release makes it non-public; a getter is used
	 * first if Elementor introduces one.
	 */
	private function handler_errors( $ajax_handler ) {
		if ( ! is_object( $ajax_handler ) ) {
			return array();
		}

		if ( is_callable( array( $ajax_handler, 'get_errors' ) ) ) {
			$errors = $ajax_handler->get_errors();
			return is_array( $errors ) ? $errors : array();
		}

		$public = get_object_vars( $ajax_handler );
		return isset( $public['errors'] ) && is_array( $public['errors'] ) ? $public['errors'] : array();
	}

	/**
	 * Elementor action failures can be represented by is_success/messages without
	 * a field error. Treat all supported public outcome channels defensively.
	 */
	private function handler_failed( $ajax_handler ) {
		if ( ! is_object( $ajax_handler ) ) {
			return true;
		}

		if ( ! empty( $this->handler_errors( $ajax_handler ) ) ) {
			return true;
		}

		$public = get_object_vars( $ajax_handler );
		if ( array_key_exists( 'is_success', $public ) && false === (bool) $public['is_success'] ) {
			return true;
		}

		if ( isset( $public['messages'] ) && is_array( $public['messages'] ) ) {
			return ! empty( $public['messages']['error'] ) || ! empty( $public['messages']['admin_error'] );
		}

		return false;
	}

	private function submission_path() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is type-checked and sanitized on the next line.
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '/';
		$referer = is_scalar( $referer ) ? esc_url_raw( (string) $referer ) : '/';
		return Sanitizer::path( $referer );
	}
}
