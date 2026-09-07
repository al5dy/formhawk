<?php

namespace Formhawk\ROI;

final class FieldROIEngine {
	const MODEL_VERSION = 'field-roi-1.0';
	private $repository;
	private $impact;
	private $confidence;
	private $recommendations;
	private $integrity;
	private $values;

	public function __construct( FieldROIRepository $repository = null, FieldImpactCalculator $impact = null, ConfidenceCalculator $confidence = null, RecommendationEngine $recommendations = null, ExperimentIntegrity $integrity = null, OutcomeValueResolver $values = null ) {
		$this->repository      = $repository ? $repository : new FieldROIRepository();
		$this->impact          = $impact ? $impact : new FieldImpactCalculator();
		$this->confidence      = $confidence ? $confidence : new ConfidenceCalculator();
		$this->recommendations = $recommendations ? $recommendations : new RecommendationEngine();
		$this->integrity       = $integrity ? $integrity : new ExperimentIntegrity();
		$this->values          = $values ? $values : new OutcomeValueResolver();
	}

	public function run_batch( $offset = 0, $limit = 25 ) {
		$settings = $this->settings();
		$end      = current_time( 'Y-m-d' );
		$start    = wp_date( 'Y-m-d', time() - ( $settings['period_days'] - 1 ) * DAY_IN_SECONDS, wp_timezone() );
		$fields   = $this->repository->fields( $start, $end, $limit, $offset );
		foreach ( $fields as $field ) {
			$this->evaluate_field( $field, $start, $end, $settings ); }
		return count( $fields );
	}

	public function refresh_daily_aggregates( $outcome_cursor ) {
		return $this->repository->rebuild_changed_days( $outcome_cursor );
	}

	public function evaluate_field( array $field, $start, $end, array $settings = array() ) {
		$settings     = array_merge( $this->settings(), $settings );
		$interactions = absint( $field['interactions'] );
		$starts       = absint( $field['starts'] );
		$validation   = absint( $field['provider_validation_errors'] ) + 0.35 * absint( $field['client_validation_errors'] );
		$friction     = $interactions ? min( 1, ( absint( $field['abandonments'] ) + $validation ) / $interactions ) : 0;
		$metrics      = array(
			'field_reach_rate'        => null,
			'field_interaction_rate'  => $starts ? min( 1, $interactions / $starts ) : null,
			'field_completion_rate'   => null,
			'validation_failure_rate' => $interactions ? $validation / $interactions : null,
			'correction_rate'         => null,
			'abandonment_association' => $interactions ? absint( $field['abandonments'] ) / $interactions : null,
			'friction_score'          => $friction,
			'interactions'            => $interactions,
			'starts'                  => $starts,
			'required'                => ! empty( $field['required'] ),
			'currency'                => $settings['currency'],
			'methodology'             => 'stage_matched_controlled_experiment',
		);
		$evidence     = CausalEvidence::OBSERVATIONAL;
		$confidence   = ConfidenceCalculator::INSUFFICIENT;
		$data_through = $end;
		$experiment   = $this->repository->experiment_for_field( $field, $start, $end );
		if ( $experiment ) {
			$cohorts = $this->repository->cohorts( $experiment, $start, $end, $settings['currency'], $settings['maturity_days'] );
			if ( $cohorts ) {
				$cohorts                                      = $this->values->apply( $cohorts, $settings );
				$intervention_impact                          = $this->impact->calculate( $cohorts['control'], $cohorts['variant'] );
				$impact                                       = $this->impact->calculate( $cohorts['variant'], $cohorts['control'] );
				$metrics                                      = array_merge( $metrics, $impact );
				$metrics['control']                           = $this->snapshot( $cohorts['control'] );
				$metrics['variant']                           = $this->snapshot( $cohorts['variant'] );
				$metrics['experiment_id']                     = absint( $experiment['id'] );
				$metrics['experiment_type']                   = $experiment['type'];
				$metrics['intervention']                      = $experiment['intervention'];
				$metrics['intervention_impact']               = $this->impact_snapshot( $intervention_impact );
				$metrics['expected_value_contribution_minor'] = $metrics['revenue']['impact_rpv_minor'] * ( $cohorts['control']['visitors'] + $cohorts['variant']['visitors'] );
				$metrics['outcome_coverage']                  = $this->coverage( $cohorts );
				$metrics['experiment_leakage']                = $this->integrity->has_assignment_leakage( $cohorts );
				$data_through                                 = $cohorts['mature_through'];
				$outcomes                                     = (int) $cohorts['control']['known'] + (int) $cohorts['variant']['known'];
				$probability                                  = $intervention_impact['revenue']['revenue_samples'] >= 20 ? $intervention_impact['revenue']['probability_beneficial'] : $intervention_impact['quality_probability_beneficial'];
				$directional_probability                      = max( $probability, 1 - $probability );
				$evidence                                     = $cohorts['control']['visitors'] >= 5000 && $cohorts['variant']['visitors'] >= 5000 && $outcomes >= 250 && $metrics['outcome_coverage'] >= 0.90 && $directional_probability >= 0.99
					? CausalEvidence::STRONG_EXPERIMENTAL : CausalEvidence::EXPERIMENTAL;
				$confidence                                   = $this->confidence->calculate(
					array(
						'control_visitors' => $cohorts['control']['visitors'],
						'variant_visitors' => $cohorts['variant']['visitors'],
						'outcomes'         => $outcomes,
						'coverage'         => $metrics['outcome_coverage'],
						'evidence_level'   => $evidence,
					)
				);
				if ( $metrics['experiment_leakage'] ) {
					$confidence = ConfidenceCalculator::INSUFFICIENT; }
			}
		}
		if ( CausalEvidence::OBSERVATIONAL === $evidence ) {
			$metrics['submission_impact']                 = null;
			$metrics['qualified_impact']                  = null;
			$metrics['won_impact']                        = null;
			$metrics['revenue_impact']                    = null;
			$metrics['expected_value_contribution_minor'] = null;
			$metrics['outcome_coverage']                  = 0;
			$metrics['causal_roi_unavailable']            = true;
			$metrics['methodology']                       = 'observational_friction_only';
		}
		$metrics['sample_size']       = isset( $metrics['control_visitors'] ) ? $metrics['control_visitors'] + $metrics['variant_visitors'] : $interactions;
		$metrics['evidence_language'] = $this->evidence_language( $evidence );
		$metrics['value_metric']      = apply_filters( 'formhawk_field_roi_value_metric', ! empty( $metrics['revenue']['revenue_samples'] ) ? 'revenue_per_visitor' : ( isset( $metrics['qualified_impact'] ) && null !== $metrics['qualified_impact'] ? 'qualified_leads_per_visitor' : 'friction_only' ), $field, $metrics );
		$decision                     = $this->recommendations->recommend( $metrics, $confidence, $evidence );
		$policy                       = apply_filters(
			'formhawk_field_roi_policy',
			array(
				'settings'      => $settings,
				'model_version' => self::MODEL_VERSION,
			),
			$field
		);
		$metrics['policy']            = is_array( $policy ) ? $policy : array();
		$saved                        = $this->repository->save( $field['id'], $start, $end, $settings['currency'], $evidence, $confidence, $decision, $metrics, self::MODEL_VERSION . '+' . FieldValueModel::MODEL_VERSION, $data_through );
		if ( $saved ) {
			do_action( 'formhawk_field_roi_updated', absint( $field['id'] ), $decision, $metrics );
			if ( in_array( $decision['verdict'], array( 'money_maker', 'conversion_killer', 'qualifier' ), true ) ) {
				do_action( 'formhawk_field_roi_opportunity_detected', absint( $field['id'] ), $decision, $metrics ); }
		}
		return array(
			'evidence_level' => $evidence,
			'confidence'     => $confidence,
			'decision'       => $decision,
			'metrics'        => $metrics,
		);
	}

