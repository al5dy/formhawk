<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\FormRepository;
use Formhawk\CRO\Attribution\RequestContext;
use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\MinimumForm\FieldDependencyGraph;
use Formhawk\MinimumForm\MinimumFormRepository;
use Formhawk\Outcomes\OutcomeAttribution;
use Formhawk\Outcomes\OutcomeRepository;
use Formhawk\Outcomes\SubmissionContext;
use Formhawk\Tests\Fixtures\IsolatedStorageTestCase;

final class MinimumFormRepositoryIntegrationTest extends IsolatedStorageTestCase {
	public function test_run_has_one_immutable_versioned_baseline_and_one_active_semantic_experiment() {
		$repository = new MinimumFormRepository();
		$form_id    = $this->form_id();
		$schema     = $this->schema();
		$run_id     = $repository->start( $form_id, $schema, array( 'mode' => 'full' ) );

		$this->assertGreaterThan( 0, $run_id );
		$this->assertSame( $run_id, $repository->start( $form_id, $schema, array( 'mode' => 'full' ) ) );
		$run      = $repository->find_run( $run_id )->data();
		$original = $repository->baseline( $run['original_baseline_id'] );
		$this->assertNotNull( $original );
		$this->assertSame( 1, absint( $original->data()['version'] ) );
		$this->assertSame( array(), $original->mutations() );

		$this->assertTrue( $repository->attach_experiment( $run_id, 901 ) );
		$this->assertFalse( $repository->attach_experiment( $run_id, 902 ) );
		$new_id = $repository->create_baseline(
			$repository->find_run( $run_id )->data(),
			$original,
			$schema,
			array(
				array(
					'type'   => ExperimentType::REMOVE_FIELD,
					'config' => array(
						'field_key' => 'company',
						'safety'    => 'safe',
					),
				),
			),
			'revenue_per_visitor',
			901
		);
		$this->assertGreaterThan( 0, $new_id );
		$this->assertSame( $new_id, $repository->create_baseline( $repository->find_run( $run_id )->data(), $original, $schema, $repository->baseline( $new_id )->mutations(), 'revenue_per_visitor', 901 ) );
		$new = $repository->baseline( $new_id );
		$this->assertSame( 2, absint( $new->data()['version'] ) );
		$this->assertSame( $original->data(), $repository->baseline( $run['original_baseline_id'] )->data(), 'Creating a winner must not rewrite its parent genome.' );
	}

	public function test_schema_mismatch_invalidates_baseline_and_disable_preserves_history() {
		$repository = new MinimumFormRepository();
		$form_id    = $this->form_id();
		$schema     = $this->schema();
		$run_id     = $repository->start( $form_id, $schema, array() );
		$run        = $repository->find_run( $run_id )->data();
		$baseline   = $repository->baseline( $run['current_baseline_id'] );

		$this->assertTrue( $baseline->matches_schema( $schema['schema_fingerprint'], $schema['dependency_hash'] ) );
		$this->assertFalse( $baseline->matches_schema( hash( 'sha256', 'provider-edited' ), $schema['dependency_hash'] ) );
		$this->assertTrue( $repository->set_status( $run_id, 'revalidation_required', 'schema_drift' ) );
		$this->assertSame( 0, $repository->attributable_baseline_id( $form_id ), 'An unvalidated baseline must never receive new outcomes.' );
		$this->assertTrue( $repository->set_status( $run_id, 'disabled', 'manual_disable' ) );
		$this->assertNull( $repository->active_run( $form_id ) );
		$this->assertSame( $run_id, absint( $repository->latest_run( $form_id )->data()['id'] ) );
	}

	public function test_business_outcome_submission_is_attributed_to_current_baseline_without_field_values() {
		$form_id     = $this->form_id();
		$repository  = new MinimumFormRepository();
		$run_id      = $repository->start( $form_id, $this->schema(), array() );
		$run         = $repository->find_run( $run_id )->data();
		$submissions = new SubmissionContext();
		$submissions->set_verified( 'fh_1234567890123456789012' );
		$attribution = new OutcomeAttribution( $submissions, new RequestContext(), new FormRepository(), new OutcomeRepository() );

		$submission = $attribution->attribute( 'wpforms', '55', 'Minimum Form', '/minimum-form', array( 'provider_entry_id' => 'entry-77' ) );

		$this->assertIsArray( $submission );
		$this->assertSame( absint( $run['current_baseline_id'] ), absint( $submission['minimum_form_baseline_id'] ) );
		$this->assertArrayNotHasKey( 'field_value', $submission );
	}

	private function form_id() {
		$identity = ( new FormRepository() )->resolve(
			array(
				'provider'         => 'wpforms',
				'provider_form_id' => '55',
				'title'            => 'Minimum Form',
				'page_path'        => '/minimum-form',
			)
		);
		return absint( $identity['form_id'] );
	}

	private function schema() {
		$fields = array(
			array(
				'key'            => 'email',
				'normalized_key' => 'email',
				'field_type'     => 'email',
				'required'       => true,
				'position'       => 0,
			),
			array(
				'key'            => 'company',
				'normalized_key' => 'company',
				'field_type'     => 'text',
				'required'       => false,
				'position'       => 1,
			),
		);
		$graph  = new FieldDependencyGraph( $fields );
		return array(
			'fields'                 => $fields,
			'dependency_graph'       => $graph,
			'dependency_hash'        => $graph->hash(),
			'schema_fingerprint'     => hash( 'sha256', (string) wp_json_encode( $fields ) ),
			'provider_schema_source' => 'test',
		);
	}
}
