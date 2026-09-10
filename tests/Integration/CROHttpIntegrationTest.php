<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\Attribution\ContextSigner;
use Formhawk\CRO\Attribution\ContextStore;
use Formhawk\CRO\Attribution\AttributingEventRecorder;
use Formhawk\CRO\Attribution\RequestContext;
use Formhawk\Contracts\CROContextStoreInterface;
use Formhawk\Infrastructure\Database;
use Formhawk\Tests\Fixtures\CROToken;
use Formhawk\Tests\Fixtures\RecordingEventRecorder;
use Formhawk\CRO\CRODiagnostics;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\CRO\Experiments\ExperimentStatus;
use Formhawk\CRO\Http\CROConfigController;
use Formhawk\CRO\Http\CROEventsController;
use Formhawk\CRO\OptimizationPolicy;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class CROHttpIntegrationTest extends IsolatedStorageTestCase {
	public function test_generic_observed_submits_require_sequenced_attempts_and_cannot_be_replayed() {
		$repository = new ExperimentRepository();
		$identity   = ( new FormRepository() )->resolve(
			array(
				'provider'         => 'html',
				'provider_form_id' => '77',
				'title'            => 'Generic observed form',
				'page_path'        => '/lead',
			)
		);
		$repository->enable( $identity['form_id'] );
		$record     = array_merge(
			$this->experiment_record( $identity['form_id'] ),
			array(
				'primary_metric' => 'observed_submit_rate',
				'evidence_level' => 'client_observed',
			)
		);
		$id         = $repository->create( $record, $this->variants() );
		$config     = ( new CROConfigController( $repository ) )->config(
			$this->request(
				'/formhawk/v1/cro/config',
				array(
					'page_path' => '/lead',
					'segment'   => 'desktop',
					'forms'     => array(
						array(
							'provider'         => 'html',
							'provider_form_id' => '77',
						),
					),
				)
			)
		);
		$assignment = $config->get_data()['assignments'][0];
		$events     = new CROEventsController( $repository );
		$send       = function ( $type, $attempt = null ) use ( $assignment, $events ) {
			$payload = array(
				'context' => $assignment['context'],
				'type'    => $type,
			);
			if ( null !== $attempt ) {
				$payload['attempt'] = $attempt;
			}
			return $events->ingest( $this->request( '/formhawk/v1/cro/events', $payload ) );
		};
		$this->assertSame( 409, $send( 'observed_submit', 1 )->get_error_data()['status'] );
		$this->assertSame( 1, ( new CRODiagnostics() )->today()['event_without_attempt'] );
		$this->assertTrue( $send( 'view' )->get_data()['accepted'] );
		$this->assertTrue( $send( 'start' )->get_data()['accepted'] );
		foreach ( array( 1, 2 ) as $attempt ) {
			$this->assertTrue( $send( 'attempt', $attempt )->get_data()['accepted'] );
			$this->assertTrue( $send( 'observed_submit', $attempt )->get_data()['accepted'] );
			$this->assertFalse( $send( 'observed_submit', $attempt )->get_data()['accepted'] );
		}
		$totals = $repository->aggregate( $id )[ $assignment['variant_id'] ];
		$this->assertSame( 2, $totals['attempts'] );
		$this->assertSame( 2, $totals['observed_submits'] );
		$this->assertSame( 0, $totals['confirmed_successes'] );
	}

	public function test_all_supported_providers_keep_trusted_attribution_across_independent_submissions() {
		$repository = new ExperimentRepository();
		foreach ( array( 'cf7', 'wpforms', 'elementor' ) as $provider ) {
			$identity = ( new FormRepository() )->resolve(
				array(
					'provider'         => $provider,
					'provider_form_id' => '77',
					'title'            => 'Issued provider',
					'page_path'        => '/lead',
				)
			);
			$repository->enable( $identity['form_id'] );
			$id         = $repository->create( $this->experiment_record( $identity['form_id'] ), $this->variants() );
			$response   = ( new CROConfigController( $repository ) )->config(
				$this->request(
					'/formhawk/v1/cro/config',
					array(
						'page_path' => '/lead',
						'segment'   => 'desktop',
						'forms'     => array(
							array(
								'provider'         => $provider,
								'provider_form_id' => '77',
							),
						),
					)
				)
			);
			$assignment = $response->get_data()['assignments'][0];
			for ( $attempt = 0; $attempt < 2; ++$attempt ) {
				$_POST['_formhawk_cro']    = $assignment['context'];
				$_REQUEST['_formhawk_cro'] = $assignment['context'];
				$context                   = new RequestContext();
				$context->capture();
				$recorder = new AttributingEventRecorder( new RecordingEventRecorder(), $context, $repository );
				$this->assertTrue( $recorder->record_success( $provider, '77', 'Issued provider', '/lead' ) );
				$this->assertTrue( $recorder->record_success( $provider, 'wrong-form', 'Wrong form', '/lead' ) );
			}
			$totals = $repository->aggregate( $id )[ $assignment['variant_id'] ];
			$this->assertSame( 1, $totals['confirmed_successes'] );
			$this->assertSame( 1, $totals['assignments'] );
			$this->assertSame( 0, $totals['views'], 'Trusted provider callbacks do not require browser telemetry to arrive.' );
		}
	}

	public function test_two_simultaneous_identical_view_requests_increment_exactly_once() {
		global $wpdb;
		if ( ! function_exists( 'proc_open' ) || ! getenv( 'FORMHAWK_WP_ROOT' ) ) {
			$this->markTestSkipped( 'Requires independent process workers and a disposable WordPress root.' );
		}
		list( $repository, $id ) = $this->experiment();
		$assignment              = ( new CROConfigController( $repository ) )->config( $this->config_request() )->get_data()['assignments'][0];
		$workers                 = array();
		$start                   = (string) ( microtime( true ) + 3 );
		for ( $index = 0; $index < 2; ++$index ) {
			$pipes = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Availability checked above; independent connections are necessary to test concurrent admission.
			$process = proc_open(
				array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/cro-context-worker.php', $wpdb->prefix, $start, $assignment['context'] ),
				array(
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			$this->assertIsResource( $process );
			$workers[] = array( $process, $pipes );
		}
		$accepted = 0;
		foreach ( $workers as list( $process, $pipes ) ) {
			$output = stream_get_contents( $pipes[1] );
			$error  = stream_get_contents( $pipes[2] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Child stdout pipe, not filesystem content.
			fclose( $pipes[1] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Child stderr pipe, not filesystem content.
			fclose( $pipes[2] );
			$this->assertSame( 0, proc_close( $process ), $error );
			$this->assertMatchesRegularExpression( '/^[01]$/D', $output );
			$accepted += (int) $output;
		}
		$this->assertSame( 1, $accepted );
		$this->assertSame( 1, $repository->aggregate( $id )[ $assignment['variant_id'] ]['views'] );
	}

	public function test_each_dom_assignment_is_registered_and_counted_before_any_browser_view() {
		global $wpdb;
		list( $repository, $experiment_id ) = $this->experiment();
		$controller                         = new CROConfigController( $repository );
		$response                           = $controller->config(
			$this->request(
				'/formhawk/v1/cro/config',
				array(
					'page_path' => '/lead',
					'segment'   => 'desktop',
					'forms'     => array_fill(
						0,
						2,
						array(
							'provider'         => 'cf7',
							'provider_form_id' => '77',
						)
					),
				)
			)
		);
		$assignments                        = $response->get_data()['assignments'];
		$this->assertCount( 2, $assignments );
		$jtis = array();
		foreach ( $assignments as $index => $assignment ) {
			$this->assertSame( $index, $assignment['form_index'] );
			$this->assertSame( '', $assignment['fallback_context'] );
			$context = ( new ContextSigner() )->verify( $assignment['context'] );
			$this->assertTrue( ( new ContextStore() )->is_issued( $context ) );
			$jtis[] = $context['jti'];
			$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE context_hash=%s', Database::cro_contexts_table(), hash( 'sha256', $context['jti'] ) ), ARRAY_A );
			$this->assertStringNotContainsString( $context['jti'], wp_json_encode( $row ) );
			$_POST['_formhawk_cro']    = $assignment['context'];
			$_REQUEST['_formhawk_cro'] = $assignment['context'];
			$request_context           = new RequestContext();
			$request_context->capture();
			$this->assertSame( $context, $request_context->get( 'cf7', '77' ) );
			$this->assertNull( $request_context->get( 'wpforms', '77' ) );
		}
		$this->assertNotSame( $jtis[0], $jtis[1] );
		$totals = $repository->aggregate( $experiment_id );
		$this->assertSame( 2, array_sum( array_column( $totals, 'assignments' ) ) );
		$this->assertSame( 0, array_sum( array_column( $totals, 'views' ) ) );
	}

	public function test_storage_failure_returns_no_experimental_mutation_or_alternate_context() {
		list( $repository, $experiment_id ) = $this->experiment();
		$store                              = new class() implements CROContextStoreInterface {
			public $issuances = 0;
			public function issue( array $context ) {
				++$this->issuances;
				return false;
			}
			public function is_issued( array $context ) {
				return false;
			}
			public function consume( array $context, $type, $attempt = 0, $latency = null, $successful = false ) {
				return 'storage_failures';
			}
			public function consume_provider_success( array $context ) {
				return 'storage_failures';
			}
		};
		$controller                         = new CROConfigController( $repository, null, null, null, $store );
		$response                           = $controller->config( $this->config_request() );
		$this->assertSame( array( 'assignments' => array() ), $response->get_data() );
		$this->assertSame( 1, $store->issuances );
		$this->assertSame( array(), $repository->aggregate( $experiment_id ) );
		$this->assertSame( 1, ( new CRODiagnostics() )->today()['cro_integrity_warning'] );
	}

	public function test_optional_origin_headers_are_defense_in_depth_not_authentication() {
		list( $repository ) = $this->experiment();
		$config             = new CROConfigController( $repository );
		$events             = new CROEventsController( $repository );
		$assignment         = $config->config( $this->config_request() )->get_data()['assignments'][0];
		foreach ( array(
			array(
				'origin'         => home_url( '/' ),
				'sec-fetch-site' => 'same-origin',
			),
			array( 'referer' => home_url( '/lead' ) ),
			array(),
		) as $headers ) {
			$request = $this->config_request();
			$request->remove_header( 'origin' );
			$event = $this->request(
				'/formhawk/v1/cro/events',
				array(
					'context' => $assignment['context'],
					'type'    => 'view',
				)
			);
			$event->remove_header( 'origin' );
			foreach ( $headers as $name => $value ) {
				$request->set_header( $name, $value );
				$event->set_header( $name, $value );
			}
			$this->assertInstanceOf( '\WP_REST_Response', $config->config( $request ) );
			$this->assertInstanceOf( '\WP_REST_Response', $events->ingest( $event ) );
		}
		foreach ( array(
			'origin'         => 'https://other.example',
			'sec-fetch-site' => 'cross-site',
			'referer'        => 'https://other.example/lead',
		) as $header => $value ) {
			$request = $this->config_request();
			$event   = $this->request(
				'/formhawk/v1/cro/events',
				array(
					'context' => $assignment['context'],
					'type'    => 'view',
				)
			);
			if ( 'referer' === $header ) {
				$request->remove_header( 'origin' );
				$event->remove_header( 'origin' );
			}
			$request->set_header( $header, $value );
			$event->set_header( $header, $value );
			$this->assertSame( 403, $config->config( $request )->get_error_data()['status'] );
			$this->assertSame( 403, $events->ingest( $event )->get_error_data()['status'] );
		}
	}

	public function test_attempt_sequences_retries_and_one_shot_cardinality_are_enforced() {
		list( $repository, $id ) = $this->experiment();
		$assignment              = ( new CROConfigController( $repository ) )->config( $this->config_request() )->get_data()['assignments'][0];
		$controller              = new CROEventsController( $repository );
		$send                    = function ( $type, array $details = array() ) use ( $assignment, $controller ) {
			return $controller->ingest(
				$this->request(
					'/formhawk/v1/cro/events',
					array_merge(
						array(
							'context' => $assignment['context'],
							'type'    => $type,
						),
						$details
					)
				)
			);
		};
		$this->assertInstanceOf( '\WP_Error', $send( 'abandon' ) );
		$this->assertInstanceOf( '\WP_Error', $send( 'client_validation' ) );
		$this->assertInstanceOf(
			'\WP_Error',
			$send(
				'latency',
				array(
					'attempt'    => 1,
					'latency_ms' => 100,
					'successful' => true,
				)
			)
		);
		$this->assertInstanceOf( '\WP_Error', $send( 'observed_submit', array( 'attempt' => 1 ) ) );
		foreach ( array( 'view', 'start', 'js_error', 'client_validation' ) as $type ) {
			$this->assertTrue( $send( $type )->get_data()['accepted'] );
			$this->assertFalse( $send( $type )->get_data()['accepted'] );
		}
		$this->assertTrue( $send( 'attempt', array( 'attempt' => 1 ) )->get_data()['accepted'] );
		$this->assertFalse( $send( 'attempt', array( 'attempt' => 1 ) )->get_data()['accepted'] );
		$this->assertInstanceOf( '\WP_Error', $send( 'attempt', array( 'attempt' => 2 ) ) );
		$this->assertInstanceOf( '\WP_Error', $send( 'abandon' ) );
		$this->assertTrue(
			$send(
				'latency',
				array(
					'attempt'    => 1,
					'latency_ms' => 100,
					'successful' => true,
				)
			)->get_data()['accepted']
		);
		$this->assertFalse(
			$send(
				'latency',
				array(
					'attempt'    => 1,
					'latency_ms' => 99999,
					'successful' => false,
				)
			)->get_data()['accepted']
		);
		$this->assertInstanceOf( '\WP_Error', $send( 'abandon' ) );
		$this->assertTrue( $send( 'attempt', array( 'attempt' => 2 ) )->get_data()['accepted'] );
		$this->assertTrue(
			$send(
				'latency',
				array(
					'attempt'    => 2,
					'latency_ms' => 200,
					'successful' => false,
				)
			)->get_data()['accepted']
		);
		$this->assertTrue( $send( 'abandon' )->get_data()['accepted'] );
		$this->assertFalse( $send( 'abandon' )->get_data()['accepted'] );
		$totals = $repository->aggregate( $id )[ $assignment['variant_id'] ];
		foreach ( array( 'views', 'starts', 'js_errors', 'client_validation_failures', 'abandonments' ) as $counter ) {
			$this->assertSame( 1, $totals[ $counter ] );
		}
		$this->assertSame( 2, $totals['attempts'] );
		$this->assertSame( 2, $totals['latency_samples'] );
		$this->assertSame( 300, $totals['latency_total_ms'] );
		$this->assertSame( 0, $totals['observed_submits'] );
		foreach ( array( 'duplicate_view', 'duplicate_start', 'duplicate_js_error', 'invalid_context_lifecycle', 'event_without_attempt', 'replayed_context_event' ) as $counter ) {
			$this->assertGreaterThan( 0, ( new CRODiagnostics() )->today()[ $counter ] );
		}
	}

	public function test_valid_but_unissued_context_and_expired_deleted_context_are_not_authorized() {
		global $wpdb;
		list( $repository, $id ) = $this->experiment();
		$assignment              = ( new CROConfigController( $repository ) )->config( $this->config_request() )->get_data()['assignments'][0];
		$signer                  = new ContextSigner();
		$context                 = $signer->verify( $assignment['context'] );
		$unknown                 = $signer->sign( $context );
		$controller              = new CROEventsController( $repository );
		$response                = $controller->ingest(
			$this->request(
				'/formhawk/v1/cro/events',
				array(
					'context' => $unknown,
					'type'    => 'view',
				)
			)
		);
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertSame( 1, ( new CRODiagnostics() )->today()['unknown_context'] );
		$claims        = CROToken::claims( $assignment['context'] );
		$claims['iat'] = time() - 7201;
		$claims['exp'] = time() - 1;
		$wpdb->update(
			Database::cro_contexts_table(),
			array(
				'issued_at_utc'  => gmdate( 'Y-m-d H:i:s', $claims['iat'] ),
				'expires_at_utc' => gmdate( 'Y-m-d H:i:s', $claims['exp'] ),
			),
			array( 'context_hash' => hash( 'sha256', $context['jti'] ) )
		);
		$response = $controller->ingest(
			$this->request(
				'/formhawk/v1/cro/events',
				array(
					'context' => CROToken::sign( $claims ),
					'type'    => 'view',
				)
			)
		);
		$this->assertSame( 403, $response->get_error_data()['status'] );
		$this->assertSame( 1, ( new CRODiagnostics() )->today()['expired_context'] );
		$this->assertSame( 1, ContextStore::cleanup() );
		$this->assertFalse( ( new ContextStore() )->is_issued( $context ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::cro_contexts_table() ) ) );
		$this->assertSame( 0, $repository->aggregate( $id )[ $assignment['variant_id'] ]['views'] );
	}

	public function test_aggregate_write_failure_rolls_back_admission_and_issuance() {
		global $wpdb;
		list( $repository, $id ) = $this->experiment();
		$assignment              = ( new CROConfigController( $repository ) )->config( $this->config_request() )->get_data()['assignments'][0];
		$context                 = ( new ContextSigner() )->verify( $assignment['context'] );
		$store                   = new ContextStore( $repository );
		$fail_aggregate          = static function ( $query ) {
			return 0 === strpos( $query, 'INSERT INTO `' . Database::experiment_daily_table() . '`' ) ? 'SELECT formhawk_test_deliberate_missing_column' : $query;
		};
		$old_errors              = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail_aggregate );
		try {
			$this->assertSame( 'storage_failures', $store->consume( $context, 'view' ) );
			$another = ( new ContextSigner() )->verify( ( new ContextSigner() )->sign( $context ) );
			$this->assertFalse( $store->issue( $another ) );
			$this->assertFalse( $store->is_issued( $another ) );
		} finally {
			remove_filter( 'query', $fail_aggregate );
			$wpdb->suppress_errors( $old_errors );
		}
		$this->assertSame( 'accepted', $store->consume( $context, 'view' ) );
		$totals = $repository->aggregate( $id )[ $assignment['variant_id'] ];
		$this->assertSame( 1, $totals['views'] );
		$this->assertSame( 1, $totals['assignments'] );
	}

	private function config_request() {
		return $this->request(
			'/formhawk/v1/cro/config',
			array(
				'page_path' => '/lead',
				'segment'   => 'desktop',
				'forms'     => array(
					array(
						'provider'         => 'cf7',
						'provider_form_id' => '77',
					),
				),
			)
		);
	}

	public function test_one_signed_assignment_cannot_replay_views_or_js_errors() {
		list( $repository, $experiment_id ) = $this->experiment();
		$config                             = ( new CROConfigController( $repository ) )->config(
			$this->request(
				'/formhawk/v1/cro/config',
				array(
					'page_path' => '/lead',
					'segment'   => 'desktop',
					'forms'     => array(
						array(
							'provider'         => 'cf7',
							'provider_form_id' => '77',
						),
					),
				)
			)
		);
		$assignment                         = $config->get_data()['assignments'][0];
		$controller                         = new CROEventsController( $repository );
		foreach ( array(
			'view'     => 100,
			'js_error' => 3,
		) as $type => $count ) {
			for ( $index = 0; $index < $count; ++$index ) {
				$controller->ingest(
					$this->request(
						'/formhawk/v1/cro/events',
						array(
							'context' => $assignment['context'],
							'type'    => $type,
						)
					)
				);
			}
		}
		$totals = $repository->aggregate( $experiment_id )[ $assignment['variant_id'] ];
		$this->assertSame( 1, $totals['views'] );
		$this->assertSame( 1, $totals['js_errors'] );
	}

	public function test_config_and_event_endpoints_issue_and_accept_only_structural_attribution() {
		list( $repository, $experiment_id ) = $this->experiment();
		$config                             = ( new CROConfigController( $repository ) )->config(
			$this->request(
				'/formhawk/v1/cro/config',
				array(
					'page_path' => '/lead',
					'segment'   => 'mobile',
					'forms'     => array(
						// Key order is not part of the public schema contract.
						array(
							'provider_form_id' => '77',
							'provider'         => 'cf7',
						),
					),
				)
			)
		);
		$this->assertInstanceOf( '\WP_REST_Response', $config );
		$assignment = $config->get_data()['assignments'][0];
		$this->assertSame( $experiment_id, $assignment['experiment_id'] );
		$this->assertArrayNotHasKey( 'field_values', $assignment );

		$response = ( new CROEventsController( $repository ) )->ingest(
			$this->request(
				'/formhawk/v1/cro/events',
				array(
					'context' => $assignment['context'],
					'type'    => 'view',
				)
			)
		);
		$this->assertInstanceOf( '\WP_REST_Response', $response );
		$this->assertSame( 1, $repository->aggregate( $experiment_id )[ $assignment['variant_id'] ]['views'] );
	}

	public function test_event_schema_rejects_tampering_unknown_keys_and_non_integer_latency() {
		list( $repository, $experiment_id ) = $this->experiment();
		$variants                           = $repository->variants( $experiment_id );
		$token                              = ( new ContextSigner() )->sign(
			array(
				'experiment_id'    => $experiment_id,
				'variant_id'       => absint( $variants[0]['id'] ),
				'form_id'          => absint( $repository->find( $experiment_id )['form_id'] ),
				'provider'         => 'cf7',
				'provider_form_id' => '77',
				'segment'          => 'desktop',
			)
		);

		$controller     = new CROEventsController( $repository );
		$tampered_token = ( 'A' === $token[0] ? 'B' : 'A' ) . substr( $token, 1 );

		$tampered = $controller->ingest(
			$this->request(
				'/formhawk/v1/cro/events',
				array(
					'context' => $tampered_token,
					'type'    => 'view',
				)
			)
		);
		$nested   = $controller->ingest(
			$this->request(
				'/formhawk/v1/cro/events',
				array(
					'context' => $token,
					'type'    => 'view',
					'visitor' => array( 'email' => 'forbidden@example.com' ),
				)
			)
		);
		$latency  = $controller->ingest(
			$this->request(
				'/formhawk/v1/cro/events',
				array(
					'context'    => $token,
					'type'       => 'latency',
					'latency_ms' => 1.5,
				)
			)
		);

		$this->assertSame( 'formhawk_cro_context', $tampered->get_error_code() );
		$this->assertSame( 'formhawk_cro_schema', $nested->get_error_code() );
		$this->assertSame( 'formhawk_cro_schema', $latency->get_error_code() );
		$this->assertSame( 3, ( new CRODiagnostics() )->today()['rejected_event_requests'] );
		$this->assertSame( array(), $repository->aggregate( $experiment_id ) );
	}

	public function test_signed_context_for_unknown_variant_is_rejected_and_cross_origin_config_is_denied() {
		list( $repository, $experiment_id ) = $this->experiment();
		$experiment                         = $repository->find( $experiment_id );
		$token                              = ( new ContextSigner() )->sign(
			array(
				'experiment_id'    => $experiment_id,
				'variant_id'       => 999999,
				'form_id'          => absint( $experiment['form_id'] ),
				'provider'         => 'cf7',
				'provider_form_id' => '77',
				'segment'          => 'desktop',
			)
		);
		$event                              = ( new CROEventsController( $repository ) )->ingest(
			$this->request(
				'/formhawk/v1/cro/events',
				array(
					'context' => $token,
					'type'    => 'view',
				)
			)
		);
		$this->assertSame( 'formhawk_cro_variant', $event->get_error_code() );

		$config_request = $this->request(
			'/formhawk/v1/cro/config',
			array(
				'page_path' => '/lead',
				'segment'   => 'desktop',
				'forms'     => array(),
			)
		);
		$config_request->set_header( 'origin', 'https://malicious.example' );
		$config = ( new CROConfigController( $repository ) )->config( $config_request );
		$this->assertSame( 'formhawk_cro_config_origin', $config->get_error_code() );
	}

	public function test_repository_enforces_one_active_experiment_per_form() {
		list( $repository, $experiment_id, $form_id ) = $this->experiment();
		$this->assertGreaterThan( 0, $experiment_id );
		$duplicate = $repository->create( $this->experiment_record( $form_id ), $this->variants() );
		$this->assertSame( 0, $duplicate );
	}

	private function experiment() {
		$forms      = new FormRepository();
		$identity   = $forms->resolve(
			array(
				'provider'         => 'cf7',
				'provider_form_id' => '77',
				'title'            => 'Lead form',
				'page_path'        => '/lead',
			)
		);
		$form_id    = absint( $identity['form_id'] );
		$repository = new ExperimentRepository();
		$repository->enable( $form_id, array( 'mode' => 'full' ) );
		$id = $repository->create( $this->experiment_record( $form_id ), $this->variants() );
		$repository->set_status( $id, ExperimentStatus::RUNNING, array( 'started_at_utc' => current_time( 'mysql', true ) ) );
		return array( $repository, $id, $form_id );
	}

	private function experiment_record( $form_id ) {
		return array(
			'form_id'        => $form_id,
			'type'           => 'submit_button',
			'status'         => ExperimentStatus::RUNNING,
			'hypothesis'     => 'A safe CTA may improve confirmed conversions.',
			'primary_metric' => 'confirmed_conversion',
			'evidence_level' => 'provider_confirmed',
			'policy'         => ( new OptimizationPolicy() )->for_form( array() ),
		);
	}

	private function variants() {
		return array(
			array(
				'name'          => 'Control',
				'mutation_type' => 'baseline',
				'config'        => array( 'mutations' => array() ),
			),
			array(
				'name'          => 'Variant',
				'mutation_type' => 'submit_button',
				'config'        => array(
					'mutations' => array(
						array(
							'type'   => 'submit_button',
							'config' => array( 'text' => 'Send request' ),
						),
					),
				),
			),
		);
	}

	private function request( $route, array $payload ) {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'origin', home_url( '/' ) );
		$request->set_body( wp_json_encode( $payload ) );
		return $request;
	}
}
