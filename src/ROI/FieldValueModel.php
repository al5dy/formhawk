<?php

namespace Formhawk\ROI;

/** Deterministic robust bootstrap for zero-inflated, skewed per-visitor revenue. */
final class FieldValueModel {
	const MODEL_VERSION = 'robust-bootstrap-1.0';

	public function compare( array $control_values, $control_visitors, array $variant_values, $variant_visitors, $seed = 104729 ) {
		$control_visitors = max( count( $control_values ), absint( $control_visitors ) );
		$variant_visitors = max( count( $variant_values ), absint( $variant_visitors ) );
		$c_total          = array_sum( $control_values );
		$v_total          = array_sum( $variant_values );
		$c_actual         = $control_visitors ? $c_total / $control_visitors : 0.0;
		$v_actual         = $variant_visitors ? $v_total / $variant_visitors : 0.0;
		$combined         = array_merge( array_map( 'abs', $control_values ), array_map( 'abs', $variant_values ) );
		sort( $combined, SORT_NUMERIC );
		$cap         = $combined ? $combined[ min( count( $combined ) - 1, (int) floor( 0.95 * ( count( $combined ) - 1 ) ) ) ] : 0;
		$c_robust    = $this->winsorize( $control_values, $cap );
		$v_robust    = $this->winsorize( $variant_values, $cap );
		$iterations  = 500;
		$differences = array();
		$state       = absint( $seed ) ? absint( $seed ) : 1;
		for ( $iteration = 0; $iteration < $iterations; ++$iteration ) {
			$c_mean        = $this->resample_mean( $c_robust, $control_visitors, $state );
			$v_mean        = $this->resample_mean( $v_robust, $variant_visitors, $state );
			$differences[] = $v_mean - $c_mean;
		}
		sort( $differences, SORT_NUMERIC );
		$beneficial    = count(
			array_filter(
				$differences,
				static function ( $value ) {
					return $value > 0;
				}
			)
		);
		$expected_loss = array_sum(
			array_map(
				static function ( $difference ) {
					return max( 0, -$difference );
				},
				$differences
			)
		) / $iterations;
		return array(
			'model_version'          => self::MODEL_VERSION,
			'control_rpv_minor'      => $c_actual,
			'variant_rpv_minor'      => $v_actual,
			'impact_rpv_minor'       => $v_actual - $c_actual,
			'probability_beneficial' => $beneficial / $iterations,
			'expected_loss_minor'    => $expected_loss,
			'robust_interval_minor'  => array( $differences[12], $differences[487] ),
			'winsor_limit_minor'     => $cap,
			'control_revenue_minor'  => $c_total,
			'variant_revenue_minor'  => $v_total,
			'revenue_samples'        => count( $control_values ) + count( $variant_values ),
		);
	}

	private function winsorize( array $values, $cap ) {
		if ( $cap <= 0 ) {
			return $values; }
		return array_map(
			static function ( $value ) use ( $cap ) {
				return max( -$cap, min( $cap, (int) $value ) );
			},
			$values
		);
	}

	private function resample_mean( array $values, $visitors, &$state ) {
		if ( $visitors < 1 ) {
			return 0.0; }
		$draws           = min( 512, $visitors );
		$sum             = 0;
		$count           = count( $values );
		$population_mean = array_sum( $values ) / $visitors;
		for ( $index = 0; $index < $draws; ++$index ) {
			$state  = (int) ( ( 1664525 * $state + 1013904223 ) % 4294967296 );
			$sample = $state % $visitors;
			if ( $sample < $count ) {
				$sum += $values[ $sample ]; }
		}
		$sample_mean = $sum / $draws;
		// Subsampled bootstrap preserves bounded runtime; this finite-size scaling
		// restores the variance of a full n-out-of-n resample for large cohorts.
		return $population_mean + ( $sample_mean - $population_mean ) * sqrt( $draws / $visitors );
	}
}
