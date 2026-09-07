<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\Attribution\ContextSigner;
use Formhawk\CRO\CRODiagnostics;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\CRO\Experiments\ExperimentStatus;
use Formhawk\CRO\Http\CROConfigController;
use Formhawk\CRO\Http\CROEventsController;
use Formhawk\CRO\OptimizationPolicy;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class CROHttpIntegrationTest extends IsolatedStorageTestCase {
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
