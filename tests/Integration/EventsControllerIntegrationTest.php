<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\EventIngestor;
use Formhawk\Analytics\FormRepository;
use Formhawk\Domain\FormIdentity;
use Formhawk\Http\EventsController;
use Formhawk\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

final class EventsControllerIntegrationTest extends TestCase {
	public function test_cross_origin_request_is_rejected() {
		$controller = new EventsController( new EventIngestor( new FormRepository() ) );
		$request    = $this->request(
			array(
				'token'  => EventsController::public_token(),
				'events' => array( array( 'type' => 'form_view' ) ),
			),
			'https://malicious.example'
		);

		$response = $controller->ingest( $request );
		$this->assertWPError( $response );
		$this->assertSame( 'formhawk_origin', $response->get_error_code() );
	}

	public function test_origin_requires_matching_scheme_and_port() {
		$controller = new EventsController( new EventIngestor( new FormRepository() ) );
		$site       = wp_parse_url( home_url( '/' ) );
		$scheme     = 'https' === $site['scheme'] ? 'http' : 'https';
		$host       = $site['host'];
		$payload    = array(
			'token'  => EventsController::public_token(),
			'events' => array( array( 'type' => 'form_view' ) ),
		);

		$wrong_scheme = $controller->ingest( $this->request( $payload, $scheme . '://' . $host ) );
		$wrong_port   = $controller->ingest( $this->request( $payload, $site['scheme'] . '://' . $host . ':43210' ) );

		$this->assertWPError( $wrong_scheme );
		$this->assertWPError( $wrong_port );
		$this->assertSame( 'formhawk_origin', $wrong_scheme->get_error_code() );
		$this->assertSame( 'formhawk_origin', $wrong_port->get_error_code() );
	}

	public function test_cached_version_011_public_token_remains_valid_during_upgrade() {
		$legacy_token = hash_hmac( 'sha256', 'formhawk|' . home_url( '/' ) . '|0.1.1', wp_salt( 'nonce' ) );
		$controller   = new EventsController( new EventIngestor( new FormRepository() ) );
		$response     = $controller->ingest(
			$this->request(
				array(
					'token'  => $legacy_token,
					'events' => array( array( 'type' => 'unsupported' ) ),
				),
				home_url( '/' )
			)
		);

		$this->assertInstanceOf( '\\WP_REST_Response', $response );
		$this->assertSame( 0, $response->get_data()['accepted'] );
	}

	public function test_public_client_cannot_forge_server_confirmed_success() {
		global $wpdb;

		$provider_form_id = 'forged-success-test';
		$controller       = new EventsController( new EventIngestor( new FormRepository() ) );
		$request          = $this->request(
			array(
				'token'  => EventsController::public_token(),
				'events' => array(
					array(
						'type'             => 'form_success',
						'provider'         => 'wpforms',
						'provider_form_id' => $provider_form_id,
						'page_path'        => '/forged',
					),
				),
			),
			home_url( '/' )
		);

		$response = $controller->ingest( $request );
		$this->assertInstanceOf( '\\WP_REST_Response', $response );
		$this->assertSame( 0, $response->get_data()['accepted'] );
		$key = FormIdentity::key( 'wpforms', $provider_form_id, '/forged' );
		$this->assertNull(
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE form_key = %s', Database::forms_table(), $key )
			)
		);
	}

	public function test_oversized_body_is_rejected_before_ingestion() {
		$controller = new EventsController( new EventIngestor( new FormRepository() ) );
		$request    = new \WP_REST_Request( 'POST', '/formhawk/v1/events' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'origin', home_url( '/' ) );
		$request->set_body( str_repeat( 'x', EventsController::MAX_BODY_BYTES + 1 ) );

		$response = $controller->ingest( $request );
		$this->assertWPError( $response );
		$this->assertSame( 'formhawk_payload_size', $response->get_error_code() );
	}

	public function test_non_scalar_public_token_is_rejected_without_coercion() {
		$controller = new EventsController( new EventIngestor( new FormRepository() ) );
		$response   = $controller->ingest(
			$this->request(
				array(
					'token'  => array( EventsController::public_token() ),
					'events' => array( array( 'type' => 'form_view' ) ),
				),
				home_url( '/' )
			)
		);

		$this->assertWPError( $response );
		$this->assertSame( 'formhawk_token', $response->get_error_code() );
	}

	public function test_site_wide_rate_limit_uses_no_visitor_identifier() {
		$key    = 'formhawk_rate_' . gmdate( 'YmdHi' );
		$filter = static function () {
			return 1;
		};
		delete_transient( $key );
		add_filter( 'formhawk_event_request_limit', $filter );
		$controller = new EventsController( new EventIngestor( new FormRepository() ) );
		$payload    = array(
			'token'  => EventsController::public_token(),
			'events' => array(
				array(
					'type'     => 'form_success',
					'provider' => 'wpforms',
				),
			),
		);

		$first  = $controller->ingest( $this->request( $payload, home_url( '/' ) ) );
		$second = $controller->ingest( $this->request( $payload, home_url( '/' ) ) );

		remove_filter( 'formhawk_event_request_limit', $filter );
		delete_transient( $key );
		$this->assertInstanceOf( '\\WP_REST_Response', $first );
		$this->assertWPError( $second );
		$this->assertSame( 'formhawk_rate_limit', $second->get_error_code() );
	}

	private function request( array $payload, $origin ) {
		$request = new \WP_REST_Request( 'POST', '/formhawk/v1/events' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'origin', $origin );
		$request->set_body( wp_json_encode( $payload ) );
		return $request;
	}

	private function assertWPError( $value ) {
		$this->assertInstanceOf( '\\WP_Error', $value );
	}
}
