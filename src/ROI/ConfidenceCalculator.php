<?php

namespace Formhawk\ROI;

final class ConfidenceCalculator {
	const LOW          = 'low';
	const MEDIUM       = 'medium';
	const HIGH         = 'high';
	const VERY_HIGH    = 'very_high';
	const INSUFFICIENT = 'insufficient';

	public function calculate( array $evidence ) {
		$minimum  = max( 20, absint( apply_filters( 'formhawk_field_roi_min_sample', 100 ) ) );
		$control  = absint( isset( $evidence['control_visitors'] ) ? $evidence['control_visitors'] : 0 );
		$variant  = absint( isset( $evidence['variant_visitors'] ) ? $evidence['variant_visitors'] : 0 );
		$outcomes = absint( isset( $evidence['outcomes'] ) ? $evidence['outcomes'] : 0 );
		$coverage = max( 0, min( 1, (float) ( isset( $evidence['coverage'] ) ? $evidence['coverage'] : 0 ) ) );
		$level    = isset( $evidence['evidence_level'] ) ? $evidence['evidence_level'] : CausalEvidence::OBSERVATIONAL;

		if ( $control < $minimum || $variant < $minimum || $outcomes < 20 || $coverage < 0.35 ) {
			return self::INSUFFICIENT; }
		if ( $control < 500 || $variant < 500 || $outcomes < 50 || $coverage < 0.60 ) {
			return self::LOW; }
		if ( $control < 1500 || $variant < 1500 || $outcomes < 100 || $coverage < 0.75 ) {
			return self::MEDIUM; }
		if ( CausalEvidence::STRONG_EXPERIMENTAL === $level && $control >= 5000 && $variant >= 5000 && $outcomes >= 250 && $coverage >= 0.90 ) {
			return self::VERY_HIGH; }
		return self::HIGH;
	}
}
