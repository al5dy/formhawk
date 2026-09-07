<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\CardinalityGuard;
use Formhawk\Analytics\EventIngestor;
use Formhawk\Analytics\FormRepository;
use Formhawk\Http\EventsController;
use Formhawk\Infrastructure\AtomicBudgetStore;
use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\IngestionDiagnostics;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class IngestionHardeningIntegrationTest extends IsolatedStorageTestCase {
	public function test_registered_rest_route_rejects_wrong_wire_types_and_empty_normalized_keys() {
		foreach ( array( array( 'provider_form_id' => 12 ), array( 'provider_form_id' => '___' ), array( 'duration_ms' => '1' ), array( 'extra' => 'private-payload' ) ) as $changes ) {
			$response = rest_do_request( $this->request( array( array_merge( $this->event(), $changes ) ) ) );
			$this->assertSame( 400, $response->get_status() );
		}
		$request = $this->request( array( $this->event() ) );
		$request->set_body( str_replace( '"events":[', '"events":{"0":', substr( $request->get_body(), 0, -2 ) . '}}' ) );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	public function test_configurable_protocol_limits_are_enforced_without_raising_hard_ceilings() {
		$this->with_limits(
			array(
				'batch_size'       => 1,
				'fields_per_event' => 1,
			),
			function () {
				$response = $this->controller()->ingest( $this->request( array_fill( 0, 2, $this->event() ) ) );
				$this->assertSame( 400, $response->get_error_data()['status'] );
				$event = array_merge(
					$this->event(),
					array(
						'type'   => 'client_validation_failure',
						'fields' => array_fill( 0, 2, array( 'key' => 'email' ) ),
					)
				);
				$this->assertSame( 400, $this->controller()->ingest( $this->request( array( $event ) ) )->get_error_data()['status'] );
			}
		);
		$this->with_limits(
			array(
				'batch_size'    => 999,
				'body_bytes'    => 999999,
				'forms_per_day' => 0.1,
			),
			function () {
				$limits = \Formhawk\Analytics\IngestionLimits::all();
				$this->assertSame( 20, $limits['batch_size'] );
				$this->assertSame( 65536, $limits['body_bytes'] );
				$this->assertSame( 100, $limits['forms_per_day'] );
			}
		);
	}

	public function test_request_throttling_counts_events_in_the_rejected_batch_and_sets_retry_header() {
		$this->with_limits(
			array( 'requests_per_minute' => 1 ),
			function () {
				$controller = $this->controller();
				$controller->ingest( $this->request( array( $this->event() ) ) );
				$request = $this->request( array_fill( 0, 3, $this->event() ) );
				$error   = $controller->ingest( $request );
				$this->assertSame( 429, $error->get_error_data()['status'] );
				$this->assertSame( 3, ( new IngestionDiagnostics() )->today()['throttled_events'] );
				$response = $controller->retry_header( rest_convert_error_to_response( $error ), rest_get_server(), $request );
				$this->assertSame( '60', $response->get_headers()['Retry-After'] );
			}
		);
	}

	private function event( $id = 'lead', $path = '/lead' ) {
		return array(
			'type'             => 'form_view',
			'provider'         => 'html',
			'provider_form_id' => $id,
			'page_path'        => $path,
		);
	}

	private function request( array $events ) {
		$request = new \WP_REST_Request( 'POST', '/formhawk/v1/events' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'token'  => EventsController::public_token(),
					'events' => $events,
				)
			)
		);
		return $request;
	}

	private function controller() {
		return new EventsController( new EventIngestor( new FormRepository() ) );
	}

	private function with_limits( array $limits, callable $test ) {
		$filter = static function ( $defaults ) use ( $limits ) {
			return array_merge( $defaults, $limits );
		};
		add_filter( 'formhawk_ingestion_limits', $filter );
		try {
			$test();
		} finally {
			remove_filter( 'formhawk_ingestion_limits', $filter );
		}
	}

	public function test_mass_unique_form_ids_are_bounded_even_without_origin_headers() {
		global $wpdb;
		$this->with_limits(
			array( 'forms_per_day' => 3 ),
			function () {
				for ( $batch = 0; $batch < 100; ++$batch ) {
					$events = array();
					for ( $index = 0; $index < 20; ++$index ) {
						$events[] = $this->event( 'poison-' . ( $batch * 20 + $index ) );
					}
					$response = $this->controller()->ingest( $this->request( $events ) );
					$this->assertInstanceOf( '\WP_REST_Response', $response );
					$this->assertSame( 0 === $batch ? 3 : 0, $response->get_data()['accepted'] );
					$this->assertSame( 0 === $batch ? 200 : 429, $response->get_status() );
				}
			}
		);
		$this->assertSame( '3', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::forms_table() ) ) );
		$this->assertSame( 1997, ( new IngestionDiagnostics() )->today()['cardinality_rejected_events'] );
	}

	public function test_site_path_and_per_form_placement_budgets_limit_real_provider_placements() {
		global $wpdb;
		$guard             = new CardinalityGuard();
		$event             = $this->event();
		$event['provider'] = 'elementor';
		$this->with_limits(
			array( 'placements_per_form' => 2 ),
			function () use ( $guard, $event ) {
				for ( $index = 0; $index < 100; ++$index ) {
					$event['page_path'] = '/page-' . $index;
					$this->assertSame( $index < 2 ? 'accepted' : 'cardinality', $guard->admit( $event ) );
				}
			}
		);
		$this->assertSame( '5', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::dimensions_table() ) ) );
	}

	public function test_fifty_fields_have_weighted_cost_and_cannot_bypass_per_form_cardinality() {
		global $wpdb;
		$event           = $this->event();
		$event['type']   = 'client_validation_failure';
		$event['fields'] = array();
		for ( $index = 0; $index < 50; ++$index ) {
			$event['fields'][] = array(
				'key'   => 'field-' . $index,
				'label' => 'Static field',
			);
		}
		$this->with_limits(
			array( 'cost_per_minute' => 53 ),
			function () use ( $event ) {
				$response = $this->controller()->ingest( $this->request( array( $event ) ) );
				$this->assertSame( 'formhawk_rate_limit', $response->get_error_code() );
			}
		);
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::forms_table() ) ) );
		$this->with_limits(
			array( 'fields_per_form' => 50 ),
			function () use ( $event ) {
				$response = $this->controller()->ingest( $this->request( array( $event ) ) );
				$this->assertSame( 1, $response->get_data()['accepted'] );
				// Reusing admitted dimensions is allowed even when the new-field budget is full.
				$this->assertSame( 1, $this->controller()->ingest( $this->request( array( $event ) ) )->get_data()['accepted'] );
				$event['fields'] = array( array( 'key' => 'field-51' ) );
				$this->assertSame( 429, $this->controller()->ingest( $this->request( array( $event ) ) )->get_status() );
			}
		);
		$this->assertSame( '50', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::fields_table() ) ) );
	}

	public function test_event_count_budget_is_independent_from_request_budget() {
		$this->with_limits(
			array( 'events_per_minute' => 2 ),
			function () {
				$response = $this->controller()->ingest( $this->request( array_fill( 0, 3, $this->event() ) ) );
				$this->assertSame( 429, $response->get_error_data()['status'] );
				$this->assertSame( 3, ( new IngestionDiagnostics() )->today()['throttled_events'] );
			}
		);
	}

	public function test_schema_rejects_nested_payloads_unknown_keys_types_and_counts() {
		$event    = $this->event();
		$variants = array(
			array_merge( $event, array( 'visitor' => 'private-value' ) ),
			array_merge( $event, array( 'source' => 'provider' ) ),
			array_merge( $event, array( 'provider_form_id' => str_repeat( 'x', 192 ) ) ),
			array_merge( $event, array( 'page_path' => '/lead?email=private-value' ) ),
			array_merge( $event, array( 'duration_ms' => '12' ) ),
			array_merge(
				$event,
				array(
					'type'  => 'field_interaction',
					'field' => array(
						'key'   => 'email',
						'value' => 'private-value',
					),
				)
			),
			array_merge(
				$event,
				array(
					'type'   => 'validation_failure',
					'fields' => array_fill( 0, 51, array( 'key' => 'a' ) ),
				)
			),
			array_merge(
				$event,
				array(
					'type'   => 'validation_failure',
					'fields' => array( array( 'key' => array( 'deep' => array( 'deeper' => true ) ) ) ),
				)
			),
		);
		foreach ( $variants as $variant ) {
			$response = $this->controller()->ingest( $this->request( array( $variant ) ) );
			$this->assertInstanceOf( '\WP_Error', $response );
			$this->assertSame( 400, $response->get_error_data()['status'] );
		}
	}

	public function test_nonexistent_first_class_form_ids_are_rejected_before_creating_dimensions() {
		global $wpdb;
		foreach ( array( 'cf7', 'wpforms' ) as $provider ) {
			$event             = $this->event( '999999999999' );
			$event['provider'] = $provider;
			$response          = $this->controller()->ingest( $this->request( array( $event ) ) );
			$this->assertSame( 422, $response->get_error_data()['status'] );
		}
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::dimensions_table() ) ) );
	}

	public function test_rejected_dimensions_do_not_drain_lifetime_budgets_and_daily_limits_roll_over() {
		$now   = 100 * DAY_IN_SECONDS;
		$guard = new CardinalityGuard(
			new AtomicBudgetStore(
				static function () use ( &$now ) {
					return $now;
				}
			)
		);
		$this->with_limits(
			array(
				'forms_per_day' => 1,
				'forms_total'   => 2,
			),
			function () use ( $guard, &$now ) {
				$this->assertSame( 'accepted', $guard->admit( $this->event( 'first' ) ) );
				for ( $index = 0; $index < 100; ++$index ) {
					$this->assertSame( 'cardinality', $guard->admit( $this->event( 'rejected-' . $index ) ) );
				}
				$now += DAY_IN_SECONDS;
				$this->assertSame( 'accepted', $guard->admit( $this->event( 'second' ) ) );
				$now += DAY_IN_SECONDS;
				$this->assertSame( 'cardinality', $guard->admit( $this->event( 'third' ) ) );
				$this->assertSame( 'accepted', $guard->admit( $this->event( 'first' ) ) );
			}
		);
	}

	public function test_distinct_paths_are_bounded_site_wide() {
		$guard = new CardinalityGuard();
		$this->with_limits(
			array( 'paths_per_day' => 1 ),
			function () use ( $guard ) {
				$this->assertSame( 'accepted', $guard->admit( $this->event( 'first', '/shared' ) ) );
				$this->assertSame( 'accepted', $guard->admit( $this->event( 'second', '/shared' ) ) );
				$this->assertSame( 'cardinality', $guard->admit( $this->event( 'third', '/new-path' ) ) );
			}
		);
	}

	public function test_fields_and_placements_share_a_per_form_daily_dimension_budget() {
		$guard = new CardinalityGuard();
		$this->with_limits(
			array( 'dimensions_per_form_per_day' => 2 ),
			function () use ( $guard ) {
				$event           = $this->event();
				$event['fields'] = array( array( 'key' => 'first' ) );
				$this->assertSame( 'accepted', $guard->admit( $event ) );
				$event['fields'] = array( array( 'key' => 'second' ) );
				$this->assertSame( 'cardinality', $guard->admit( $event ) );
			}
		);
	}

	public function test_budget_rollover_is_atomic_bounded_and_never_resets_backwards() {
		global $wpdb;
		$now   = 120;
		$store = new AtomicBudgetStore(
			static function () use ( &$now ) {
				return $now;
			}
		);
		$this->assertTrue( $store->reserve( 'test-rollover', 2, 3, 60 ) );
		$this->assertFalse( $store->reserve( 'test-rollover', 2, 3, 60 ) );
		$this->assertTrue( $store->reserve( 'test-rollover', 1, 3, 60 ) );
		$now = 180;
		$this->assertTrue( $store->reserve( 'test-rollover', 3, 3, 60 ) );
		$now = 120;
		$this->assertFalse( $store->reserve( 'test-rollover', 1, 3, 60 ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scope_key = %s', Database::budgets_table(), hash( 'sha256', 'test-rollover' ) ) ) );
	}
}
