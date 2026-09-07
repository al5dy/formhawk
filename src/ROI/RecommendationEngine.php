<?php

namespace Formhawk\ROI;

final class RecommendationEngine {
	public function recommend( array $metrics, $confidence, $evidence_level ) {
		if ( ConfidenceCalculator::INSUFFICIENT === $confidence ) {
			return $this->result( 'unknown', 'insufficient_data', 0, 'minimum_sample_or_coverage' );
		}
		$friction   = max( 0, min( 1, (float) ( isset( $metrics['friction_score'] ) ? $metrics['friction_score'] : 0 ) ) );
		$submission = isset( $metrics['submission_impact'] ) ? $metrics['submission_impact'] : null;
		$qualified  = isset( $metrics['qualified_impact'] ) ? $metrics['qualified_impact'] : null;
		$revenue    = isset( $metrics['revenue_impact'] ) ? $metrics['revenue_impact'] : null;
		$business   = null !== $revenue ? $revenue : $qualified;
		if ( null === $business || null === $submission ) {
			return $this->result( 'unknown', 'test', 25, 'causal_roi_unavailable' );
		}
		$signal = max( -1, min( 1, $business ) );
		$score  = max( 0, min( 100, (int) round( 50 + 40 * $signal - 10 * $friction ) ) );
		$causal = CausalEvidence::is_causal( $evidence_level );
		if ( $friction >= 0.12 && $business >= 0.10 ) {
			return $this->result( 'money_maker', $causal ? 'keep' : 'test', $score, 'value_outweighs_friction' );
		}
		if ( $friction >= 0.12 && $business <= 0.02 ) {
			$recommendation = $causal ? 'make_optional' : 'test';
			if ( $causal && isset( $metrics['intervention']['alternative'] ) && in_array( $metrics['intervention']['alternative'], array( 'move_later', 'progressive_disclosure' ), true ) ) {
				$recommendation = 'move_later';
			}
			return $this->result( 'conversion_killer', $recommendation, $score, 'friction_without_value' );
		}
		if ( $friction < 0.05 && $business >= 0.08 ) {
			return $this->result( 'free_value', 'keep', $score, 'value_with_low_friction' );
		}
		if ( $submission <= -0.05 && ( ( null !== $qualified && $qualified >= 0.10 ) || $business >= 0.05 ) ) {
			return $this->result( 'qualifier', 'keep', $score, 'quality_filter' );
		}
		if ( abs( $business ) < 0.03 && abs( $submission ) < 0.03 ) {
			return $this->result( 'neutral', 'keep', $score, 'no_material_effect' );
		}
		return $this->result( 'unknown', 'test', $score, 'uncertain_tradeoff' );
	}

	private function result( $verdict, $recommendation, $score, $reason ) {
		$result   = array(
			'verdict'        => $verdict,
			'recommendation' => $recommendation,
			'score'          => $score,
			'reason'         => $reason,
		);
		$filtered = apply_filters( 'formhawk_field_roi_recommendation', $result );
		return is_array( $filtered ) ? $filtered : $result;
	}
}
