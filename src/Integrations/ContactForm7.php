<?php

namespace Formhawk\Integrations;

use Formhawk\Contracts\EventRecorderInterface;
use Formhawk\Contracts\FormIntegrationInterface;
use Formhawk\Domain\ProviderCatalog;
use Formhawk\Support\Sanitizer;

final class ContactForm7 implements FormIntegrationInterface {
	private $events;
	private $terminal_recorded   = array();
	private $validation_recorded = array();

	public function __construct( EventRecorderInterface $events ) {
		$this->events = $events;
	}

	public function id() {
		return ProviderCatalog::CF7;
	}

	public function label() {
		return __( 'Contact Form 7', 'formhawk' );
	}

	public function is_available() {
		return defined( 'WPCF7_VERSION' ) || class_exists( 'WPCF7_ContactForm' );
	}

	public function capabilities() {
		return array(
			ProviderCatalog::CAP_FRONTEND_TRACKING,
			ProviderCatalog::CAP_SERVER_SUCCESS,
			ProviderCatalog::CAP_SERVER_FAILURE,
			ProviderCatalog::CAP_SERVER_VALIDATION,
			ProviderCatalog::CAP_MAIL_SUCCESS,
			ProviderCatalog::CAP_MAIL_FAILURE,
			ProviderCatalog::CAP_STABLE_FIELD_IDS,
			ProviderCatalog::CAP_DYNAMIC_RENDERING,
			ProviderCatalog::CAP_SUBMISSION_ATTRIBUTION,
			ProviderCatalog::CAP_FIELD_ROI,
			ProviderCatalog::CAP_REMOVE_FIELD,
			ProviderCatalog::CAP_FIELD_ORDER,
			ProviderCatalog::CAP_PROGRESSIVE_DISCLOSURE,
			ProviderCatalog::CAP_DEPENDENCY_GRAPH,
			ProviderCatalog::CAP_OUTCOME_ATTRIBUTION,
		);
	}

	public function register() {
		add_action( 'wpcf7_mail_sent', array( $this, 'mail_sent' ), 10, 1 );
		add_action( 'wpcf7_mail_failed', array( $this, 'mail_failed' ), 10, 1 );
		add_action( 'wpcf7_submit', array( $this, 'submitted' ), 10, 2 );
	}

	public function mail_sent( $contact_form ) {
		$metadata = $this->metadata( $contact_form );
		if ( ! $metadata || $this->terminal_was_recorded( $metadata['id'] ) ) {
			return;
		}

		$this->terminal_recorded[ $metadata['id'] ] = true;
		$this->events->record_success(
			$this->id(),
			$metadata['id'],
			$metadata['title'],
			$this->submission_path(),
			array(
				'mail_success' => true,
				'fields'       => $this->structural_fields( $contact_form ),
			)
		);
	}

	public function mail_failed( $contact_form ) {
		$metadata = $this->metadata( $contact_form );
		if ( ! $metadata || $this->terminal_was_recorded( $metadata['id'] ) ) {
			return;
		}

		$this->terminal_recorded[ $metadata['id'] ] = true;
		$this->events->record_mail_failure( $this->id(), $metadata['id'], $metadata['title'], $this->submission_path() );
	}

	public function submitted( $contact_form, $result ) {
		$metadata = $this->metadata( $contact_form );
		if ( ! $metadata || ! is_array( $result ) ) {
			return;
		}

		$status = isset( $result['status'] ) ? sanitize_key( $result['status'] ) : '';
		$path   = $this->submission_path();

		if ( 'validation_failed' === $status ) {
			if ( isset( $this->validation_recorded[ $metadata['id'] ] ) ) {
				return;
			}
			$this->validation_recorded[ $metadata['id'] ] = true;
			$fields                                       = array();
			if ( isset( $result['invalid_fields'] ) && is_array( $result['invalid_fields'] ) ) {
				foreach ( array_keys( $result['invalid_fields'] ) as $field_name ) {
					$key      = Sanitizer::identifier( $field_name, 'unknown' );
					$fields[] = array(
						'key'   => $key,
						'label' => $key,
						'type'  => '',
					);
				}
			}
			$this->events->record_validation_failure( $this->id(), $metadata['id'], $metadata['title'], $path, $fields );
			return;
		}

		if ( $this->terminal_was_recorded( $metadata['id'] ) ) {
			return;
		}

		if ( 'mail_sent' === $status ) {
			$this->terminal_recorded[ $metadata['id'] ] = true;
			$this->events->record_success(
				$this->id(),
				$metadata['id'],
				$metadata['title'],
				$path,
				array(
					'mail_success' => true,
					'fields'       => $this->structural_fields( $contact_form ),
				)
			);
		} elseif ( 'mail_failed' === $status ) {
			$this->terminal_recorded[ $metadata['id'] ] = true;
			$this->events->record_mail_failure( $this->id(), $metadata['id'], $metadata['title'], $path );
		} elseif ( in_array( $status, array( 'aborted', 'spam' ), true ) ) {
			$this->terminal_recorded[ $metadata['id'] ] = true;
			$this->events->record_failure( $this->id(), $metadata['id'], $metadata['title'], $path, $status );
		}
	}

	private function metadata( $contact_form ) {
		if ( ! is_object( $contact_form ) || ! is_callable( array( $contact_form, 'id' ) ) ) {
			return null;
		}

		return array(
			'id'    => (string) absint( $contact_form->id() ),
			'title' => is_callable( array( $contact_form, 'title' ) ) ? Sanitizer::title( $contact_form->title() ) : '',
		);
	}

	private function terminal_was_recorded( $form_id ) {
		return ! empty( $this->terminal_recorded[ $form_id ] );
	}

	private function structural_fields( $contact_form ) {
		if ( ! is_object( $contact_form ) || ! is_callable( array( $contact_form, 'scan_form_tags' ) ) ) {
			return array(); }
		$fields = array();
		foreach ( array_slice( (array) $contact_form->scan_form_tags(), 0, 50 ) as $tag ) {
			if ( ! is_object( $tag ) ) {
				continue; }
			$public = get_object_vars( $tag );
			$key    = Sanitizer::identifier( isset( $public['name'] ) ? $public['name'] : '', '' );
			$type   = Sanitizer::field_type( isset( $public['basetype'] ) ? $public['basetype'] : '' );
			if ( '' === $key || in_array( $type, array( 'submit', 'acceptance', 'quiz', 'captcha', 'recaptcha', 'file', 'hidden' ), true ) ) {
				continue; }
			$fields[] = array(
				'key'      => $key,
				'label'    => $key,
				'type'     => $type,
				'required' => is_callable( array( $tag, 'is_required' ) ) && $tag->is_required(),
				'position' => count( $fields ),
			);
		}
		return $fields;
	}

	private function submission_path() {
		if ( class_exists( 'WPCF7_Submission' ) && is_callable( array( 'WPCF7_Submission', 'get_instance' ) ) ) {
			$submission = \WPCF7_Submission::get_instance();
			if ( $submission && is_callable( array( $submission, 'get_meta' ) ) ) {
				$url = $submission->get_meta( 'url' );
				if ( is_string( $url ) && '' !== $url ) {
					return Sanitizer::path( $url );
				}
			}
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is type-checked and sanitized on the next line.
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '/';
		$referer = is_scalar( $referer ) ? esc_url_raw( (string) $referer ) : '/';
		return Sanitizer::path( $referer );
	}
}
