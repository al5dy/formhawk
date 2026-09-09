<?php

namespace Formhawk\CRO\Http;

use Formhawk\Contracts\BudgetStoreInterface;
use Formhawk\Contracts\CROContextStoreInterface;
use Formhawk\CRO\Attribution\ClientEventLifecycle;
use Formhawk\CRO\Attribution\ContextSigner;
use Formhawk\CRO\Attribution\ContextStore;
use Formhawk\CRO\CRODiagnostics;
use Formhawk\CRO\CROIngestionLimits;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\Infrastructure\AtomicBudgetStore;

final class CROEventsController {
	private $experiments;
	private $signer;
	private $budgets;
	private $diagnostics;
	private $contexts;

	public function __construct( ExperimentRepository $experiments = null, ContextSigner $signer = null, BudgetStoreInterface $budgets = null, CRODiagnostics $diagnostics = null, CROContextStoreInterface $contexts = null ) {
		$this->experiments = $experiments ? $experiments : new ExperimentRepository();
		$this->signer      = $signer ? $signer : new ContextSigner();
		$this->budgets     = $budgets ? $budgets : new AtomicBudgetStore();
		$this->diagnostics = $diagnostics ? $diagnostics : new CRODiagnostics();
		$this->contexts    = $contexts ? $contexts : new ContextStore( $this->experiments );
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
		if ( ! RequestOrigin::allows( $request ) ) {
			return $this->reject( 'formhawk_cro_origin', 403 );
		}
		if ( ! $this->budgets->reserve( 'cro_events', 1, $limits['event_requests_per_minute'], MINUTE_IN_SECONDS ) ) {
			$status = 'storage' === $this->budgets->last_failure() ? 503 : 429;
			return $this->reject( 'formhawk_cro_rate_limit', $status, true );
		}
		$data = json_decode( $body, true, 5 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || array_diff_key( $data, array_flip( array( 'context', 'type', 'attempt', 'latency_ms', 'successful' ) ) ) ) {
			return $this->reject( 'formhawk_cro_schema', 400 );
		}
		if ( ! isset( $data['context'], $data['type'] ) || ! is_string( $data['context'] ) || strlen( $data['context'] ) > $limits['context_bytes'] || ! preg_match( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $data['context'] ) || ! is_string( $data['type'] ) || strlen( $data['type'] ) > 32 ) {
			return $this->reject( 'formhawk_cro_schema', 400 );
		}
		$type = $data['type'];
		if ( 'latency' === $type ? ( ! isset( $data['latency_ms'], $data['successful'] ) || ! is_int( $data['latency_ms'] ) || $data['latency_ms'] < 0 || $data['latency_ms'] > 300000 || ! is_bool( $data['successful'] ) ) : ( array_key_exists( 'latency_ms', $data ) || array_key_exists( 'successful', $data ) ) ) {
			return $this->reject( 'formhawk_cro_schema', 400 );
		}
		$sequenced = in_array( $type, array( 'attempt', 'observed_submit', 'latency', 'resume' ), true );
		if ( $sequenced ? ( ! isset( $data['attempt'] ) || ! is_int( $data['attempt'] ) || $data['attempt'] < 1 || $data['attempt'] > ClientEventLifecycle::MAX_ATTEMPTS ) : array_key_exists( 'attempt', $data ) ) {
			return $this->reject( 'formhawk_cro_schema', 400 );
		}
		if ( ! $sequenced && ! isset( ClientEventLifecycle::ONCE[ $type ] ) ) {
			return $this->reject( 'formhawk_cro_event', 400 );
		}
		$context = $this->signer->verify( $data['context'] );
		if ( ! $context ) {
			if ( 'expired_context' === $this->signer->last_failure() ) {
				$this->diagnostics->increment( 'expired_context' );
			}
			return $this->reject( 'formhawk_cro_context', 403 );
		}
		if ( 'observed_submit' === $type && 'html' !== $context['provider'] ) {
			$this->diagnostics->increment( 'invalid_context_lifecycle' );
			return $this->reject( 'formhawk_cro_lifecycle', 409 );
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
		$result = $this->contexts->consume( $context, $type, $data['attempt'] ?? 0, $data['latency_ms'] ?? null, $data['successful'] ?? false );
		if ( 'accepted' === $result ) {
			return new \WP_REST_Response(
				array(
					'ok'       => true,
					'accepted' => true,
				),
				200
			);
		}
		if ( 'storage_failures' === $result ) {
			return $this->reject( 'formhawk_cro_storage', 503 );
		}
		$this->diagnostics->increment( $result );
		if ( in_array( $result, array( 'duplicate_view', 'duplicate_start', 'duplicate_js_error', 'replayed_context_event' ), true ) ) {
			if ( 'replayed_context_event' !== $result ) {
				$this->diagnostics->increment( 'replayed_context_event' );
			}
			return new \WP_REST_Response(
				array(
					'ok'       => true,
					'accepted' => false,
				),
				200
			);
		}
		return $this->reject( 'formhawk_cro_lifecycle', in_array( $result, array( 'unknown_context', 'expired_context' ), true ) ? 403 : 409 );
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
