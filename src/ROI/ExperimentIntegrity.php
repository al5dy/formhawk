<?php

namespace Formhawk\ROI;

/** Rejects experiment evidence when assignment balance indicates leakage. */
final class ExperimentIntegrity {
	public function validate_aggregates( array $control, array $variant ) {
		foreach ( array( $control, $variant ) as $arm ) {
			$assignments = (int) ( $arm['assignments'] ?? $arm['visitors'] ?? 0 );
			$conversions = (int) ( $arm['confirmed_successes'] ?? $arm['conversions'] ?? 0 );
			$submissions = (int) ( $arm['submissions'] ?? $conversions );
			if ( $assignments < 0 || $conversions < 0 || $conversions > $assignments ) {
				return array(
					'valid'  => false,
					'reason' => 'conversions_exceed_assignments',
				);
			}
			if ( $submissions < 0 || ( isset( $arm['known'] ) && ( (int) $arm['known'] < 0 || (int) $arm['known'] > $submissions ) ) ) {
				return array(
					'valid'  => false,
					'reason' => 'outcomes_exceed_submissions',
				);
			}
		}
		return array(
			'valid'  => true,
			'reason' => '',
		);
	}

	public function has_assignment_leakage( array $cohorts ) {
		$expected = isset( $cohorts['expected_variant_share'] ) ? $cohorts['expected_variant_share'] : 0.5;
		$cells    = array(
			array(
				'control' => $cohorts['control']['visitors'],
				'variant' => $cohorts['variant']['visitors'],
			),
		);
		if ( isset( $cohorts['balance_cells'] ) && is_array( $cohorts['balance_cells'] ) ) {
			$cells = array_merge( $cells, $cohorts['balance_cells'] );
		}
		foreach ( $cells as $cell ) {
			$total = absint( $cell['control'] ) + absint( $cell['variant'] );
			if ( $total < 100 ) {
				continue;
			}
			$share          = absint( $cell['variant'] ) / $total;
			$standard_error = sqrt( max( 1.0E-9, $expected * ( 1 - $expected ) / $total ) );
			if ( abs( $share - $expected ) > max( 0.10, 4 * $standard_error ) ) {
				return true;
			}
		}
		return false;
	}
}
