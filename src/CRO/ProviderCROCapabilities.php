<?php

namespace Formhawk\CRO;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\Domain\ProviderCatalog;

final class ProviderCROCapabilities {
	public static function matrix() {
		$presentation = array(
			ExperimentType::SUBMIT_BUTTON,
			ExperimentType::FIELD_ORDER,
			ExperimentType::PROGRESSIVE_DISCLOSURE,
			ExperimentType::MULTI_STEP,
			ExperimentType::LABEL_PRESENTATION,
			ExperimentType::PLACEHOLDER_PRESENTATION,
		);

		$matrix = array(
			ProviderCatalog::CF7       => array(
				'mutations'         => $presentation,
				'confirmed_success' => true,
				'server_validation' => true,
				'server_failure'    => true,
				'evidence_level'    => 'provider_confirmed',
			),
			ProviderCatalog::WPFORMS   => array(
				'mutations'         => $presentation,
				'confirmed_success' => true,
				'server_validation' => true,
				'server_failure'    => false,
				'evidence_level'    => 'provider_confirmed',
			),
			ProviderCatalog::ELEMENTOR => array(
				'mutations'         => $presentation,
				'confirmed_success' => true,
				'server_validation' => true,
				'server_failure'    => true,
				'evidence_level'    => 'provider_confirmed',
			),
			ProviderCatalog::GENERIC   => array(
				'mutations'         => $presentation,
				'confirmed_success' => false,
				'server_validation' => false,
				'server_failure'    => false,
				'evidence_level'    => 'observed',
			),
		);

		/** @param array $matrix Provider CRO capability matrix. */
		return apply_filters( 'formhawk_cro_provider_capabilities', $matrix );
	}

	public static function supports( $provider, $mutation ) {
		$matrix = self::matrix();
		return isset( $matrix[ $provider ]['mutations'] ) && in_array( $mutation, $matrix[ $provider ]['mutations'], true );
	}

	public static function evidence_level( $provider ) {
		$matrix = self::matrix();
		return isset( $matrix[ $provider ]['evidence_level'] ) ? $matrix[ $provider ]['evidence_level'] : 'observed';
	}
}
