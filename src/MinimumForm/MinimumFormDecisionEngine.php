<?php

namespace Formhawk\MinimumForm;

use Formhawk\CRO\BusinessValueWinnerSelector;
use Formhawk\CRO\DecisionEvidence;
use Formhawk\CRO\GuardrailEvaluator;
use Formhawk\CRO\WinnerSelector;
use Formhawk\ROI\ExperimentIntegrity;

/** Shared deterministic policy gate for semantic experiments. */
final class MinimumFormDecisionEngine {
	private $integrity;
	private $guardrails;
	private $binary;
	private $business;

	public function __construct( ?ExperimentIntegrity $integrity = null, ?GuardrailEvaluator $guardrails = null, ?WinnerSelector $binary = null, ?BusinessValueWinnerSelector $business = null ) {
		$this->integrity  = $integrity ? $integrity : new ExperimentIntegrity();
		$this->guardrails = $guardrails ? $guardrails : new GuardrailEvaluator();
		$this->binary     = $binary ? $binary : new WinnerSelector();
		$this->business   = $business ? $business : new BusinessValueWinnerSelector();
	}

	public function evaluate( array $experiment, array $variants, array $totals, array $settings, $runtime_days ) {
		if ( count( $variants ) !== 2 ) {
			return $this->fixed( 'integrity_failure', 'corrupt_variant_configuration' );
		}
		$control = $this->metrics( $totals[ absint( $variants[0]['id'] ) ] ?? array() );
		$variant = $this->metrics( $totals[ absint( $variants[1]['id'] ) ] ?? array() );
		$valid   = $this->integrity->validate_aggregates( $control, $variant );
		if ( ! $valid['valid'] ) {
			return $this->fixed( 'integrity_failure', $valid['reason'] );
		}
		$policy                             = $experiment['policy'];
		$policy['business_value_objective'] = in_array( $experiment['primary_metric'], array( 'business_value', 'qualified_leads', 'won_leads' ), true );
		/**
		 * @param array $policy     Minimum Form decision thresholds.
		 * @param array $experiment Immutable experiment definition.
		 */
		$policy = apply_filters( 'formhawk_minimum_form_decision_policy', $policy, $experiment );
		if ( ! is_array( $policy ) ) {
			return $this->fixed( 'integrity_failure', 'invalid_decision_policy' );
		}
		$guard = $this->guardrails->evaluate( $control, $variant, $policy );
		if ( $guard['triggered'] ) {
			return $this->with_counts( array_merge( $this->fixed( 'reject', $guard['reason'] ), array( 'guardrail' => true ) ), $control, $variant );
		}
		if ( $policy['business_value_objective'] ) {
			$result = $this->business->select( $experiment, $variants, $policy, $settings, $runtime_days );
		} else {
			$result = $this->binary->select( DecisionEvidence::server_sample( $control ), DecisionEvidence::server_sample( $variant ), $policy, $runtime_days );
		}
		if ( 'integrity_failure' === ( $result['decision'] ?? '' ) ) {
			return $result;
		}
		$result['guardrail'] = false;
		return $this->with_counts( $result, $control, $variant );
	}

	private function metrics( array $row ) {
		$row['assignments'] = absint( $row['assignments'] ?? 0 );
		$row['conversions'] = absint( $row['confirmed_successes'] ?? 0 );
		return $row;
	}

	private function fixed( $decision, $reason ) {
		return array(
			'decision'               => $decision,
			'reason'                 => $reason,
			'expected_lift'          => null,
			'probability_to_be_best' => null,
			'expected_loss'          => null,
			'control_rate'           => null,
			'variant_rate'           => null,
		);
	}

	private function with_counts( array $result, array $control, array $variant ) {
		$result['control_visitors']  = absint( $control['assignments'] ?? 0 );
		$result['variant_visitors']  = absint( $variant['assignments'] ?? 0 );
		$result['control_confirmed'] = absint( $control['conversions'] ?? 0 );
		$result['variant_confirmed'] = absint( $variant['conversions'] ?? 0 );
		return $result;
	}
}
