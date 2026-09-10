<?php

namespace Formhawk\MinimumForm;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\MinimumForm\Domain\MinimumFormCandidate;

/** Selects one highest-value, evidence-backed semantic mutation at a time. */
final class MinimumFormCandidateSelector {
	private $safety;

	public function __construct( ?FieldSafetyClassifier $safety = null ) {
		$this->safety = $safety ? $safety : new FieldSafetyClassifier();
	}

	public function select( array $fields, FieldDependencyGraph $dependencies, array $capabilities, array $history = array(), $aggressiveness = 'balanced' ) {
		$candidates = array();
		$completed  = array();
		foreach ( $history as $record ) {
			if ( is_array( $record ) ) {
				$completed[] = absint( $record['field_definition_id'] ?? 0 ) . '|' . sanitize_key( $record['mutation_type'] ?? '' );
			}
		}
		foreach ( $fields as $field ) {
			$candidate = $this->candidate( $field, $dependencies, $capabilities, $aggressiveness );
			if ( ! $candidate ) {
				continue;
			}
			$data = $candidate->data();
			if ( in_array( absint( $data['field_definition_id'] ) . '|' . $data['mutation_type'], $completed, true ) ) {
				continue;
			}
			$candidates[] = $candidate;
		}
		usort(
			$candidates,
			static function ( MinimumFormCandidate $left, MinimumFormCandidate $right ) {
				return $right->score() <=> $left->score();
			}
		);
		return isset( $candidates[0] ) ? $candidates[0] : null;
	}

	private function candidate( array $field, FieldDependencyGraph $dependencies, array $capabilities, $aggressiveness ) {
		$safety     = $this->safety->classify( $field );
		$verdict    = sanitize_key( $field['verdict'] ?? 'unknown' );
		$confidence = sanitize_key( $field['confidence'] ?? 'insufficient' );
		$field_key  = sanitize_key( $field['normalized_key'] ?? $field['key'] ?? '' );
		if ( '' === $field_key || FieldSafety::FORBIDDEN === $safety['safety'] ) {
			return null;
		}
		if ( in_array( $verdict, array( 'money_maker', 'qualifier', 'free_value', 'unknown' ), true ) ) {
			return null;
		}
		$required_only_protection = FieldSafety::PROTECTED === $safety['safety'] && 'required_semantics' === $safety['reason'];
		if ( FieldSafety::PROTECTED === $safety['safety'] && ! $required_only_protection ) {
			return null;
		}
		if ( 'conservative' === $aggressiveness && FieldSafety::SAFE !== $safety['safety'] && ! $required_only_protection ) {
			return null;
		}
		if ( 'balanced' === $aggressiveness && FieldSafety::CAUTION === $safety['safety'] && 'high' !== $confidence ) {
			return null;
		}

		$mutation = '';
		if ( ! $required_only_protection && 'conversion_killer' === $verdict && 'high' === $confidence && ! empty( $capabilities[ ProviderCapabilityMatrix::REMOVE_FIELD ] ) && $dependencies->can_remove( $field_key )['safe'] ) {
			$mutation = ExperimentType::REMOVE_FIELD;
		} elseif ( $required_only_protection && in_array( $verdict, array( 'conversion_killer', 'neutral' ), true ) && in_array( $confidence, array( 'medium', 'high' ), true ) && ! empty( $capabilities[ ProviderCapabilityMatrix::MAKE_OPTIONAL ] ) ) {
			$mutation = ExperimentType::MAKE_OPTIONAL;
		}
		if ( '' === $mutation ) {
			return null;
		}

		$allowed = apply_filters(
			'formhawk_minimum_form_allowed_mutations',
			array( ExperimentType::REMOVE_FIELD, ExperimentType::MAKE_OPTIONAL ),
			$field,
			$aggressiveness
		);
		if ( ! is_array( $allowed ) || ! in_array( $mutation, $allowed, true ) ) {
			return null;
		}

		$metrics      = isset( $field['metrics'] ) && is_array( $field['metrics'] ) ? $field['metrics'] : array();
		$friction     = max( 0, min( 1, (float) ( $metrics['friction_score'] ?? 0 ) ) );
		$sample       = absint( $metrics['sample_size'] ?? 0 );
		$value_impact = isset( $metrics['revenue_impact'] ) && null !== $metrics['revenue_impact'] ? (float) $metrics['revenue_impact'] : (float) ( $metrics['qualified_impact'] ?? 0 );
		$confidence_w = 'high' === $confidence ? 1.0 : 0.65;
		$risk         = FieldSafety::CAUTION === $safety['safety'] ? 0.35 : 0.10;
		$score        = 100 * ( 0.45 * $friction + 0.30 * max( 0, -$value_impact ) + 0.20 * $confidence_w + 0.05 * min( 1, $sample / 5000 ) - 0.35 * $risk );
		$score        = (float) apply_filters( 'formhawk_minimum_form_candidate_score', $score, $field, $mutation );
		return new MinimumFormCandidate(
			array(
				'field_definition_id'         => absint( $field['field_definition_id'] ?? $field['id'] ?? 0 ),
				'field_key'                   => $field_key,
				'field_label'                 => (string) ( $field['label'] ?? $field_key ),
				'field_type'                  => sanitize_key( $field['field_type'] ?? $field['type'] ?? '' ),
				'mutation_type'               => $mutation,
				'safety'                      => $safety['safety'],
				'safety_reason'               => $safety['reason'],
				'verdict'                     => $verdict,
				'confidence'                  => $confidence,
				'sample_size'                 => $sample,
				'friction_score'              => $friction,
				'expected_upside'             => max( 0, -$value_impact ),
				'risk_score'                  => $risk,
				'dependency_verified'         => ExperimentType::REMOVE_FIELD === $mutation,
				'provider_semantics_verified' => ExperimentType::MAKE_OPTIONAL === $mutation,
				'score'                       => $score,
			)
		);
	}
}
