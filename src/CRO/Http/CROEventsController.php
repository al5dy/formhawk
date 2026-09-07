<?php

namespace Formhawk\CRO\Http;

use Formhawk\Contracts\BudgetStoreInterface;
use Formhawk\CRO\Attribution\ContextSigner;
use Formhawk\CRO\CRODiagnostics;
use Formhawk\CRO\CROIngestionLimits;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\Infrastructure\AtomicBudgetStore;

final class CROEventsController {
	private $experiments;
	private $signer;
	private $budgets;
	private $diagnostics;

	public function __construct( ExperimentRepository $experiments = null, ContextSigner $signer = null, BudgetStoreInterface $budgets = null, CRODiagnostics $diagnostics = null ) {
		$this->experiments = $experiments ? $experiments : new ExperimentRepository();
		$this->signer      = $signer ? $signer : new ContextSigner();
		$this->budgets     = $budgets ? $budgets : new AtomicBudgetStore();
		$this->diagnostics = $diagnostics ? $diagnostics : new CRODiagnostics();
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'formhawk/v1',
			'/cro/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ingest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function ingest( \WP_REST_Request $request ) {
		$body   = (string) $request->get_body();
		$limits = CROIngestionLimits::all();
		if ( strlen( $body ) > $limits['body_bytes'] ) {
			return $this->reject( 'formhawk_cro_payload_size', 413 );
		}
		if ( ! preg_match( '/^application\/json(?:\s*;|$)/i', (string) $request->get_header( 'content-type' ) ) ) {
			return $this->reject( 'formhawk_cro_content_type', 415 );
		}
		if ( ! $this->same_origin( $request ) ) {
			return $this->reject( 'formhawk_cro_origin', 403 );
		}
		if ( ! $this->budgets->reserve( 'cro_events', 1, $limits['event_requests_per_minute'], MINUTE_IN_SECONDS ) ) {
			$status = 'storage' === $this->budgets->last_failure() ? 503 : 429;
			return $this->reject( 'formhawk_cro_rate_limit', $status, true );
		}
		$data = json_decode( $body, true, 5 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || array_diff_key( $data, array_flip( array( 'context', 'type', 'latency_ms' ) ) ) ) {
			return $this->reject( 'formhawk_cro_schema', 400 );
		}
		if ( ! isset( $data['context'], $data['type'] ) || ! is_string( $data['context'] ) || strlen( $data['context'] ) > $limits['context_bytes'] || ! preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $data['context'] ) || ! is_string( $data['type'] ) || strlen( $data['type'] ) > 32 ) {
			return $this->reject( 'formhawk_cro_schema', 400 );
		}
		if ( array_key_exists( 'latency_ms', $data ) && ( ! is_int( $data['latency_ms'] ) || $data['latency_ms'] < 0 || $data['latency_ms'] > 300000 ) ) {
			return $this->reject( 'formhawk_cro_schema', 400 );
		}
		$map  = array(
			'view'              => array( 'views' => 1 ),
			'start'             => array( 'starts' => 1 ),
			'attempt'           => array( 'attempts' => 1 ),
			'observed_submit'   => array( 'observed_submits' => 1 ),
			'client_validation' => array( 'client_validation_failures' => 1 ),
			'abandon'           => array( 'abandonments' => 1 ),
			'js_error'          => array( 'js_errors' => 1 ),
			'latency'           => array(),
		);
		$type = sanitize_key( $data['type'] );
		if ( ! isset( $map[ $type ] ) ) {
			return $this->reject( 'formhawk_cro_event', 400 );
		}
		$context = $this->signer->verify( $data['context'] );
		if ( ! $context ) {
			return $this->reject( 'formhawk_cro_context', 403 );
		}
		$experiment = $this->experiments->find( $context['experiment_id'] );
		if ( ! $experiment || absint( $experiment['form_id'] ) !== $context['form_id'] || ! in_array( $experiment['status'], array( 'running', 'promoted_monitoring' ), true ) ) {
			return $this->reject( 'formhawk_cro_inactive', 409 );
		}
		$variants = $this->experiments->variants( $context['experiment_id'] );
		$valid    = false;
		foreach ( $variants as $variant ) {
			if ( absint( $variant['id'] ) === $context['variant_id'] && 'active' === $variant['status'] ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) {
			return $this->reject( 'formhawk_cro_variant', 409 );
		}
		$increments = $map[ $type ];
		if ( isset( $data['latency_ms'] ) ) {
			$increments['latency_total_ms'] = $data['latency_ms'];
			$increments['latency_samples']  = 1;
		}
		if ( $increments && ! $this->experiments->increment( $context['experiment_id'], $context['variant_id'], $context['segment'], $increments ) ) {
			return $this->reject( 'formhawk_cro_storage', 503 );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private function same_origin( \WP_REST_Request $request ) {
		$source = $request->get_header( 'origin' );
		if ( ! $source ) {
			$source = $request->get_header( 'referer' );
		}
		if ( ! $source ) {
			return true;
		}
		return $this->origin( home_url( '/' ) ) === $this->origin( $source );
	}

	private function origin( $url ) {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! $scheme || ! $host ) {
			return '';
		}
		$port = $port ? absint( $port ) : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . $host . ':' . $port;
	}

	private function reject( $code, $status, $throttled = false ) {
		$this->diagnostics->increment( 'rejected_event_requests' );
		if ( $throttled && 429 === $status ) {
			$this->diagnostics->increment( 'throttled_event_requests' );
		}
		if ( 503 === $status ) {
			$this->diagnostics->increment( 'storage_failures' );
		}
		return new \WP_Error(
			$code,
			__( 'CRO request rejected.', 'formhawk' ),
			array(
				'status'      => $status,
				'retry_after' => in_array( $status, array( 429, 503 ), true ) ? 60 : 0,
			)
		);
	}
}
