<?php

namespace Formhawk\CRO\Http;

use Formhawk\Contracts\BudgetStoreInterface;
use Formhawk\Contracts\CROContextStoreInterface;
use Formhawk\CRO\Attribution\ContextSigner;
use Formhawk\CRO\Attribution\ContextStore;
use Formhawk\CRO\CRODiagnostics;
use Formhawk\CRO\CROIngestionLimits;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\Infrastructure\AtomicBudgetStore;
use Formhawk\Support\Sanitizer;

final class CROConfigController {
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
			'/cro/config',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'config' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function config( \WP_REST_Request $request ) {
		$body   = (string) $request->get_body();
		$limits = CROIngestionLimits::all();
		if ( strlen( $body ) > $limits['body_bytes'] ) {
			return $this->reject( 'formhawk_cro_config_size', 413 );
		}
		if ( ! RequestOrigin::allows( $request ) || ! preg_match( '/^application\/json(?:\s*;|$)/i', (string) $request->get_header( 'content-type' ) ) ) {
			return $this->reject( 'formhawk_cro_config_origin', 403 );
		}
		if ( ! $this->budgets->reserve( 'cro_config', 1, $limits['config_requests_per_minute'], MINUTE_IN_SECONDS ) ) {
			$status = 'storage' === $this->budgets->last_failure() ? 503 : 429;
			return $this->reject( 'formhawk_cro_config_rate', $status, true );
		}
		$data = json_decode( $body, true, 5 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! $this->valid_request( $data, $limits ) ) {
			return $this->reject( 'formhawk_cro_config_schema', 400 );
		}
		$page_path = Sanitizer::path( $data['page_path'] );
		$segment   = $data['segment'];
		$assigned  = array();
		foreach ( array_values( $data['forms'] ) as $form_index => $identity ) {
			$runtime = $this->experiments->runtime_for_identity( $identity['provider'], $identity['provider_form_id'], $page_path );
			if ( ! $runtime ) {
				continue;
			}
			if ( ! empty( $runtime['deployment'] ) ) {
				$assigned[] = array(
					'form_index'       => $form_index,
					'provider'         => $identity['provider'],
					'provider_form_id' => $identity['provider_form_id'],
					'experiment_id'    => 0,
					'variant_id'       => 0,
					'variant_name'     => __( 'Validated baseline', 'formhawk' ),
					'control'          => true,
					'config'           => $runtime['config'],
					'context'          => '',
				);
				continue;
			}
			if ( count( $runtime['variants'] ) !== 2 ) {
				continue;
			}
			$variant = $this->assign( $runtime['variants'], isset( $data['force'] ) ? $data['force'] : '' );
			if ( ! $variant ) {
				continue;
			}
			$context = array(
				'experiment_id'    => absint( $runtime['experiment']['id'] ),
				'variant_id'       => absint( $variant['id'] ),
				'form_id'          => absint( $runtime['form_id'] ),
				'provider'         => $identity['provider'],
				'provider_form_id' => $identity['provider_form_id'],
				'segment'          => $segment,
			);
			$token   = $this->signer->sign( $context, $runtime['experiment']['policy']['context_ttl_seconds'] ?? 7200 );
			$issued  = $this->signer->verify( $token );
			if ( ! $issued || ! $this->contexts->issue( $issued ) ) {
				// Never apply an unaccounted experimental mutation; the original form remains usable.
				$this->diagnostics->increment( 'storage_failures' );
				$this->diagnostics->increment( 'cro_integrity_warning' );
				continue;
			}
			$assigned[] = array(
				'form_index'       => $form_index,
				'provider'         => $identity['provider'],
				'provider_form_id' => $identity['provider_form_id'],
				'experiment_id'    => $context['experiment_id'],
				'variant_id'       => $context['variant_id'],
				'variant_name'     => $variant['name'],
				'control'          => 'baseline' === $variant['mutation_type'],
				'config'           => $variant['config'],
				'context'          => $token,
				'fallback_config'  => $runtime['variants'][0]['config'],
				// A caller must not receive a second usable token for an arm it was not assigned.
				'fallback_context' => '',
			);
		}
		$response = new \WP_REST_Response( array( 'assignments' => $assigned ), 200 );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}

