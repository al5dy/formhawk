<?php

namespace Formhawk\Http;

use Formhawk\Analytics\EventIngestor;
use Formhawk\Analytics\IngestionLimits;
use Formhawk\Contracts\BudgetStoreInterface;
use Formhawk\Infrastructure\AtomicBudgetStore;
use Formhawk\Infrastructure\IngestionDiagnostics;
use Formhawk\Integrations\ClientFormIdentityValidator;

final class EventsController {
	const MAX_BODY_BYTES          = 65536;
	const MAX_EVENT_BYTES         = 16384;
	const MAX_BATCH_SIZE          = 20;
	const MAX_REQUESTS_PER_MINUTE = 600;

	private $ingestor;
	private $budgets;
	private $diagnostics;
	private $identities;

	public function __construct( EventIngestor $ingestor, BudgetStoreInterface $budgets = null, IngestionDiagnostics $diagnostics = null, ClientFormIdentityValidator $identities = null ) {
		$this->ingestor    = $ingestor;
		$this->budgets     = $budgets ? $budgets : new AtomicBudgetStore();
		$this->diagnostics = $diagnostics ? $diagnostics : new IngestionDiagnostics();
		$this->identities  = $identities ? $identities : new ClientFormIdentityValidator();
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'retry_header' ), 10, 3 );
	}

	public function retry_header( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( '/formhawk/v1/events' === $request->get_route() && in_array( $response->get_status(), array( 429, 503 ), true ) ) {
			$response->header( 'Retry-After', '60' );
		}
		return $response;
	}

	public function routes() {
		register_rest_route(
			'formhawk/v1',
			'/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ingest' ),
				// Deliberately public. Token/origin checks do not authenticate a visitor.
				'permission_callback' => '__return_true',
				'args'                => $this->route_args(),
			)
		);
	}

	private function route_args() {
		$args = ClientEventSchema::payload()['properties'];
		foreach ( $args as &$arg ) {
			// Enforce the complete schema in ingest(), so rejections also reach diagnostics.
			$arg['validate_callback'] = '__return_true';
			$arg['sanitize_callback'] = null;
		}
		return $args;
	}

	public function ingest( \WP_REST_Request $request ) {
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			return $this->handle( $request );
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	private function handle( \WP_REST_Request $request ) {
		$limits = IngestionLimits::all();
		$body   = (string) $request->get_body();
		// Count only bounded, parseable batches, including requests rejected by the first budget.
		$wire  = strlen( $body ) <= $limits['body_bytes'] ? json_decode( $body, false, 8 ) : null;
		$count = is_object( $wire ) && isset( $wire->events ) && is_array( $wire->events ) ? min( $limits['batch_size'], count( $wire->events ) ) : 0;
		if ( ! $this->budgets->reserve( 'requests', 1, $limits['requests_per_minute'], MINUTE_IN_SECONDS ) ) {
			return $this->reject( 'storage' === $this->budgets->last_failure() ? 'storage' : 'rate_limit', 'storage' === $this->budgets->last_failure() ? 503 : 429, $count );
		}
		if ( ! $this->is_same_origin_request( $request ) ) {
			return $this->reject( 'origin', 403, $count );
		}
		if ( ! preg_match( '/^application\\/json(?:\\s*;|$)/i', (string) $request->get_header( 'content-type' ) ) ) {
			return $this->reject( 'content_type', 415, $count );
		}
		if ( strlen( $body ) > $limits['body_bytes'] ) {
			return $this->reject( 'payload_size', 413 );
		}
		// Depth is bounded before walking any nested structure. No raw payload is logged.
		$payload = json_decode( $body, true, 8 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			return $this->reject( 'payload', 400 );
		}
		if ( ! isset( $payload['token'] ) || ! is_string( $payload['token'] ) || ! $this->is_valid_public_token( $payload['token'] ) ) {
			return $this->reject( 'token', 403, $count );
		}
		$schema = ClientEventSchema::payload();
		if ( ! ClientEventSchema::wire_types( $wire, $schema ) || ! ClientEventSchema::strict_types( $payload, $schema ) || is_wp_error( rest_validate_value_from_schema( $payload, $schema ) ) ) {
			return $this->reject( 'schema', 400, $count );
		}
		$events = $payload['events'];
		foreach ( $events as $event ) {
			if ( strlen( (string) wp_json_encode( $event ) ) > $limits['event_bytes'] ) {
				return $this->reject( 'payload_size', 413, $count );
			}
			if ( ! ClientEventSchema::valid_event( $event ) ) {
				return $this->reject( 'schema', 400, $count );
			}
		}
		if ( ! $this->budgets->reserve( 'events', $count, $limits['events_per_minute'], MINUTE_IN_SECONDS )
			|| ! $this->budgets->reserve( 'cost', IngestionLimits::cost( $events ), $limits['cost_per_minute'], MINUTE_IN_SECONDS ) ) {
			return $this->reject( 'storage' === $this->budgets->last_failure() ? 'storage' : 'rate_limit', 'storage' === $this->budgets->last_failure() ? 503 : 429, $count );
		}
		foreach ( $events as $event ) {
			if ( ! $this->identities->validate( $event ) ) {
				return $this->reject( 'form_identity', 422, $count );
			}
		}
		$accepted = 0;
		$reasons  = array();
		foreach ( $events as $event ) {
			if ( $this->ingestor->ingest_client( $event ) ) {
				++$accepted;
			} else {
				$reasons[] = $this->ingestor->last_rejection();
			}
		}
		if ( $reasons ) {
			// Ingestor accounts for rejected events, including calls outside REST.
			$this->diagnostics->increment( 'rejected_requests' );
		}
		$status   = $accepted ? 200 : ( in_array( 'storage', $reasons, true ) ? 503 : 429 );
		$response = new \WP_REST_Response(
			array(
				'ok'       => empty( $reasons ),
				'accepted' => $accepted,
				'rejected' => count( $reasons ),
				'reasons'  => array_values( array_unique( $reasons ) ),
			),
			$status
		);
		if ( 429 === $status || 503 === $status ) {
			$response->header( 'Retry-After', '60' );
		}
		return $response;
	}

	private function reject( $reason, $status, $events = 0 ) {
		$this->diagnostics->increment( 'rejected_requests' );
		$this->diagnostics->increment( 'rejected_events', $events );
		if ( 429 === $status ) {
			$this->diagnostics->increment( 'throttled_requests' );
			$this->diagnostics->increment( 'throttled_events', $events );
		}
		if ( 503 === $status ) {
			$this->diagnostics->increment( 'storage_rejected_events', $events );
		}
		return new \WP_Error(
			'formhawk_' . $reason,
			__( 'Analytics request rejected.', 'formhawk' ),
			array(
				'status'      => $status,
				'retry_after' => 429 === $status || 503 === $status ? 60 : 0,
			)
		);
	}

	public static function public_token() {
		return hash_hmac( 'sha256', 'formhawk|tracking-v1|' . home_url( '/' ), wp_salt( 'nonce' ) );
	}

	private function is_same_origin_request( \WP_REST_Request $request ) {
		$site_url = home_url( '/' );
		if ( ! wp_parse_url( $site_url, PHP_URL_HOST ) ) {
			return false;
		}

		$origin = $request->get_header( 'origin' );
		if ( $origin ) {
			return $this->urls_have_same_origin( $site_url, $origin );
		}

		$referer = $request->get_header( 'referer' );
		if ( $referer ) {
			return $this->urls_have_same_origin( $site_url, $referer );
		}

		// Privacy tools can strip both headers. Direct clients can also forge them;
		// atomic budgets and dimension admission apply regardless of these headers.
		return true;
	}

	private function urls_have_same_origin( $site_url, $request_url ) {
		$site_scheme    = strtolower( (string) wp_parse_url( $site_url, PHP_URL_SCHEME ) );
		$request_scheme = strtolower( (string) wp_parse_url( $request_url, PHP_URL_SCHEME ) );
		$site_host      = (string) wp_parse_url( $site_url, PHP_URL_HOST );
		$request_host   = (string) wp_parse_url( $request_url, PHP_URL_HOST );
		$site_port      = $this->normalized_port( $site_url, $site_scheme );
		$request_port   = $this->normalized_port( $request_url, $request_scheme );

		return '' !== $site_scheme
			&& $site_scheme === $request_scheme
			&& '' !== $request_host
			&& 0 === strcasecmp( $site_host, $request_host )
			&& $site_port === $request_port;
	}

	private function normalized_port( $url, $scheme ) {
		$port = wp_parse_url( $url, PHP_URL_PORT );
		if ( $port ) {
			return absint( $port );
		}
		return 'https' === $scheme ? 443 : 80;
	}

	private function is_valid_public_token( $token ) {
		if ( hash_equals( self::public_token(), (string) $token ) ) {
			return true;
		}

		// Keep already-cached 0.1.1 pages ingesting during the 0.2.0 rollout.
		$legacy = hash_hmac( 'sha256', 'formhawk|' . home_url( '/' ) . '|0.1.1', wp_salt( 'nonce' ) );
		return hash_equals( $legacy, (string) $token );
	}
}
