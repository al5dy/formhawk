<?php

namespace Formhawk\Tests\Unit;

use Formhawk\ROI\CausalEvidence;
use Formhawk\ROI\ConfidenceCalculator;
use Formhawk\ROI\FieldImpactCalculator;
use Formhawk\ROI\FieldValueModel;
use Formhawk\ROI\RecommendationEngine;
use PHPUnit\Framework\TestCase;

final class FieldROIMathTest extends TestCase {
	public function test_submission_loss_with_higher_revenue_per_visitor_is_money_maker() {
		$metrics                   = ( new FieldImpactCalculator() )->calculate(
			array(
				'visitors'       => 1000,
				'submissions'    => 120,
				'qualified'      => 25,
				'won'            => 5,
				'known'          => 100,
				'coverage'       => 0.83,
				'revenue_values' => array_fill( 0, 5, 100000 ),
			),
			array(
				'visitors'       => 1000,
				'submissions'    => 90,
				'qualified'      => 45,
				'won'            => 15,
				'known'          => 80,
				'coverage'       => 0.89,
				'revenue_values' => array_fill( 0, 15, 100000 ),
			)
		);
		$metrics['friction_score'] = 0.20;
		$decision                  = ( new RecommendationEngine() )->recommend( $metrics, ConfidenceCalculator::HIGH, CausalEvidence::EXPERIMENTAL );
		$this->assertLessThan( 0, $metrics['submission_impact'] );
		$this->assertGreaterThan( 0, $metrics['revenue']['impact_rpv_minor'] );
		$this->assertSame( 'money_maker', $decision['verdict'] );
		$this->assertSame( 'keep', $decision['recommendation'] );
	}

	public function test_high_friction_without_business_value_is_conversion_killer() {
		$decision = ( new RecommendationEngine() )->recommend(
			array(
				'friction_score'    => 0.22,
				'submission_impact' => -0.12,
				'qualified_impact'  => 0.01,
				'revenue_impact'    => -0.08,
			),
			ConfidenceCalculator::HIGH,
			CausalEvidence::EXPERIMENTAL
		);
		$this->assertSame( 'conversion_killer', $decision['verdict'] );
		$this->assertSame( 'make_optional', $decision['recommendation'] );
	}

	public function test_presentation_experiment_recommends_the_measured_safe_intervention() {
		$decision = ( new RecommendationEngine() )->recommend(
			array(
				'friction_score'    => 0.22,
				'submission_impact' => -0.12,
				'qualified_impact'  => 0.01,
				'revenue_impact'    => -0.08,
				'intervention'      => array( 'alternative' => 'move_later' ),
			),
			ConfidenceCalculator::HIGH,
			CausalEvidence::EXPERIMENTAL
		);
		$this->assertSame( 'conversion_killer', $decision['verdict'] );
		$this->assertSame( 'move_later', $decision['recommendation'] );
	}

	public function test_neutral_submission_with_quality_lift_is_free_value() {
		$decision = ( new RecommendationEngine() )->recommend(
			array(
				'friction_score'    => 0.01,
				'submission_impact' => 0.00,
				'qualified_impact'  => 0.20,
				'revenue_impact'    => null,
			),
			ConfidenceCalculator::HIGH,
			CausalEvidence::EXPERIMENTAL
		);
		$this->assertSame( 'free_value', $decision['verdict'] );
	}

	public function test_low_sample_and_incomplete_coverage_never_make_recommendation() {
		$confidence = ( new ConfidenceCalculator() )->calculate(
			array(
				'control_visitors' => 20,
				'variant_visitors' => 20,
				'outcomes'         => 2,
				'coverage'         => 0.10,
				'evidence_level'   => CausalEvidence::EXPERIMENTAL,
			)
		);
		$decision   = ( new RecommendationEngine() )->recommend(
			array(
				'friction_score'    => 0.9,
				'submission_impact' => -0.9,
				'revenue_impact'    => 10,
			),
			$confidence,
			CausalEvidence::EXPERIMENTAL
		);
		$this->assertSame( ConfidenceCalculator::INSUFFICIENT, $confidence );
		$this->assertSame( 'insufficient_data', $decision['recommendation'] );
	}

	public function test_seeded_revenue_model_is_deterministic_and_uses_actual_totals() {
		$model   = new FieldValueModel();
		$control = array( 10000, 12000, 11000, 100000000 );
		$variant = array_fill( 0, 20, 15000 );
		$first   = $model->compare( $control, 10000, $variant, 10000, 77 );
		$second  = $model->compare( $control, 10000, $variant, 10000, 77 );
		$this->assertSame( $first, $second );
		$this->assertSame( array_sum( $control ), $first['control_revenue_minor'] );
		$this->assertLessThan( 100000000, $first['winsor_limit_minor'] );
	}
}