	private function settings() {
		$stored   = get_option( 'formhawk_field_roi_settings', array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$currency = isset( $stored['currency'] ) && \Formhawk\Outcomes\OutcomeNormalizer::valid_currency( $stored['currency'] ) ? strtoupper( $stored['currency'] ) : 'USD';
		return array(
			'period_days'           => 90,
			'maturity_days'         => max( 1, min( 90, absint( isset( $stored['maturity_days'] ) ? $stored['maturity_days'] : 14 ) ) ),
			'currency'              => $currency,
			'objective'             => in_array( isset( $stored['objective'] ) ? $stored['objective'] : '', array( 'qualified', 'won', 'business_value' ), true ) ? $stored['objective'] : 'business_value',
			'qualified_value_minor' => isset( $stored['qualified_value_minor'] ) ? max( 0, (int) $stored['qualified_value_minor'] ) : 0,
			'won_value_minor'       => isset( $stored['won_value_minor'] ) ? max( 0, (int) $stored['won_value_minor'] ) : 0,
		);
	}

	private function snapshot( array $cohort ) {
		return array_intersect_key( $cohort, array_flip( array( 'visitors', 'submissions', 'qualified', 'won', 'known', 'coverage', 'maturing' ) ) );
	}

	private function impact_snapshot( array $impact ) {
		return array_intersect_key(
			$impact,
			array_flip( array( 'submission_impact', 'qualified_impact', 'won_impact', 'revenue_impact', 'expected_value_contribution_minor' ) )
		);
	}

	private function coverage( array $cohorts ) {
		$submitted = $cohorts['control']['submissions'] + $cohorts['variant']['submissions'];
		return $submitted ? min( 1, ( $cohorts['control']['known'] + $cohorts['variant']['known'] ) / $submitted ) : 0;
	}

	private function evidence_language( $evidence ) {
		if ( CausalEvidence::STRONG_EXPERIMENTAL === $evidence ) {
			return 'proven_impact'; }
		if ( CausalEvidence::EXPERIMENTAL === $evidence ) {
			return 'measured_causal_lift'; }
		if ( CausalEvidence::QUASI_EXPERIMENTAL === $evidence ) {
			return 'estimated_quasi_experimental_impact'; }
		return 'associated_with';
	}
}
