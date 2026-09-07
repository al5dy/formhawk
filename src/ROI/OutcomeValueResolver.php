<?php

namespace Formhawk\ROI;

/** Applies configured values only where an eligible outcome has no actual revenue. */
final class OutcomeValueResolver {
	public function apply( array $cohorts, array $settings ) {
		foreach ( array( 'control', 'variant' ) as $arm ) {
			$won_value       = absint( isset( $settings['won_value_minor'] ) ? $settings['won_value_minor'] : 0 );
			$qualified_value = absint( isset( $settings['qualified_value_minor'] ) ? $settings['qualified_value_minor'] : 0 );
			$actual_won      = min( absint( $cohorts[ $arm ]['won'] ), absint( isset( $cohorts[ $arm ]['revenue_samples'] ) ? $cohorts[ $arm ]['revenue_samples'] : count( $cohorts[ $arm ]['revenue_values'] ) ) );
			if ( $won_value && $cohorts[ $arm ]['won'] > $actual_won ) {
				$cohorts[ $arm ]['revenue_values'] = array_merge( $cohorts[ $arm ]['revenue_values'], array_fill( 0, $cohorts[ $arm ]['won'] - $actual_won, $won_value ) );
			}
			if ( $qualified_value ) {
				$cohorts[ $arm ]['revenue_values'] = array_merge( $cohorts[ $arm ]['revenue_values'], array_fill( 0, max( 0, $cohorts[ $arm ]['qualified'] - $cohorts[ $arm ]['won'] ), $qualified_value ) );
			}
		}
		return $cohorts;
	}
}
