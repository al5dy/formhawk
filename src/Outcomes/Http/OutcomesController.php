<?php

namespace Formhawk\Outcomes\Http;

use Formhawk\Contracts\BudgetStoreInterface;
use Formhawk\Infrastructure\AtomicBudgetStore;
use Formhawk\Outcomes\ApiKeyRepository;
use Formhawk\Outcomes\OutcomeManager;
use Formhawk\Outcomes\OutcomeStatus;

final class OutcomesController {
	const MAX_BODY_BYTES      = 8192;
	const REQUESTS_PER_MINUTE = 120;
	private $manager;
	private $keys;
	private $budgets;
	private $authenticated_key;

	public function __construct( OutcomeManager $manager = null, ApiKeyRepository $keys = null, BudgetStoreInterface $budgets = null ) {
		$this->manager = $manager ? $manager : new OutcomeManager();
		$this->keys    = $keys ? $keys : new ApiKeyRepository();
		$this->budgets = $budgets ? $budgets : new AtomicBudgetStore();
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) ); }

	public function routes() {
		register_rest_route(
			'formhawk/v1',
			'/outcomes',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'record' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	public function permission( \WP_REST_Request $request ) {
		$this->authenticated_key = null;
		if ( current_user_can( 'manage_options' ) ) {
			return $this->budgets->reserve( 'outcome_user_' . get_current_user_id(), 1, self::REQUESTS_PER_MINUTE, MINUTE_IN_SECONDS )
				? true : new \WP_Error( 'formhawk_outcome_rate_limit', __( 'Outcome API rate limit exceeded.', 'formhawk' ), array( 'status' => 429 ) );
		}
		if ( ! $this->budgets->reserve( 'outcome_auth_global', 1, 6000, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'formhawk_outcome_rate_limit', __( 'Outcome API rate limit exceeded.', 'formhawk' ), array( 'status' => 429 ) );
		}
		$authorization = (string) $request->get_header( 'authorization' );
		$key_bucket    = preg_match( '/^Bearer\s+fhk_([a-f0-9]{24})\./i', trim( $authorization ), $key_match ) ? strtolower( $key_match[1] ) : 'malformed';
		if ( ! $this->budgets->reserve( 'outcome_auth_attempt_' . hash( 'sha256', $key_bucket ), 1, 180, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'formhawk_outcome_rate_limit', __( 'Outcome API rate limit exceeded.', 'formhawk' ), array( 'status' => 429 ) );
		}
		$this->authenticated_key = $this->keys->authenticate( $authorization );
		if ( ! $this->authenticated_key ) {
			if ( ! $this->budgets->reserve( 'outcome_invalid_auth', 1, 600, MINUTE_IN_SECONDS ) ) {
				return new \WP_Error( 'formhawk_outcome_rate_limit', __( 'Outcome API rate limit exceeded.', 'formhawk' ), array( 'status' => 429 ) );
			}
			return new \WP_Error( 'formhawk_outcome_forbidden', __( 'Outcome API authentication failed.', 'formhawk' ), array( 'status' => 401 ) );
		}
		if ( ! $this->budgets->reserve( 'outcome_api_' . absint( $this->authenticated_key['id'] ), 1, self::REQUESTS_PER_MINUTE, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'formhawk_outcome_rate_limit', __( 'Outcome API rate limit exceeded.', 'formhawk' ), array( 'status' => 429 ) );
		}
		return true;
	}

	public function record( \WP_REST_Request $request ) {
		$body = (string) $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error( 'formhawk_outcome_too_large', __( 'Outcome request is too large.', 'formhawk' ), array( 'status' => 413 ) );
		}
		if ( ! preg_match( '/^application\/json(?:\s*;|$)/i', (string) $request->get_header( 'content-type' ) ) ) {
			return new \WP_Error( 'formhawk_outcome_content_type', __( 'Outcome requests must use application/json.', 'formhawk' ), array( 'status' => 415 ) );
		}
		$data = json_decode( $body, true, 4 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || array_diff_key( $data, array_flip( array( 'submission_id', 'status', 'value_minor', 'currency', 'occurred_at', 'external_reference', 'idempotency_key' ) ) ) ) {
			return new \WP_Error( 'formhawk_outcome_schema', __( 'Outcome request does not match the strict schema.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( ! isset( $data['submission_id'], $data['status'] ) || ! is_string( $data['submission_id'] ) || ! is_string( $data['status'] ) ) {
			return new \WP_Error( 'formhawk_outcome_schema', __( 'submission_id and status are required strings.', 'formhawk' ), array( 'status' => 400 ) );
		}
		foreach ( array( 'currency', 'occurred_at', 'external_reference', 'idempotency_key' ) as $key ) {
			if ( isset( $data[ $key ] ) && ! is_string( $data[ $key ] ) ) {
				return new \WP_Error( 'formhawk_outcome_schema', __( 'Outcome request contains an invalid value type.', 'formhawk' ), array( 'status' => 400 ) );
			}
		}
		if ( array_key_exists( 'value_minor', $data ) && null !== $data['value_minor'] && ! is_int( $data['value_minor'] ) ) {
			return new \WP_Error( 'formhawk_outcome_schema', __( 'value_minor must be an integer or null.', 'formhawk' ), array( 'status' => 400 ) );
		}
		if ( ! isset( $data['idempotency_key'] ) || '' === $data['idempotency_key'] ) {
			$data['idempotency_key'] = (string) $request->get_header( 'idempotency-key' );
		}
		if ( '' === $data['idempotency_key'] ) {
			return new \WP_Error( 'formhawk_idempotency_required', __( 'An Idempotency-Key header or idempotency_key is required.', 'formhawk' ), array( 'status' => 400 ) );
		}
		$source = $this->authenticated_key ? 'api_key_' . absint( $this->authenticated_key['id'] ) : 'application_password';
		$result = $this->manager->record( $data, $source );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
	}
}