	private function valid_request( $data, array $limits ) {
		if ( ! is_array( $data ) || array_diff_key( $data, array_flip( array( 'page_path', 'segment', 'forms', 'force' ) ) ) ) {
			return false;
		}
		if ( ! isset( $data['page_path'], $data['segment'], $data['forms'] ) || ! is_string( $data['page_path'] ) || strlen( $data['page_path'] ) > 500 || Sanitizer::path( $data['page_path'] ) !== $data['page_path'] || ! in_array( $data['segment'], array( 'desktop', 'mobile' ), true ) || ! is_array( $data['forms'] ) || count( $data['forms'] ) > $limits['forms_per_request'] ) {
			return false;
		}
		if ( isset( $data['force'] ) && ( ! is_string( $data['force'] ) || ! $this->test_mode() || ! in_array( $data['force'], array( 'control', 'variant' ), true ) ) ) {
			return false;
		}
		foreach ( $data['forms'] as $identity ) {
			if ( ! is_array( $identity ) || count( $identity ) !== 2 || array_diff_key( $identity, array_flip( array( 'provider', 'provider_form_id' ) ) ) || ! isset( $identity['provider'], $identity['provider_form_id'] ) || ! is_string( $identity['provider'] ) || ! is_string( $identity['provider_form_id'] ) || ! in_array( $identity['provider'], array( 'cf7', 'wpforms', 'elementor', 'html' ), true ) || ! preg_match( '/^[A-Za-z0-9_:\-.]{1,191}$/D', $identity['provider_form_id'] ) ) {
				return false;
			}
		}
		return true;
	}

	private function assign( array $variants, $force ) {
		$total_weight = 0;
		foreach ( $variants as $variant ) {
			if ( 'active' !== ( $variant['status'] ?? '' ) || empty( $variant['id'] ) || ! isset( $variant['traffic_weight'] ) ) {
				return null;
			}
			$total_weight += absint( $variant['traffic_weight'] );
		}
		if ( 2 !== count( $variants ) || 100 !== $total_weight ) {
			return null;
		}
		if ( $this->test_mode() && in_array( $force, array( 'control', 'variant' ), true ) ) {
			return 'control' === $force ? $variants[0] : $variants[1];
		}
		try {
			$roll = random_int( 1, 100 );
		} catch ( \Exception $exception ) {
			return null;
		}
		$cursor    = 0;
		$selection = $variants[0];
		foreach ( $variants as $variant ) {
			$cursor += absint( $variant['traffic_weight'] );
			if ( $roll <= $cursor ) {
				$selection = $variant;
				break;
			}
		}
		/**
		 * @param array $selection Assigned variant.
		 * @param array $variants Eligible variants.
		 */
		$selection = apply_filters( 'formhawk_cro_assignment', $selection, $variants );
		if ( ! is_array( $selection ) || empty( $selection['id'] ) ) {
			return null;
		}
		foreach ( $variants as $variant ) {
			if ( absint( $variant['id'] ) === absint( $selection['id'] ) ) {
				return $variant;
			}
		}
		return null;
	}

	private function test_mode() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'FORMHAWK_CRO_TEST_MODE' ) && FORMHAWK_CRO_TEST_MODE;
	}

	private function reject( $code, $status, $throttled = false ) {
		$this->diagnostics->increment( 'rejected_config_requests' );
		if ( $throttled && 429 === $status ) {
			$this->diagnostics->increment( 'throttled_config_requests' );
		}
		if ( 503 === $status ) {
			$this->diagnostics->increment( 'storage_failures' );
		}
		return new \WP_Error(
			$code,
			__( 'CRO configuration request rejected.', 'formhawk' ),
			array(
				'status'      => $status,
				'retry_after' => in_array( $status, array( 429, 503 ), true ) ? 60 : 0,
			)
		);
	}
}
