<?php

namespace Formhawk\Tests\Unit;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\MinimumForm\FieldDependencyGraph;
use Formhawk\MinimumForm\FieldSafety;
use Formhawk\MinimumForm\FieldSafetyClassifier;
use Formhawk\MinimumForm\MinimumFormCandidateSelector;
use Formhawk\MinimumForm\ProviderCapabilityMatrix;
use PHPUnit\Framework\TestCase;

final class MinimumFormCandidateSelectorTest extends TestCase {
	private function capabilities() {
		return array(
			ProviderCapabilityMatrix::REMOVE_FIELD  => true,
			ProviderCapabilityMatrix::MAKE_OPTIONAL => true,
			ProviderCapabilityMatrix::MAKE_REQUIRED => false,
		);
	}

	private function field( array $overrides = array() ) {
		return array_merge(
			array(
				'id'             => 10,
				'normalized_key' => 'company',
				'label'          => 'Company',
				'field_type'     => 'text',
				'required'       => 0,
				'verdict'        => 'conversion_killer',
				'confidence'     => 'high',
				'metrics'        => array(
					'friction_score'   => 0.24,
					'revenue_impact'   => -0.04,
					'qualified_impact' => 0.01,
					'sample_size'      => 2800,
				),
			),
			$overrides
		);
	}

	public function test_high_confidence_safe_conversion_killer_is_remove_candidate() {
		$field     = $this->field();
		$candidate = ( new MinimumFormCandidateSelector() )->select( array( $field ), new FieldDependencyGraph( array( $field ) ), $this->capabilities() );
		$this->assertNotNull( $candidate );
		$this->assertSame( ExperimentType::REMOVE_FIELD, $candidate->data()['mutation_type'] );
	}

	public function test_money_maker_is_kept_even_when_friction_is_high() {
		$field = $this->field( array( 'verdict' => 'money_maker' ) );
		$this->assertNull( ( new MinimumFormCandidateSelector() )->select( array( $field ), new FieldDependencyGraph( array( $field ) ), $this->capabilities() ) );
	}

	public function test_low_confidence_never_starts_automatic_experiment() {
		$field = $this->field( array( 'confidence' => 'low' ) );
		$this->assertNull( ( new MinimumFormCandidateSelector() )->select( array( $field ), new FieldDependencyGraph( array( $field ) ), $this->capabilities() ) );
	}

	public function test_medium_confidence_uses_optional_before_removal_when_supported() {
		$field     = $this->field(
			array(
				'confidence' => 'medium',
				'required'   => 1,
			)
		);
		$candidate = ( new MinimumFormCandidateSelector() )->select( array( $field ), new FieldDependencyGraph( array( $field ) ), $this->capabilities() );
		$this->assertNotNull( $candidate );
		$this->assertSame( ExperimentType::MAKE_OPTIONAL, $candidate->data()['mutation_type'] );
	}

	public function test_required_fields_are_never_removed_and_forbidden_fields_are_never_candidates() {
		$required         = $this->field( array( 'required' => 1 ) );
		$password         = $this->field(
			array(
				'id'             => 11,
				'normalized_key' => 'password',
				'label'          => 'Password',
				'field_type'     => 'password',
			)
		);
		$selector         = new MinimumFormCandidateSelector();
		$without_optional = $this->capabilities();
		$without_optional[ ProviderCapabilityMatrix::MAKE_OPTIONAL ] = false;
		$this->assertNull( $selector->select( array( $required ), new FieldDependencyGraph( array( $required ) ), $without_optional ) );
		$this->assertNull( $selector->select( array( $password ), new FieldDependencyGraph( array( $password ) ), $this->capabilities() ) );
		$this->assertSame( FieldSafety::FORBIDDEN, ( new FieldSafetyClassifier() )->classify( $password )['safety'] );
	}

	public function test_dependency_blocks_removal_and_unknown_collects_more_data() {
		$field     = $this->field( array( 'controls_visibility_of' => array( 'budget' ) ) );
		$dependent = $this->field(
			array(
				'id'             => 12,
				'normalized_key' => 'budget',
				'label'          => 'Budget',
				'depends_on'     => array( 'company' ),
			)
		);
		$unknown   = $this->field( array( 'verdict' => 'unknown' ) );
		$selector  = new MinimumFormCandidateSelector();
		$this->assertNull( $selector->select( array( $field, $dependent ), new FieldDependencyGraph( array( $field, $dependent ) ), $this->capabilities() ) );
		$this->assertNull( $selector->select( array( $unknown ), new FieldDependencyGraph( array( $unknown ) ), $this->capabilities() ) );
	}

	public function test_hidden_technical_field_is_protected() {
		$field = $this->field(
			array(
				'normalized_key'   => 'routing_code',
				'label'            => 'Routing code',
				'hidden_technical' => true,
			)
		);
		$this->assertSame( FieldSafety::PROTECTED, ( new FieldSafetyClassifier() )->classify( $field )['safety'] );
		$this->assertNull( ( new MinimumFormCandidateSelector() )->select( array( $field ), new FieldDependencyGraph( array( $field ) ), $this->capabilities() ) );
	}

	public function test_completed_field_mutation_is_not_repeated() {
		$field   = $this->field();
		$history = array(
			array(
				'field_definition_id' => 10,
				'mutation_type'       => ExperimentType::REMOVE_FIELD,
			),
		);
		$this->assertNull( ( new MinimumFormCandidateSelector() )->select( array( $field ), new FieldDependencyGraph( array( $field ) ), $this->capabilities(), $history ) );
	}
}
