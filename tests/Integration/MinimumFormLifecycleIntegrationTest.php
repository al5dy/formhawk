<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\ExperimentRepository;
use Formhawk\CRO\Experiments\ExperimentStatus;
use Formhawk\Domain\ProviderCatalog;
use Formhawk\Infrastructure\Database;
use Formhawk\Integrations\IntegrationRegistry;
use Formhawk\MinimumForm\MinimumFormManager;
use Formhawk\MinimumForm\MinimumFormRepository;
use Formhawk\MinimumForm\ProviderCapabilityMatrix;
use Formhawk\MinimumForm\ProviderSchemaInspector;
use Formhawk\Tests\Fixtures\IntegrationDouble;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class MinimumFormLifecycleIntegrationTest extends IsolatedStorageTestCase {
	private $schema_filter     = null;
	private $capability_filter = null;

	protected function tearDown(): void {
		if ( $this->schema_filter ) {
			remove_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		}
		if ( $this->capability_filter ) {
			remove_filter( 'formhawk_minimum_form_provider_capabilities', $this->capability_filter );
		}
		parent::tearDown();
	}

	public function test_sequential_semantic_loop_promotes_rejects_and_stops_at_a_validated_minimum() {
		global $wpdb;

		$form_id                 = $this->seed_form();
		$schema                  = $this->schema_fields();
		$this->schema_filter     = static function () use ( $schema ) {
			return array(
				'fields'                 => $schema,
				'provider_schema_source' => 'test_provider_definition',
			);
		};
		$this->capability_filter = static function ( $capabilities ) {
			$capabilities[ ProviderCapabilityMatrix::MAKE_OPTIONAL ] = true;
			return $capabilities;
		};
		add_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		add_filter( 'formhawk_minimum_form_provider_capabilities', $this->capability_filter );
		$this->seed_field_roi( $form_id, $schema );

		$experiments = new ExperimentRepository();
		$repository  = new MinimumFormRepository();
		$adapter     = new IntegrationDouble(
			'wpforms',
			true,
			array(
				ProviderCatalog::CAP_REMOVE_FIELD,
				ProviderCatalog::CAP_MAKE_OPTIONAL,
				ProviderCatalog::CAP_SERVER_SUCCESS,
				ProviderCatalog::CAP_DEPENDENCY_GRAPH,
			)
		);
		$manager     = new MinimumFormManager( $repository, $experiments, new FormRepository(), null, new ProviderCapabilityMatrix( new IntegrationRegistry( array( $adapter ) ) ) );
		$run_id      = $manager->start(
			$form_id,
			array(
				'mode'      => 'full',
				'objective' => 'confirmed_conversion',
			)
		);
		$this->assertGreaterThan( 0, $run_id );

		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$company = $experiments->active_for_form( $form_id );
		$this->assertSame( 'company', $company['policy']['opportunity']['field_key'] );
		$this->seed_binary_result( $experiments, $company, 80, 125 );
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$this->assertSame( ExperimentStatus::PROMOTED_MONITORING, $experiments->find( $company['id'] )['status'] );
		$company_variants = $experiments->variants( $company['id'] );
		$promoted_profile = $repository->profile( $form_id )->data();
		$this->assertSame(
			absint( $promoted_profile['original_baseline_id'] ),
			$repository->attributable_baseline_id(
				$form_id,
				array(
					'experiment_id' => $company['id'],
					'variant_id'    => $company_variants[0]['id'],
				)
			),
			'A late control callback must retain the baseline from which it was assigned.'
		);
		$this->assertSame(
			absint( $promoted_profile['current_baseline_id'] ),
			$repository->attributable_baseline_id(
				$form_id,
				array(
					'experiment_id' => $company['id'],
					'variant_id'    => $company_variants[1]['id'],
				)
			),
			'A promoted variant callback must receive the winning immutable baseline.'
		);
		$this->finish_monitoring( $manager, $experiments, $form_id, $company['id'] );
		$history = $repository->decisions( $run_id );
		$this->assertCount( 1, $history );
		$this->assertSame( 'company', $history[0]['normalized_key'] );
		$this->assertSame( 'remove_field', $history[0]['mutation_type'] );
		$wpdb->update(
			Database::field_roi_results_table(),
			array(
				'verdict'    => 'unknown',
				'confidence' => 'insufficient',
			),
			array( 'field_definition_id' => $history[0]['field_definition_id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$phone = $experiments->active_for_form( $form_id );
		$this->assertSame( 'phone', $phone['policy']['opportunity']['field_key'] );
		$this->seed_binary_result( $experiments, $phone, 120, 60 );
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$this->assertSame( ExperimentStatus::REJECTED, $experiments->find( $phone['id'] )['status'] );

		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$message = $experiments->active_for_form( $form_id );
		$this->assertSame( 'message', $message['policy']['opportunity']['field_key'] );
		$this->assertSame( 'make_optional', $message['type'] );
		$this->seed_binary_result( $experiments, $message, 80, 125 );
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$this->finish_monitoring( $manager, $experiments, $form_id, $message['id'] );

		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$profile = $repository->profile( $form_id )->data();
		$this->assertSame( 'optimized', $profile['status'] );
		$this->assertSame( 3, absint( $profile['current_field_count'] ) );
		$this->assertCount( 3, $profile['decisions'] );
		$this->assertCount( 2, $profile['current_baseline']->mutations() );
		$this->assertSame( 1, absint( $profile['original_baseline']->data()['version'] ) );
		$this->assertSame( 3, absint( $profile['current_baseline']->data()['version'] ) );
		$this->assertNull( $experiments->active_for_form( $form_id ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id=%d AND status=%s', Database::minimum_form_runs_table(), $form_id, 'revalidation_required' ) ) );
	}

	public function test_schema_drift_routes_to_original_and_requires_an_explicit_new_baseline() {
		$form_id             = $this->seed_form();
		$schema              = $this->schema_fields();
		$this->schema_filter = static function () use ( $schema ) {
			return array(
				'fields'                 => $schema,
				'provider_schema_source' => 'test_provider_definition',
			);
		};
		add_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		$this->seed_field_roi( $form_id, $schema );
		$experiments = new ExperimentRepository();
		$repository  = new MinimumFormRepository();
		$adapter     = new IntegrationDouble(
			'wpforms',
			true,
			array(
				ProviderCatalog::CAP_REMOVE_FIELD,
				ProviderCatalog::CAP_SERVER_SUCCESS,
				ProviderCatalog::CAP_DEPENDENCY_GRAPH,
			)
		);
		$manager     = new MinimumFormManager( $repository, $experiments, new FormRepository(), null, new ProviderCapabilityMatrix( new IntegrationRegistry( array( $adapter ) ) ) );
		$run_id      = $manager->start(
			$form_id,
			array(
				'mode'      => 'full',
				'objective' => 'confirmed_conversion',
			)
		);
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$experiment = $experiments->active_for_form( $form_id );
		$this->assertNotNull( $experiment );

		remove_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		$schema[]            = array(
			'key'               => 'service',
			'normalized_key'    => 'service',
			'field_type'        => 'select',
			'required'          => false,
			'provider_required' => false,
			'position'          => 4,
		);
		$this->schema_filter = static function () use ( $schema ) {
			return array(
				'fields'                 => $schema,
				'provider_schema_source' => 'test_provider_definition',
			);
		};
		add_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );

		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$this->assertSame( 'revalidation_required', $repository->find_run( $run_id )->data()['status'] );
		$this->assertSame( ExperimentStatus::PAUSED_GUARDRAIL, $experiments->find( $experiment['id'] )['status'] );
		$this->assertSame( array(), $experiments->settings( $form_id )['baseline'] );
		$this->assertNull( $experiments->runtime_for_identity( 'wpforms', '955', '/minimum-sequential' ), 'Schema drift must fail open to the untouched provider form.' );

		$new_run_id = $manager->revalidate( $form_id );
		$this->assertGreaterThan( $run_id, $new_run_id );
		$this->assertSame( 'superseded', $repository->find_run( $run_id )->data()['status'] );
		$this->assertSame( 'collecting', $repository->find_run( $new_run_id )->data()['status'] );
		$this->assertSame( 5, absint( $repository->find_run( $new_run_id )->data()['original_field_count'] ) );
	}

	public function test_schema_fingerprint_changes_when_safety_relevant_metadata_changes() {
		$form_id             = $this->seed_form();
		$schema              = $this->schema_fields();
		$this->schema_filter = static function () use ( $schema ) {
			return array(
				'fields'                 => $schema,
				'provider_schema_source' => 'test_provider_definition',
			);
		};
		add_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		$inspector = new ProviderSchemaInspector();
		$form      = array(
			'id'               => $form_id,
			'provider'         => 'generic',
			'provider_form_id' => '955',
		);
		$original  = $inspector->inspect( $form );

		remove_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		$schema[1]['label']               = 'Medical condition';
		$schema[1]['integration_mapping'] = true;
		$this->schema_filter              = static function () use ( $schema ) {
			return array(
				'fields'                 => $schema,
				'provider_schema_source' => 'test_provider_definition',
			);
		};
		add_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		$changed = $inspector->inspect( $form );

		$this->assertNotSame( $original['schema_fingerprint'], $changed['schema_fingerprint'] );
		$this->assertSame( $original['dependency_hash'], $changed['dependency_hash'], 'The full schema fingerprint must cover safety metadata beyond graph edges.' );
	}

	public function test_disable_restores_the_preexisting_autopilot_runtime_exactly() {
		$form_id             = $this->seed_form();
		$schema              = $this->schema_fields();
		$this->schema_filter = static function () use ( $schema ) {
			return array(
				'fields'                 => $schema,
				'provider_schema_source' => 'test_provider_definition',
			);
		};
		add_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		$experiments = new ExperimentRepository();
		$this->assertTrue(
			$experiments->enable(
				$form_id,
				array(
					'mode'                     => 'observe',
					'aggressiveness'           => 'conservative',
					'optimization_objective'   => 'submissions',
					'max_experimental_traffic' => 20,
					'min_duration_days'        => 9,
					'min_conversions'          => 80,
					'lead_value'               => 42,
					'currency'                 => 'EUR',
				)
			)
		);
		$first_baseline = array(
			array(
				'type'   => 'submit_button',
				'config' => array( 'text' => 'First baseline' ),
			),
		);
		$prior_baseline = array(
			array(
				'type'   => 'submit_button',
				'config' => array( 'text' => 'Prior baseline' ),
			),
		);
		$this->assertTrue( $experiments->promote_baseline( $form_id, $first_baseline ) );
		$this->assertTrue( $experiments->promote_baseline( $form_id, $prior_baseline ) );
		$before = $experiments->settings( $form_id );

		$repository = new MinimumFormRepository();
		$adapter    = new IntegrationDouble(
			'wpforms',
			true,
			array(
				ProviderCatalog::CAP_REMOVE_FIELD,
				ProviderCatalog::CAP_SERVER_SUCCESS,
				ProviderCatalog::CAP_DEPENDENCY_GRAPH,
			)
		);
		$manager    = new MinimumFormManager( $repository, $experiments, new FormRepository(), null, new ProviderCapabilityMatrix( new IntegrationRegistry( array( $adapter ) ) ) );
		$run_id     = $manager->start( $form_id, array( 'mode' => 'full' ) );
		$this->assertGreaterThan( 0, $run_id );
		$this->assertTrue( $manager->disable( $form_id ) );
		$after = $experiments->settings( $form_id );

		foreach ( array( 'mode', 'state', 'aggressiveness', 'optimization_objective', 'max_experimental_traffic', 'min_duration_days', 'min_conversions', 'lead_value', 'currency', 'baseline', 'previous_baseline' ) as $key ) {
			$this->assertSame( $before[ $key ], $after[ $key ], 'Pre-existing Autopilot setting was not restored: ' . $key );
		}
		$this->assertSame( 'disabled', $repository->find_run( $run_id )->data()['status'] );
	}

	public function test_manual_rollback_restores_parent_baseline_and_keeps_append_only_history() {
		$form_id                 = $this->seed_form();
		$schema                  = $this->schema_fields();
		$this->schema_filter     = static function () use ( $schema ) {
			return array(
				'fields'                 => $schema,
				'provider_schema_source' => 'test_provider_definition',
			);
		};
		$this->capability_filter = static function ( $capabilities ) {
			$capabilities[ ProviderCapabilityMatrix::MAKE_OPTIONAL ] = true;
			return $capabilities;
		};
		add_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		add_filter( 'formhawk_minimum_form_provider_capabilities', $this->capability_filter );
		$this->seed_field_roi( $form_id, $schema );

		$experiments = new ExperimentRepository();
		$repository  = new MinimumFormRepository();
		$adapter     = new IntegrationDouble(
			'wpforms',
			true,
			array(
				ProviderCatalog::CAP_REMOVE_FIELD,
				ProviderCatalog::CAP_MAKE_OPTIONAL,
				ProviderCatalog::CAP_SERVER_SUCCESS,
				ProviderCatalog::CAP_DEPENDENCY_GRAPH,
			)
		);
		$manager     = new MinimumFormManager( $repository, $experiments, new FormRepository(), null, new ProviderCapabilityMatrix( new IntegrationRegistry( array( $adapter ) ) ) );
		$run_id      = $manager->start(
			$form_id,
			array(
				'mode'      => 'full',
				'objective' => 'confirmed_conversion',
			)
		);
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$experiment = $experiments->active_for_form( $form_id );
		$this->seed_binary_result( $experiments, $experiment, 80, 125 );
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$promoted = $repository->profile( $form_id )->data();
		$this->assertNotSame( absint( $promoted['original_baseline_id'] ), absint( $promoted['current_baseline_id'] ) );
		$this->finish_monitoring( $manager, $experiments, $form_id, $experiment['id'] );

		$this->assertTrue( $manager->restore_previous( $form_id ) );
		$restored = $repository->profile( $form_id )->data();
		$this->assertSame( absint( $restored['original_baseline_id'] ), absint( $restored['current_baseline_id'] ) );
		$this->assertSame( ExperimentStatus::ROLLED_BACK, $experiments->find( $experiment['id'] )['status'] );
		$this->assertSame( array(), $experiments->settings( $form_id )['baseline'] );
		$this->assertCount( 2, $restored['decisions'] );
		$this->assertSame( array( 'rollback', 'promote' ), array_column( $restored['decisions'], 'decision' ) );
		$this->assertSame( array( 'manual_rollback', 'winner' ), array_column( $experiments->history( $form_id ), 'decision' ) );
		$this->assertSame( $run_id, absint( $restored['id'] ) );
	}

	public function test_post_promotion_conversion_regression_rolls_back_automatically() {
		global $wpdb;

		$form_id             = $this->seed_form();
		$schema              = $this->schema_fields();
		$this->schema_filter = static function () use ( $schema ) {
			return array(
				'fields'                 => $schema,
				'provider_schema_source' => 'test_provider_definition',
			);
		};
		add_filter( 'formhawk_minimum_form_provider_schema', $this->schema_filter );
		$this->seed_field_roi( $form_id, $schema );

		$experiments = new ExperimentRepository();
		$repository  = new MinimumFormRepository();
		$adapter     = new IntegrationDouble(
			'wpforms',
			true,
			array(
				ProviderCatalog::CAP_REMOVE_FIELD,
				ProviderCatalog::CAP_SERVER_SUCCESS,
				ProviderCatalog::CAP_DEPENDENCY_GRAPH,
			)
		);
		$manager     = new MinimumFormManager( $repository, $experiments, new FormRepository(), null, new ProviderCapabilityMatrix( new IntegrationRegistry( array( $adapter ) ) ) );
		$run_id      = $manager->start(
			$form_id,
			array(
				'mode'      => 'full',
				'objective' => 'confirmed_conversion',
			)
		);
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$experiment = $experiments->active_for_form( $form_id );
		$this->seed_binary_result( $experiments, $experiment, 80, 125 );
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$variants = $experiments->variants( $experiment['id'] );
		$wpdb->update(
			Database::experiments_table(),
			array( 'ended_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ),
			array( 'id' => $experiment['id'] ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertTrue(
			$experiments->increment(
				$experiment['id'],
				$variants[1]['id'],
				'desktop',
				array(
					'assignments' => 9000,
					'views'       => 9000,
				)
			)
		);

		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$profile = $repository->profile( $form_id )->data();
		$this->assertSame( ExperimentStatus::ROLLED_BACK, $experiments->find( $experiment['id'] )['status'] );
		$this->assertSame( absint( $profile['original_baseline_id'] ), absint( $profile['current_baseline_id'] ) );
		$this->assertSame( array(), $experiments->settings( $form_id )['baseline'] );
		$this->assertCount( 2, $profile['decisions'] );
		$this->assertSame( 'rollback', $profile['decisions'][0]['decision'] );
		$this->assertStringContainsString( 'post_promotion_', $profile['decisions'][0]['reason'] );
		$this->assertSame( $run_id, absint( $profile['id'] ) );
	}

	private function seed_binary_result( ExperimentRepository $repository, array $experiment, $control_successes, $variant_successes ) {
		global $wpdb;
		$variants = $repository->variants( $experiment['id'] );
		foreach ( array( $control_successes, $variant_successes ) as $index => $successes ) {
			$repository->increment(
				$experiment['id'],
				$variants[ $index ]['id'],
				'desktop',
				array(
					'assignments'         => 1000,
					'views'               => 1000,
					'confirmed_successes' => $successes,
				)
			);
		}
		$wpdb->update(
			Database::experiments_table(),
			array( 'started_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ),
			array( 'id' => $experiment['id'] ),
			array( '%s' ),
			array( '%d' )
		);
	}

	private function finish_monitoring( MinimumFormManager $manager, ExperimentRepository $repository, $form_id, $experiment_id ) {
		global $wpdb;
		$wpdb->update(
			Database::experiments_table(),
			array( 'ended_at_utc' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ),
			array( 'id' => $experiment_id ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertTrue( $manager->evaluate_form( $form_id ) );
		$this->assertSame( ExperimentStatus::COMPLETED, $repository->find( $experiment_id )['status'] );
	}

	private function seed_form() {
		global $wpdb;
		$identity = ( new FormRepository() )->resolve(
			array(
				'provider'         => 'wpforms',
				'provider_form_id' => '955',
				'title'            => 'Sequential Minimum Form',
				'page_path'        => '/minimum-sequential',
			)
		);
		$wpdb->insert(
			Database::form_versions_table(),
			array(
				'form_id'        => $identity['form_id'],
				'fingerprint'    => hash( 'sha256', 'minimum-sequential' ),
				'schema_json'    => wp_json_encode( $this->schema_fields() ),
				'created_at_utc' => current_time( 'mysql', true ),
			)
		);
		return absint( $identity['form_id'] );
	}

	private function seed_field_roi( $form_id, array $fields ) {
		global $wpdb;
		$profiles = array(
			'email'   => array( 'money_maker', 'high', 0.02, 0.20 ),
			'company' => array( 'conversion_killer', 'high', 0.50, -0.10 ),
			'phone'   => array( 'conversion_killer', 'high', 0.40, -0.05 ),
			'message' => array( 'neutral', 'medium', 0.10, 0.00 ),
		);
		foreach ( $fields as $field ) {
			$wpdb->insert(
				Database::field_definitions_table(),
				array(
					'form_id'           => $form_id,
					'provider_field_id' => $field['key'],
					'normalized_key'    => $field['key'],
					'label'             => ucfirst( $field['key'] ),
					'field_type'        => $field['field_type'],
					'required'          => $field['required'] ? 1 : 0,
					'position'          => $field['position'],
					'first_seen_utc'    => '2026-06-01 00:00:00',
					'last_seen_utc'     => '2026-09-01 00:00:00',
				)
			);
			$field_id = absint( $wpdb->insert_id );
			$profile  = $profiles[ $field['key'] ];
			$wpdb->insert(
				Database::field_roi_results_table(),
				array(
					'field_definition_id' => $field_id,
					'period_start'        => '2026-06-01',
					'period_end'          => '2026-09-01',
					'currency'            => 'USD',
					'evidence_level'      => 'experimental',
					'confidence'          => $profile[1],
					'verdict'             => $profile[0],
					'recommendation'      => 'test',
					'score'               => 70,
					'metrics_json'        => wp_json_encode(
						array(
							'friction_score'   => $profile[2],
							'revenue_impact'   => $profile[3],
							'qualified_impact' => 0,
							'sample_size'      => 4000,
						)
					),
					'model_version'       => 'test',
					'evaluated_at_utc'    => '2026-09-01 00:00:00',
					'data_through_utc'    => '2026-09-01 00:00:00',
				)
			);
		}
	}

	private function schema_fields() {
		return array(
			array(
				'key'               => 'email',
				'normalized_key'    => 'email',
				'label'             => 'Email',
				'field_type'        => 'email',
				'required'          => true,
				'provider_required' => true,
				'position'          => 0,
			),
			array(
				'key'               => 'company',
				'normalized_key'    => 'company',
				'label'             => 'Company',
				'field_type'        => 'text',
				'required'          => false,
				'provider_required' => false,
				'position'          => 1,
			),
			array(
				'key'               => 'phone',
				'normalized_key'    => 'phone',
				'label'             => 'Phone',
				'field_type'        => 'tel',
				'required'          => false,
				'provider_required' => false,
				'position'          => 2,
			),
			array(
				'key'               => 'message',
				'normalized_key'    => 'message',
				'label'             => 'Message',
				'field_type'        => 'textarea',
				'required'          => true,
				'provider_required' => true,
				'position'          => 3,
			),
		);
	}
}
