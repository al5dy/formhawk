<?php

namespace Formhawk\Http;

use Formhawk\Analytics\EventIngestor;

final class EventsController {
	const MAX_BODY_BYTES          = 65536;
	const MAX_EVENT_BYTES         = 4096;
	const MAX_BATCH_SIZE          = 20;
	const MAX_REQUESTS_PER_MINUTE = 600;

	private $ingestor;

	public function __construct( EventIngestor $ingestor ) {
		$this->ingestor = $ingestor;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'formhawk/v1',
			'/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ingest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function ingest( \WP_REST_Request $request ) {
		if ( ! $this->is_same_origin_request( $request ) ) {
			return new \WP_Error( 'formhawk_origin', __( 'Invalid request origin.', 'formhawk' ), array( 'status' => 403 ) );
		}

		$content_type = (string) $request->get_header( 'content-type' );
		if ( false === stripos( $content_type, 'application/json' ) ) {
			return new \WP_Error( 'formhawk_content_type', __( 'Event payload must use JSON.', 'formhawk' ), array( 'status' => 415 ) );
		}

		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error( 'formhawk_payload_size', __( 'Event payload is too large.', 'formhawk' ), array( 'status' => 413 ) );
		}

		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return new \WP_Error( 'formhawk_payload', __( 'Invalid event payload.', 'formhawk' ), array( 'status' => 400 ) );
		}

		$token = isset( $payload['token'] ) && is_scalar( $payload['token'] ) ? (string) $payload['token'] : '';
		if ( ! $this->is_valid_public_token( $token ) ) {
			return new \WP_Error( 'formhawk_token', __( 'Invalid tracking token.', 'formhawk' ), array( 'status' => 403 ) );
		}
		if ( $this->request_limit_exceeded() ) {
			return new \WP_Error( 'formhawk_rate_limit', __( 'Too many analytics requests. Try again shortly.', 'formhawk' ), array( 'status' => 429 ) );
		}

		$events = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array();
		if ( empty( $events ) || count( $events ) > self::MAX_BATCH_SIZE ) {
			return new \WP_Error( 'formhawk_events', __( 'Event batch must contain between 1 and 20 events.', 'formhawk' ), array( 'status' => 400 ) );
		}

		$accepted = 0;
		foreach ( $events as $event ) {
			$encoded_size = is_array( $event ) ? strlen( (string) wp_json_encode( $event ) ) : 0;
			if ( $encoded_size > 0 && $encoded_size <= self::MAX_EVENT_BYTES && $this->ingestor->ingest_client( $event ) ) {
				++$accepted;
			}
		}

		return rest_ensure_response(
			array(
				'ok'       => true,
				'accepted' => $accepted,
			)
		);
	}

	public static function public_token() {
		return hash_hmac( 'sha256', 'formhawk|tracking-v1|' . home_url( '/' ), wp_salt( 'nonce' ) );
	}

	private function is_same_origin_request( \WP_REST_Request $request ) {
		$site_url = home_url( '/' );
		if ( ! wp_parse_url( $site_url, PHP_URL_HOST ) ) {
			return true;
		}

		$origin = $request->get_header( 'origin' );
		if ( $origin ) {
			return $this->urls_have_same_origin( $site_url, $origin );
		}

		$referer = $request->get_header( 'referer' );
		if ( $referer ) {
			return $this->urls_have_same_origin( $site_url, $referer );
		}

		// Some privacy tools strip both headers. The public token still prevents blind writes.
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

	private function request_limit_exceeded() {
		$limit = absint( apply_filters( 'formhawk_event_request_limit', self::MAX_REQUESTS_PER_MINUTE ) );
		if ( 0 === $limit ) {
			return false;
		}

		// A site-wide, minute-scoped counter provides coarse abuse resistance without storing IPs or visitor identifiers.
		$key   = 'formhawk_rate_' . gmdate( 'YmdHi' );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return false;
	}
}
