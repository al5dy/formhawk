<?php

namespace Formhawk\Tests\Unit;

use Formhawk\ROI\CausalEvidence;
use Formhawk\ROI\ConfidenceCalculator;
use Formhawk\ROI\ExperimentIntegrity;
use Formhawk\ROI\FieldImpactCalculator;
use Formhawk\ROI\FieldValueModel;
use Formhawk\ROI\OutcomeValueResolver;
use Formhawk\ROI\RecommendationEngine;
use PHPUnit\Framework\TestCase;

final class FieldROIStatisticalRegressionTest extends TestCase {
	public function test_seeded_no_effect_revenue_stays_undecided() {
		$values = array_fill( 0, 80, 25000 );
		$result = ( new FieldValueModel() )->compare( $values, 4000, $values, 4000, 19 );
		$this->assertEquals( 0.0, $result['impact_rpv_minor'] );
		$this->assertGreaterThan( 0.25, $result['probability_beneficial'] );
		$this->assertLessThan( 0.75, $result['probability_beneficial'] );
	}

	public function test_positive_and_negative_revenue_effects_keep_their_direction() {
		$model    = new FieldValueModel();
		$positive = $model->compare( array_fill( 0, 60, 10000 ), 3000, array_fill( 0, 90, 10000 ), 3000, 23 );
		$negative = $model->compare( array_fill( 0, 90, 10000 ), 3000, array_fill( 0, 60, 10000 ), 3000, 23 );
		$this->assertGreaterThan( 0, $positive['impact_rpv_minor'] );
		$this->assertLessThan( 0, $negative['impact_rpv_minor'] );
	}

	public function test_unequal_traffic_uses_per_view_value_not_raw_revenue() {
		$result = ( new FieldValueModel() )->compare( array_fill( 0, 50, 10000 ), 1000, array_fill( 0, 75, 10000 ), 3000, 29 );
		$this->assertEquals( 500.0, $result['control_rpv_minor'] );
		$this->assertEquals( 250.0, $result['variant_rpv_minor'] );
		$this->assertLessThan( 0, $result['impact_rpv_minor'] );
	}

	public function test_single_extreme_deal_does_not_replace_actual_total_or_robust_cap() {
		$result = ( new FieldValueModel() )->compare( array( 10000, 10000, 10000, 1000000000 ), 5000, array_fill( 0, 20, 20000 ), 5000, 31 );
		$this->assertSame( 1000030000, $result['control_revenue_minor'] );
		$this->assertLessThan( 1000000000, $result['winsor_limit_minor'] );
	}

	public function test_single_extreme_variant_deal_does_not_create_a_premature_winner() {
		$control = array_fill( 0, 20, 10000 );
		$variant = array_merge( array_fill( 0, 19, 10000 ), array( 1000000000 ) );
		$result  = ( new FieldValueModel() )->compare( $control, 5000, $variant, 5000, 37 );
		$this->assertGreaterThan( $result['control_rpv_minor'], $result['variant_rpv_minor'], 'Actual RPV remains available for reporting.' );
		$this->assertLessThan( 0.75, $result['probability_beneficial'], 'Winsorized inference must not promote from one extreme deal.' );
		$this->assertLessThanOrEqual( 0, $result['robust_interval_minor'][0] );
		$this->assertGreaterThanOrEqual( 0, $result['robust_interval_minor'][1] );
	}

	public function test_missing_outcome_coverage_caps_confidence() {
		$confidence = ( new ConfidenceCalculator() )->calculate(
			array(
				'control_visitors' => 10000,
				'variant_visitors' => 10000,
				'outcomes'         => 500,
				'coverage'         => 0.20,
				'evidence_level'   => CausalEvidence::STRONG_EXPERIMENTAL,
			)
		);
		$this->assertSame( ConfidenceCalculator::INSUFFICIENT, $confidence );
	}

	public function test_observational_signal_never_returns_an_automatic_causal_change() {
		$decision = ( new RecommendationEngine() )->recommend(
			array(
				'friction_score'    => 0.30,
				'submission_impact' => -0.25,
				'qualified_impact'  => 0.01,
				'revenue_impact'    => -0.20,
			),
			ConfidenceCalculator::HIGH,
			CausalEvidence::OBSERVATIONAL
		);
		$this->assertSame( 'conversion_killer', $decision['verdict'] );
		$this->assertSame( 'test', $decision['recommendation'] );
	}

	public function test_simpsons_paradox_fixture_reverses_when_pooled() {
		$control_strata = array( array( 90, 100 ), array( 1, 10 ) );
		$variant_strata = array( array( 19, 20 ), array( 20, 100 ) );
		foreach ( array_keys( $control_strata ) as $index ) {
			$this->assertGreaterThan( $this->rate( $control_strata[ $index ] ), $this->rate( $variant_strata[ $index ] ) );
		}
		$control_pooled = array( 91, 110 );
		$variant_pooled = array( 39, 120 );
		$this->assertLessThan( $this->rate( $control_pooled ), $this->rate( $variant_pooled ) );
	}

	public function test_business_value_fixture_prefers_lower_submission_higher_evpv_arm() {
		$metrics = ( new FieldImpactCalculator() )->calculate(
			array(
				'visitors'       => 4100,
				'submissions'    => 402,
				'qualified'      => 96,
				'won'            => 30,
				'revenue_values' => array_fill( 0, 30, 180000 ),
			),
			array(
				'visitors'       => 4090,
				'submissions'    => 328,
				'qualified'      => 91,
				'won'            => 27,
				'revenue_values' => array_fill( 0, 27, 227407 ),
			)
		);
		$this->assertLessThan( 0, $metrics['submission_impact'] );
		$this->assertGreaterThan( 0, $metrics['revenue']['impact_rpv_minor'] );
	}

	public function test_device_or_day_assignment_imbalance_invalidates_pooled_experiment() {
		$leaked = ( new ExperimentIntegrity() )->has_assignment_leakage(
			array(
				'control'                => array( 'visitors' => 500 ),
				'variant'                => array( 'visitors' => 500 ),
				'expected_variant_share' => 0.5,
				'balance_cells'          => array(
					array(
						'control' => 90,
						'variant' => 10,
					),
				),
			)
		);
		$this->assertTrue( $leaked );
	}

	public function test_configured_values_fill_only_outcomes_without_actual_revenue() {
		$cohort = array(
			'visitors'        => 100,
			'submissions'     => 10,
			'qualified'       => 3,
			'won'             => 2,
			'revenue_samples' => 1,
			'revenue_values'  => array( 10000 ),
		);
		$result = ( new OutcomeValueResolver() )->apply(
			array(
				'control' => $cohort,
				'variant' => $cohort,
			),
			array(
				'won_value_minor'       => 5000,
				'qualified_value_minor' => 2000,
			)
		);
		$this->assertSame( array( 10000, 5000, 2000 ), $result['control']['revenue_values'] );
	}

	private function rate( array $counts ) {
		return $counts[0] / $counts[1];
	}
}
