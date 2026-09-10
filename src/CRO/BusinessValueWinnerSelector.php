<?php

namespace Formhawk\CRO;

use Formhawk\ROI\FieldImpactCalculator;
use Formhawk\ROI\FieldROIRepository;
use Formhawk\ROI\ExperimentIntegrity;
use Formhawk\ROI\OutcomeValueResolver;

final class BusinessValueWinnerSelector {
	private $repository;
	private $impact;
	private $binary;
	private $integrity;
	private $values;

	public function __construct( ?FieldROIRepository $repository = null, ?FieldImpactCalculator $impact = null, ?StatisticalEngine $binary = null, ?ExperimentIntegrity $integrity = null, ?OutcomeValueResolver $values = null ) {
		$this->repository = $repository ? $repository : new FieldROIRepository();
		$this->impact     = $impact ? $impact : new FieldImpactCalculator();
		$this->binary     = $binary ? $binary : new StatisticalEngine();
		$this->integrity  = $integrity ? $integrity : new ExperimentIntegrity();
		$this->values     = $values ? $values : new OutcomeValueResolver();
	}

	public function select( array $experiment, array $variants, array $policy, array $settings, $runtime_days ) {
		$policy = array_merge( ( new OptimizationPolicy() )->presets()['balanced'], $policy );
		$result = array(
			'decision'               => 'collecting',
			'reason'                 => 'business_outcome_maturity',
			'expected_lift'          => null,
			'probability_to_be_best' => 0.5,
			'expected_loss'          => null,
			'metric'                 => 'business_value',
		);
		if ( (int) ( $experiment['integrity_version'] ?? 1 ) < 2 ) {
			$result['reason'] = 'legacy_experiment_review';
			return $result;
		}
		if ( count( $variants ) !== 2 ) {
			$result['reason'] = 'corrupt_variant_configuration';
			return $result; }
		$start                  = $experiment['started_at_utc'] ? get_date_from_gmt( $experiment['started_at_utc'], 'Y-m-d' ) : current_time( 'Y-m-d' );
		$end                    = current_time( 'Y-m-d' );
		$field_settings         = get_option( 'formhawk_field_roi_settings', array() );
		$maturity               = is_array( $field_settings ) && isset( $field_settings['maturity_days'] ) ? absint( $field_settings['maturity_days'] ) : 14;
		$currency               = isset( $settings['currency'] ) ? $settings['currency'] : 'USD';
		$experiment['variants'] = $variants;
		$cohorts                = $this->repository->cohorts( $experiment, $start, $end, $currency, $maturity, true );
		if ( ! $cohorts ) {
			return $result; }
		$integrity = $this->integrity->validate_aggregates( $cohorts['control'], $cohorts['variant'] );
		if ( ! $integrity['valid'] ) {
			$result['decision'] = 'integrity_failure';
			$result['reason']   = $integrity['reason'];
			return $result;
		}
		if ( $this->integrity->has_assignment_leakage( $cohorts ) ) {
			$result['reason'] = 'experiment_leakage';
			return $result;
		}
		if ( is_array( $field_settings ) ) {
			$cohorts = $this->values->apply( $cohorts, $field_settings );
		}
		$metrics   = $this->impact->calculate( $cohorts['control'], $cohorts['variant'] );
		$submitted = (int) $cohorts['control']['submissions'] + (int) $cohorts['variant']['submissions'];
		$coverage  = $submitted > 0 ? ( (int) $cohorts['control']['known'] + (int) $cohorts['variant']['known'] ) / $submitted : 0;
		if ( $cohorts['control']['visitors'] < $policy['minimum_views_per_variant'] || $cohorts['variant']['visitors'] < $policy['minimum_views_per_variant'] || $coverage < 0.60 || $runtime_days < $policy['minimum_runtime_days'] ) {
			$result['reason'] = $coverage < 0.60 ? 'outcome_coverage' : 'minimum_sample';
			return array_merge(
				$result,
				array(
					'outcome_coverage'     => $coverage,
					'maturing_submissions' => $metrics['maturing_submissions'],
				)
			);
		}
		$quality_policy = array_merge(
			$policy,
			array(
				'minimum_conversions'     => 1,
				'minimum_runtime_days'    => 0,
				'minimum_absolute_effect' => max( 0.001, $policy['minimum_absolute_effect'] ),
			)
		);
		$quality_guard  = $this->binary->evaluate(
			array(
				'views'       => $cohorts['control']['visitors'],
				'conversions' => $cohorts['control']['qualified'],
			),
			array(
				'views'       => $cohorts['variant']['visitors'],
				'conversions' => $cohorts['variant']['qualified'],
			),
			$quality_policy,
			$runtime_days
		);
		if ( 'reject' === $quality_guard['decision'] ) {
			$result['decision']               = 'reject';
			$result['reason']                 = 'qualified_lead_guardrail';
			$result['probability_to_be_best'] = $quality_guard['probability_to_be_best'];
			$result['expected_lift']          = $quality_guard['expected_lift'];
			$result['expected_loss']          = $quality_guard['expected_loss'];
			$result['metric']                 = 'qualified_leads_per_visitor';
			$result['outcome_coverage']       = $coverage;
			return $result;
		}
		$objective = isset( $settings['optimization_objective'] ) ? $settings['optimization_objective'] : 'auto';
		if ( 'business_value' === $experiment['primary_metric'] && $metrics['revenue']['revenue_samples'] >= 20 ) {
			$probability             = $metrics['revenue']['probability_beneficial'];
			$lift                    = $metrics['revenue_impact'];
			$result['metric']        = 'revenue_per_visitor';
			$result['control_rate']  = $metrics['revenue']['control_rpv_minor'];
			$result['variant_rate']  = $metrics['revenue']['variant_rpv_minor'];
			$interval                = $metrics['revenue']['robust_interval_minor'];
			$practical_effect        = max( 1.0, abs( $metrics['revenue']['control_rpv_minor'] ) * $policy['minimum_absolute_effect'] );
			$result['expected_loss'] = $metrics['revenue']['expected_loss_minor'];
			if ( $probability >= $policy['probability_to_be_best'] && $interval[0] >= $practical_effect && $result['expected_loss'] <= max( 1.0, abs( $metrics['revenue']['control_rpv_minor'] ) * $policy['maximum_expected_loss'] ) ) {
				$result['decision'] = 'winner';
				$result['reason']   = 'credible_business_value_improvement';
			} elseif ( ( 1 - $probability ) >= $policy['probability_to_be_best'] && $interval[1] <= -$practical_effect ) {
				$result['decision'] = 'reject';
				$result['reason']   = 'credible_business_value_harm';
			} elseif ( $interval[0] < $practical_effect && $interval[1] > -$practical_effect ) {
				$result['reason'] = 'practical_significance';
			}
		} else {
			$key                     = 'won_leads' === $experiment['primary_metric'] || 'won_leads' === $objective ? 'won' : 'qualified';
			$analysis                = $this->binary->evaluate(
				array(
					'views'       => $cohorts['control']['visitors'],
					'conversions' => $cohorts['control'][ $key ],
				),
				array(
					'views'       => $cohorts['variant']['visitors'],
					'conversions' => $cohorts['variant'][ $key ],
				),
				$policy,
				$runtime_days
			);
			$probability             = $analysis['probability_to_be_best'];
			$lift                    = $analysis['expected_lift'];
			$result['metric']        = $key . '_leads_per_visitor';
			$result['control_rate']  = $analysis['control_rate'];
			$result['variant_rate']  = $analysis['variant_rate'];
			$result['decision']      = $analysis['decision'];
			$result['reason']        = $analysis['reason'];
			$result['expected_loss'] = $analysis['expected_loss'];
		}
		$result['expected_lift']          = $lift;
		$result['probability_to_be_best'] = $probability;
		$result['outcome_coverage']       = $coverage;
		$result['maturing_submissions']   = $metrics['maturing_submissions'];
		if ( 'collecting' === $result['decision'] && $runtime_days >= $policy['maximum_runtime_days'] ) {
			$result['decision'] = 'inconclusive';
			$result['reason']   = 'maximum_runtime';
		}
		return $result;
	}
}
