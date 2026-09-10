<?php

namespace Formhawk\CRO;

use Formhawk\CRO\Experiments\ExperimentType;

final class VariantGenerator {
	private $registry;

	public function __construct( ?MutationRegistry $registry = null ) {
		$this->registry = $registry ? $registry : new MutationRegistry();
	}

	public function generate( array $form, array $opportunity, array $baseline ) {
		$type     = $opportunity['type'];
		$mutation = $this->registry->get( $type );
		if ( ! $mutation || ! $mutation->supports( $form['provider'] ) ) {
			return null;
		}
		$config = $this->candidate_config( $form, $opportunity );
		$config = $mutation->normalize( $config );
		if ( ! is_array( $config ) ) {
			return null;
		}
		$candidate = array(
			'type'   => $type,
			'config' => $config,
		);
		/**
		 * @param array $candidate Candidate mutation.
		 * @param array $form Form metadata.
		 * @param array $opportunity Ranked opportunity.
		 */
		$candidate = apply_filters( 'formhawk_cro_variant', $candidate, $form, $opportunity );
		if ( ! is_array( $candidate ) || ! isset( $candidate['type'], $candidate['config'] ) || $type !== $candidate['type'] || ! is_array( $candidate['config'] ) ) {
			return null;
		}
		$config = $mutation->normalize( $candidate['config'] );
		if ( ! is_array( $config ) ) {
			return null;
		}
		$candidate['config'] = $config;

		return array(
			'control' => array(
				'name'          => __( 'Control', 'formhawk' ),
				'mutation_type' => 'baseline',
				'config'        => array( 'mutations' => array_values( $baseline ) ),
			),
			'variant' => array(
				'name'          => __( 'Autopilot variant', 'formhawk' ),
				'mutation_type' => $type,
				'config'        => array( 'mutations' => array_merge( array_values( $baseline ), array( $candidate ) ) ),
			),
		);
	}

	private function candidate_config( array $form, array $opportunity ) {
		switch ( $opportunity['type'] ) {
			case ExperimentType::FIELD_ORDER:
				return array( 'field_order' => array( '__all_except_target__', $opportunity['field_key'] ) );
			case ExperimentType::PROGRESSIVE_DISCLOSURE:
				return array( 'fields' => array( $opportunity['field_key'] ) );
			case ExperimentType::REMOVE_FIELD:
				return array(
					'field_key'           => $opportunity['field_key'],
					'safety'              => isset( $opportunity['safety'] ) ? $opportunity['safety'] : '',
					'dependency_verified' => ! empty( $opportunity['dependency_verified'] ),
				);
			case ExperimentType::MAKE_OPTIONAL:
				return array(
					'field_key'                   => $opportunity['field_key'],
					'provider_semantics_verified' => ! empty( $opportunity['provider_semantics_verified'] ),
				);
			case ExperimentType::MAKE_REQUIRED:
				return array(
					'field_key'                   => $opportunity['field_key'],
					'provider_semantics_verified' => ! empty( $opportunity['provider_semantics_verified'] ),
					'risk_authorized'             => ! empty( $opportunity['risk_authorized'] ),
				);
			case ExperimentType::MULTI_STEP:
				return array( 'steps' => array( array( '__first_half__' ), array( '__second_half__' ) ) );
			case ExperimentType::SUBMIT_BUTTON:
			default:
				$title = strtolower( isset( $form['title'] ) ? $form['title'] : '' );
				$text  = false !== strpos( $title, 'quote' ) ? __( 'Get a quote', 'formhawk' ) : __( 'Send request', 'formhawk' );
				return array( 'text' => $text );
		}
	}
}
