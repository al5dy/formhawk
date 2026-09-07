<?php

namespace Formhawk\ROI;

/** Rejects experiment evidence when assignment balance indicates leakage. */
final class ExperimentIntegrity {
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
