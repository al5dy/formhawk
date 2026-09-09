<?php

namespace Formhawk\CRO;

final class GuardrailEvaluator {
	private $statistics;

	public function __construct( StatisticalEngine $statistics = null ) {
		$this->statistics = $statistics ? $statistics : new StatisticalEngine();
	}

	public function evaluate( array $control, array $variant, array $policy ) {
		/**
		 * @param array $policy Guardrail policy.
		 * @param array $control Control aggregates.
		 * @param array $variant Variant aggregates.
		 */
		$policy = apply_filters( 'formhawk_cro_guardrails', $policy, $control, $variant );
		if ( ! is_array( $policy ) ) {
			return array(
				'triggered' => true,
				'reason'    => 'invalid_guardrail_policy',
				'evidence'  => DecisionEvidence::SERVER_CONFIRMED,
				'warnings'  => array(),
			);
		}
		$warnings    = $this->advisory_warnings( $control, $variant, $policy );
		$v_exposures = absint( $variant['assignments'] ?? 0 );
		$c_exposures = absint( $control['assignments'] ?? 0 );
		if ( $v_exposures < $policy['guardrail_minimum_views'] || ! $c_exposures ) {
			return array(
				'triggered' => false,
				'reason'    => 'collecting_guardrail_data',
				'evidence'  => DecisionEvidence::SERVER_CONFIRMED,
				'warnings'  => $warnings,
			);
		}
		$v_provider_rate = ( absint( $variant['provider_failures'] ?? 0 ) + absint( $variant['mail_failures'] ?? 0 ) ) / $v_exposures;
		$c_provider_rate = ( absint( $control['provider_failures'] ?? 0 ) + absint( $control['mail_failures'] ?? 0 ) ) / $c_exposures;
		if ( $v_provider_rate > $policy['guardrail_provider_failure_rate'] && $v_provider_rate > max( 0.005, $c_provider_rate * 2 ) ) {
			return array(
				'triggered' => true,
				'reason'    => 'provider_failure_rate',
				'evidence'  => DecisionEvidence::SERVER_CONFIRMED,
				'warnings'  => $warnings,
			);
		}

		$v_validation_denominator = absint( $variant['provider_validation_failures'] ?? 0 ) + absint( $variant['confirmed_successes'] ?? 0 );
		$c_validation_denominator = absint( $control['provider_validation_failures'] ?? 0 ) + absint( $control['confirmed_successes'] ?? 0 );
		if ( $v_validation_denominator >= 30 && $c_validation_denominator >= 30 ) {
			$v_validation = absint( $variant['provider_validation_failures'] ?? 0 ) / $v_validation_denominator;
			$c_validation = absint( $control['provider_validation_failures'] ?? 0 ) / $c_validation_denominator;
			if ( $v_validation > 0.10 && $v_validation > max( 0.02, $c_validation ) * $policy['guardrail_validation_multiplier'] ) {
				return array(
					'triggered' => true,
					'reason'    => 'provider_validation_explosion',
					'evidence'  => DecisionEvidence::SERVER_CONFIRMED,
					'warnings'  => $warnings,
				);
			}
		}

		if ( empty( $policy['business_value_objective'] ) ) {
			$guard_policy = array_merge(
				$policy,
				array(
					'minimum_views_per_variant' => $policy['guardrail_minimum_views'],
					'minimum_conversions'       => 1,
					'minimum_runtime_days'      => 0,
					'probability_to_be_best'    => $policy['guardrail_harm_probability'],
				)
			);
			$analysis     = $this->statistics->evaluate( DecisionEvidence::server_sample( $control ), DecisionEvidence::server_sample( $variant ), $guard_policy, 999 );
			if ( ( 1 - $analysis['probability_to_be_best'] ) >= $policy['guardrail_harm_probability']
				&& $analysis['control_rate'] - $analysis['variant_rate'] >= $policy['guardrail_absolute_conversion_drop'] ) {
				return array(
					'triggered' => true,
					'reason'    => 'confirmed_conversion_harm',
					'analysis'  => $analysis,
					'evidence'  => DecisionEvidence::SERVER_CONFIRMED,
					'warnings'  => $warnings,
				);
			}
		}
		return array(
			'triggered' => false,
			'reason'    => 'within_limits',
			'evidence'  => DecisionEvidence::SERVER_CONFIRMED,
			'warnings'  => $warnings,
		);
	}

	private function advisory_warnings( array $control, array $variant, array $policy ) {
		$warnings = array();
		$v_views  = absint( $variant['views'] ?? 0 );
		if ( $v_views >= $policy['guardrail_minimum_views'] && absint( $variant['js_errors'] ?? 0 ) / max( 1, $v_views ) > $policy['guardrail_js_error_rate'] ) {
			$warnings[] = array(
				'reason'   => 'js_error_rate',
				'evidence' => DecisionEvidence::CLIENT_OBSERVED,
			);
		}
		// Browser-only signals can request review, never authorize a traffic or baseline transition.
		$v_starts = absint( $variant['starts'] ?? 0 );
		$c_starts = absint( $control['starts'] ?? 0 );
		if ( $v_starts >= 50 && $c_starts >= 50 ) {
			$v_client_validation = min( $v_starts, absint( $variant['client_validation_failures'] ?? 0 ) ) / $v_starts;
			$c_client_validation = min( $c_starts, absint( $control['client_validation_failures'] ?? 0 ) ) / $c_starts;
			if ( $v_client_validation > 0.15 && $v_client_validation > max( 0.03, $c_client_validation ) * $policy['guardrail_validation_multiplier'] ) {
				$warnings[] = array(
					'reason'   => 'client_validation_explosion',
					'evidence' => DecisionEvidence::CLIENT_OBSERVED,
				);
			}
		}

		$v_latency_samples = absint( $variant['latency_samples'] ?? 0 );
		$c_latency_samples = absint( $control['latency_samples'] ?? 0 );
		if ( $v_latency_samples >= 30 && $c_latency_samples >= 30 ) {
			$v_latency = absint( $variant['latency_total_ms'] ?? 0 ) / $v_latency_samples;
			$c_latency = absint( $control['latency_total_ms'] ?? 0 ) / $c_latency_samples;
			if ( $v_latency > $c_latency * 2 && $v_latency - $c_latency > 500 ) {
				$warnings[] = array(
					'reason'   => 'submission_latency',
					'evidence' => DecisionEvidence::CLIENT_OBSERVED,
				);
			}
		}

		return $warnings;
	}
}
