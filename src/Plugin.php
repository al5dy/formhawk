<?php

namespace Formhawk;

use Formhawk\Admin\Admin;
use Formhawk\Analytics\EventIngestor;
use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\Attribution\AttributingEventRecorder;
use Formhawk\CRO\Attribution\ContextCleanup;
use Formhawk\CRO\Attribution\RequestContext;
use Formhawk\CRO\AutopilotManager;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\CRO\Http\CROConfigController;
use Formhawk\CRO\Http\CROEventsController;
use Formhawk\Http\EventsController;
use Formhawk\Infrastructure\Cleanup;
use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\Privacy;
use Formhawk\Infrastructure\ModuleGate;
use Formhawk\Outcomes\Http\OutcomesController;
use Formhawk\Outcomes\OutcomeAttribution;
use Formhawk\Outcomes\SubmissionContext;
use Formhawk\ROI\FieldROIAdmin;
use Formhawk\ROI\FieldROIScheduler;
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
		$cro      = new ExperimentRepository();
		$context  = new RequestContext();
		$context->register();
		$outcome_attribution = null;
		if ( self::field_roi_collecting() ) {
			$submission_context = new SubmissionContext();
			$submission_context->register();
			$outcome_attribution = new OutcomeAttribution( $submission_context, $context, $forms );
		}
		$provider_events = new AttributingEventRecorder( $ingestor, $context, $cro, $outcome_attribution );
		$registry        = new IntegrationRegistry(
			array(
				new ContactForm7( $provider_events ),
				new WPForms( $provider_events ),
				new ElementorForms( $provider_events ),
				new GenericForm(),
			)
		);

		( new EventsController( $ingestor ) )->register();
		if ( Database::field_roi_schema_is_current() && ( new ModuleGate() )->enabled( 'field_roi' ) ) {
			( new OutcomesController() )->register();
			( new FieldROIScheduler() )->register();
			( new FieldROIAdmin() )->register();
		}
		if ( Database::cro_schema_is_current() ) {
			( new ContextCleanup() )->register();
			( new CROConfigController( $cro ) )->register();
			( new CROEventsController( $cro ) )->register();
			( new AutopilotManager( $cro, $forms ) )->register();
		}
		$registry->register();
		( new MailMonitor() )->register();
		( new Cleanup() )->register();
		( new Privacy() )->register();
		( new Admin( $forms, $registry, $cro ) )->register();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_cro' ), 20 );
	}

	public function enqueue_cro() {
		if ( is_admin() || is_feed() || is_robots() || ! Database::cro_schema_is_current() || apply_filters( 'formhawk_cro_disabled', false ) ) {
			return;
		}
		$path = self::current_path();
		if ( ! ( new ExperimentRepository() )->has_runtime_on_path( $path ) ) {
			return;
		}

		wp_enqueue_style( 'formhawk-cro', FORMHAWK_URL . 'assets/css/cro.css', array(), FORMHAWK_VERSION );
		wp_enqueue_script(
			'formhawk-cro',
			FORMHAWK_URL . 'assets/js/cro-autopilot.js',
			array(),
			FORMHAWK_VERSION,
			array(
				'in_footer' => false,
				'strategy'  => 'defer',
			)
		);
		$test_mode = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'FORMHAWK_CRO_TEST_MODE' ) && FORMHAWK_CRO_TEST_MODE;
		$force     = '';
		if ( $test_mode ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Development-only deterministic assignment switch, disabled unless two server constants opt in.
			$raw_force = isset( $_GET['formhawk_cro_variant'] ) ? wp_unslash( $_GET['formhawk_cro_variant'] ) : '';
			$force     = is_scalar( $raw_force ) && in_array( $raw_force, array( 'control', 'variant' ), true ) ? (string) $raw_force : '';
		}
		$config = array(
			'configEndpoint' => esc_url_raw( rest_url( 'formhawk/v1/cro/config' ) ),
			'eventsEndpoint' => esc_url_raw( rest_url( 'formhawk/v1/cro/events' ) ),
			'path'           => $path,
			'debug'          => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'testMode'       => $test_mode,
			'force'          => $force,
			'strings'        => array(
				'more'     => __( 'Add additional information', 'formhawk' ),
				'progress' => __( 'Form progress', 'formhawk' ),
				'step'     => __( 'Step', 'formhawk' ),
				'back'     => __( 'Back', 'formhawk' ),
				'next'     => __( 'Next', 'formhawk' ),
			),
		);
		wp_add_inline_script(
			'formhawk-cro',
			'document.documentElement.classList.add("formhawk-cro-pending");window.setTimeout(function(){document.documentElement.classList.remove("formhawk-cro-pending");},1200);window.FormhawkCROConfig=' . wp_json_encode( $config ) . ';',
			'before'
		);
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
			'endpoint'           => esc_url_raw( rest_url( 'formhawk/v1/events' ) ),
			'token'              => EventsController::public_token(),
			'path'               => self::current_path(),
			'debug'              => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'outcomeAttribution' => self::field_roi_collecting(),
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

	private static function field_roi_collecting() {
		if ( ! Database::field_roi_schema_is_current() || ! ( new ModuleGate() )->enabled( 'field_roi' ) ) {
			return false;
		}
		$settings = get_option( 'formhawk_field_roi_settings', array() );
		return is_array( $settings ) && ! empty( $settings['enabled'] );
	}
}
