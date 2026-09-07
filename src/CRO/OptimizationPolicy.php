<?php

namespace Formhawk\CRO;

final class OptimizationPolicy {
	const VERSION = '2026-09-01';

	public function for_form( array $settings ) {
		$name = isset( $settings['aggressiveness'] ) ? sanitize_key( $settings['aggressiveness'] ) : 'balanced';
		$all  = $this->presets();
		if ( ! isset( $all[ $name ] ) ) {
			$name = 'balanced';
		}
		$policy = $all[ $name ];
		if ( isset( $settings['min_duration_days'] ) ) {
			$policy['minimum_runtime_days'] = max( $policy['hard_minimum_runtime_days'], absint( $settings['min_duration_days'] ) );
		}
		if ( isset( $settings['min_conversions'] ) ) {
			$policy['minimum_conversions'] = max( $policy['hard_minimum_conversions'], absint( $settings['min_conversions'] ) );
		}
		if ( isset( $settings['max_experimental_traffic'] ) ) {
			$policy['maximum_experimental_traffic'] = max( 10, min( 50, absint( $settings['max_experimental_traffic'] ) ) );
		}

		/**
		 * Filters the complete CRO decision policy. Keep thresholds internally
		 * coherent: lowering one safety gate does not bypass the hard floors.
		 *
		 * @param array  $policy Decision and guardrail thresholds.
		 * @param string $name   Preset name.
		 */
		$policy = apply_filters( 'formhawk_cro_statistical_policy', $policy, $name );
		return $this->normalize( is_array( $policy ) ? $policy : array(), $all[ $name ] );
	}

	public function presets() {
		$common = array(
			'prior_alpha'                        => 0.5,
			'prior_beta'                         => 0.5,
			'minimum_views_per_variant'          => 500,
			'minimum_conversions'                => 50,
			'hard_minimum_conversions'           => 20,
			'minimum_runtime_days'               => 7,
			'hard_minimum_runtime_days'          => 3,
			'probability_to_be_best'             => 0.97,
			'maximum_expected_loss'              => 0.0025,
			'minimum_absolute_effect'            => 0.002,
			'maximum_experimental_traffic'       => 50,
			'minimum_exploration_traffic'        => 20,
			'guardrail_minimum_views'            => 100,
			'guardrail_harm_probability'         => 0.995,
			'guardrail_absolute_conversion_drop' => 0.03,
			'guardrail_provider_failure_rate'    => 0.05,
			'guardrail_validation_multiplier'    => 2.5,
			'guardrail_js_error_rate'            => 0.02,
			'maximum_runtime_days'               => 42,
			'promotion_monitor_days'             => 14,
			'rollback_relative_drop'             => 0.20,
			'context_ttl_seconds'                => 7200,
		);

		$conservative = array_merge(
			$common,
			array(
				'minimum_views_per_variant' => 1000,
				'minimum_conversions'       => 75,
				'minimum_runtime_days'      => 14,
				'probability_to_be_best'    => 0.99,
				'maximum_expected_loss'     => 0.001,
				'minimum_absolute_effect'   => 0.003,
				'guardrail_minimum_views'   => 75,
				'promotion_monitor_days'    => 21,
			)
		);
		$aggressive   = array_merge(
			$common,
			array(
				'minimum_views_per_variant' => 250,
				'minimum_conversions'       => 30,
				'minimum_runtime_days'      => 3,
				'probability_to_be_best'    => 0.95,
				'maximum_expected_loss'     => 0.005,
				'minimum_absolute_effect'   => 0.001,
				'guardrail_minimum_views'   => 150,
				'promotion_monitor_days'    => 7,
			)
		);

		return array(
			'conservative' => $conservative,
			'balanced'     => $common,
			'aggressive'   => $aggressive,
		);
	}

	private function normalize( array $policy, array $fallback ) {
		$normalized = array_merge( $fallback, $policy );
		foreach ( array( 'minimum_views_per_variant', 'minimum_conversions', 'hard_minimum_conversions', 'minimum_runtime_days', 'hard_minimum_runtime_days', 'maximum_experimental_traffic', 'minimum_exploration_traffic', 'guardrail_minimum_views', 'maximum_runtime_days', 'promotion_monitor_days', 'context_ttl_seconds' ) as $key ) {
			$normalized[ $key ] = max( 1, absint( $normalized[ $key ] ) );
		}
		foreach ( array( 'prior_alpha', 'prior_beta', 'probability_to_be_best', 'maximum_expected_loss', 'minimum_absolute_effect', 'guardrail_harm_probability', 'guardrail_absolute_conversion_drop', 'guardrail_provider_failure_rate', 'guardrail_validation_multiplier', 'guardrail_js_error_rate', 'rollback_relative_drop' ) as $key ) {
			$normalized[ $key ] = (float) $normalized[ $key ];
		}
		$normalized['prior_alpha']                        = max( 0.1, min( 100, $normalized['prior_alpha'] ) );
		$normalized['prior_beta']                         = max( 0.1, min( 100, $normalized['prior_beta'] ) );
		$normalized['probability_to_be_best']             = max( 0.80, min( 0.9999, $normalized['probability_to_be_best'] ) );
		$normalized['guardrail_harm_probability']         = max( 0.90, min( 0.9999, $normalized['guardrail_harm_probability'] ) );
		$normalized['maximum_expected_loss']              = max( 0.00001, min( 0.20, $normalized['maximum_expected_loss'] ) );
		$normalized['minimum_absolute_effect']            = max( 0, min( 0.50, $normalized['minimum_absolute_effect'] ) );
		$normalized['guardrail_absolute_conversion_drop'] = max( 0.001, min( 0.50, $normalized['guardrail_absolute_conversion_drop'] ) );
		$normalized['guardrail_provider_failure_rate']    = max( 0.001, min( 1, $normalized['guardrail_provider_failure_rate'] ) );
		$normalized['guardrail_validation_multiplier']    = max( 1.1, min( 100, $normalized['guardrail_validation_multiplier'] ) );
		$normalized['guardrail_js_error_rate']            = max( 0.001, min( 1, $normalized['guardrail_js_error_rate'] ) );
		$normalized['rollback_relative_drop']             = max( 0.01, min( 0.90, $normalized['rollback_relative_drop'] ) );
		$normalized['hard_minimum_conversions']           = max( 20, $normalized['hard_minimum_conversions'] );
		$normalized['hard_minimum_runtime_days']          = max( 3, $normalized['hard_minimum_runtime_days'] );
		$normalized['minimum_views_per_variant']          = max( 100, $normalized['minimum_views_per_variant'] );
		$normalized['minimum_conversions']                = max( $normalized['hard_minimum_conversions'], $normalized['minimum_conversions'] );
		$normalized['minimum_runtime_days']               = max( $normalized['hard_minimum_runtime_days'], $normalized['minimum_runtime_days'] );
		$normalized['maximum_experimental_traffic']       = min( 50, $normalized['maximum_experimental_traffic'] );
		$normalized['minimum_exploration_traffic']        = min( $normalized['maximum_experimental_traffic'], max( 10, $normalized['minimum_exploration_traffic'] ) );
		$normalized['maximum_runtime_days']               = max( $normalized['minimum_runtime_days'], $normalized['maximum_runtime_days'] );
		$normalized['context_ttl_seconds']                = max( 300, min( DAY_IN_SECONDS, $normalized['context_ttl_seconds'] ) );
		return $normalized;
	}
}
