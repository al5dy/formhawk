<?php

namespace Formhawk;

use Formhawk\Admin\Admin;
use Formhawk\Analytics\EventIngestor;
use Formhawk\Analytics\FormRepository;
use Formhawk\Http\EventsController;
use Formhawk\Infrastructure\Cleanup;
use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\Privacy;
use Formhawk\Integrations\ContactForm7;
use Formhawk\Integrations\ElementorForms;
use Formhawk\Integrations\GenericForm;
use Formhawk\Integrations\IntegrationRegistry;
use Formhawk\Integrations\MailMonitor;
use Formhawk\Integrations\WPForms;

final class Plugin {
	private static $instance;
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Database::maybe_upgrade();

		$forms    = new FormRepository();
		$ingestor = new EventIngestor( $forms );
		$registry = new IntegrationRegistry(
			array(
				new ContactForm7( $ingestor ),
				new WPForms( $ingestor ),
				new ElementorForms( $ingestor ),
				new GenericForm(),
			)
		);

		( new EventsController( $ingestor ) )->register();
		$registry->register();
		( new MailMonitor() )->register();
		( new Cleanup() )->register();
		( new Privacy() )->register();
		( new Admin( $forms, $registry ) )->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
	}

	public function enqueue_tracker() {
		if ( is_admin() || is_feed() || is_robots() || apply_filters( 'formhawk_tracking_disabled', false ) ) {
			return;
		}

		wp_enqueue_script(
			'formhawk-tracker',
			FORMHAWK_URL . 'assets/js/tracker.js',
			array(),
			FORMHAWK_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'endpoint' => esc_url_raw( rest_url( 'formhawk/v1/events' ) ),
			'token'    => EventsController::public_token(),
			'path'     => self::current_path(),
			'debug'    => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
		);

		wp_add_inline_script(
			'formhawk-tracker',
			'window.FormhawkConfig=' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	public static function current_path() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input is type-checked and sanitized on the next line.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$request_uri = is_scalar( $request_uri ) ? sanitize_text_field( (string) $request_uri ) : '/';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}
}
