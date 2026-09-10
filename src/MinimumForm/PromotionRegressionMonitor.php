<?php

namespace Formhawk\MinimumForm;

use Formhawk\CRO\StatisticalEngine;
use Formhawk\ROI\ExperimentIntegrity;
use Formhawk\ROI\FieldImpactCalculator;
use Formhawk\ROI\FieldROIRepository;
use Formhawk\ROI\OutcomeValueResolver;

/** Evaluates mature post-promotion business outcomes against the original control. */
final class PromotionRegressionMonitor {
	private $repository;
	private $impact;
	private $statistics;
	private $integrity;
	private $values;

	public function __construct( ?FieldROIRepository $repository = null, ?FieldImpactCalculator $impact = null, ?StatisticalEngine $statistics = null, ?ExperimentIntegrity $integrity = null, ?OutcomeValueResolver $values = null ) {
		$this->repository = $repository ? $repository : new FieldROIRepository();
		$this->impact     = $impact ? $impact : new FieldImpactCalculator();
		$this->statistics = $statistics ? $statistics : new StatisticalEngine();
		$this->integrity  = $integrity ? $integrity : new ExperimentIntegrity();
		$this->values     = $values ? $values : new OutcomeValueResolver();
	}

	public function evaluate( array $experiment, array $variants, array $policy, array $settings, $current_start, $runtime_days ) {
		$result = array(
			'triggered' => false,
			'reason'    => 'collecting_business_guardrail',
			'analysis'  => null,
			'ready'     => false,
		);
		if ( count( $variants ) !== 2 || empty( $experiment['winner_variant_id'] ) || ! in_array( $experiment['primary_metric'], array( 'business_value', 'qualified_leads', 'won_leads' ), true ) ) {
			return $result;
		}
		$field_settings = get_option( 'formhawk_field_roi_settings', array() );
		$field_settings = is_array( $field_settings ) ? $field_settings : array();
		$maturity       = isset( $field_settings['maturity_days'] ) ? max( 1, min( 90, absint( $field_settings['maturity_days'] ) ) ) : 14;
		$currency       = isset( $settings['currency'] ) ? strtoupper( sanitize_text_field( $settings['currency'] ) ) : 'USD';
		$cohorts        = $this->repository->promotion_cohorts(
			$experiment,
			absint( $variants[0]['id'] ),
			absint( $experiment['winner_variant_id'] ),
			$current_start,
			current_time( 'Y-m-d' ),
			$currency,
			$maturity
		);
		if ( ! $cohorts ) {
			return $result;
		}
		$valid = $this->integrity->validate_aggregates( $cohorts['control'], $cohorts['variant'] );
		if ( ! $valid['valid'] ) {
			return array(
				'triggered' => true,
				'reason'    => $valid['reason'],
				'analysis'  => array( 'decision' => 'integrity_failure' ),
				'ready'     => false,
			);
		}
		$minimum = max( 100, absint( $policy['guardrail_minimum_views'] ?? 100 ) );
		if ( $runtime_days < max( 3, min( $maturity, absint( $policy['promotion_monitor_days'] ?? 14 ) ) )
			|| $cohorts['control']['visitors'] < $minimum
			|| $cohorts['variant']['visitors'] < $minimum ) {
			return $result;
		}
		$coverage = $this->coverage( $cohorts );
		if ( $coverage < 0.60 ) {
			$result['reason'] = 'outcome_coverage';
			return $result;
		}
		$cohorts = $this->values->apply( $cohorts, $field_settings );
		$metrics = $this->impact->calculate( $cohorts['control'], $cohorts['variant'] );
		$key     = 'won_leads' === $experiment['primary_metric'] ? 'won' : 'qualified';
		if ( 'business_value' === $experiment['primary_metric'] && $metrics['revenue']['revenue_samples'] >= 20 ) {
			$probability_harm = 1 - $metrics['revenue']['probability_beneficial'];
			$relative_drop    = null !== $metrics['revenue_impact'] ? -$metrics['revenue_impact'] : 0;
			$practical_minor  = max( 1.0, abs( $metrics['revenue']['control_rpv_minor'] ) * (float) $policy['rollback_relative_drop'] );
			$triggered        = $probability_harm >= (float) $policy['guardrail_harm_probability']
				&& $relative_drop >= (float) $policy['rollback_relative_drop']
				&& $metrics['revenue']['robust_interval_minor'][1] <= -$practical_minor;
			return array(
				'triggered' => $triggered,
				'reason'    => $triggered ? 'business_value_regression' : 'within_business_value_limits',
				'analysis'  => array_merge( $metrics['revenue'], array( 'outcome_coverage' => $coverage ) ),
				'ready'     => true,
			);
		}
		$analysis_policy = array_merge(
			$policy,
			array(
				'minimum_views_per_variant' => $minimum,
				'minimum_conversions'       => 1,
				'minimum_runtime_days'      => 0,
				'probability_to_be_best'    => $policy['guardrail_harm_probability'],
				'minimum_absolute_effect'   => max( 0.001, (float) $policy['rollback_relative_drop'] * max( 0.01, (float) $metrics[ $key . '_per_visitor_control' ] ) ),
			)
		);
		$analysis        = $this->statistics->evaluate(
			array(
				'views'       => $cohorts['control']['visitors'],
				'conversions' => $cohorts['control'][ $key ],
			),
			array(
				'views'       => $cohorts['variant']['visitors'],
				'conversions' => $cohorts['variant'][ $key ],
			),
			$analysis_policy,
			$runtime_days
		);
		return array(
			'triggered' => 'reject' === $analysis['decision'],
			'reason'    => 'reject' === $analysis['decision'] ? $key . '_per_visitor_regression' : 'within_business_value_limits',
			'analysis'  => array_merge( $analysis, array( 'outcome_coverage' => $coverage ) ),
			'ready'     => true,
		);
	}

	private function coverage( array $cohorts ) {
		$submissions = absint( $cohorts['control']['submissions'] ) + absint( $cohorts['variant']['submissions'] );
		return $submissions ? min( 1, ( absint( $cohorts['control']['known'] ) + absint( $cohorts['variant']['known'] ) ) / $submissions ) : 0;
	}
}
