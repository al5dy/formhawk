<?php

namespace Formhawk\MinimumForm;

/** Deterministic explainable score; never used to promote an experiment. */
final class FormEfficiencyScore {
	public function calculate( array $run, array $fields, array $decisions ) {
		$original = max( 1, absint( $run['original_field_count'] ?? count( $fields ) ) );
		$current  = min( $original, absint( $run['current_field_count'] ?? $original ) );
		$friction = 0.0;
		$evidence = 0;
		foreach ( $fields as $field ) {
			$metrics   = isset( $field['metrics'] ) && is_array( $field['metrics'] ) ? $field['metrics'] : array();
			$friction += max( 0, min( 1, (float) ( $metrics['friction_score'] ?? 0 ) ) );
			$evidence += in_array( $field['confidence'] ?? '', array( 'medium', 'high' ), true ) ? 1 : 0;
		}
		$average_friction = $fields ? $friction / count( $fields ) : 0;
		$evidence_quality = $fields ? $evidence / count( $fields ) : 0;
		$proven_positive  = count(
			array_filter(
				$decisions,
				static function ( $decision ) {
					return 'promote' === ( $decision['decision'] ?? '' ) && (float) ( $decision['business_lift'] ?? 0 ) > 0;
				}
			)
		);
		$simplification   = ( $original - $current ) / $original;
		$score            = 55 + 20 * $simplification + 15 * ( 1 - $average_friction ) + 10 * $evidence_quality + min( 5, $proven_positive );
		return max( 0, min( 100, (int) round( $score ) ) );
	}
}
