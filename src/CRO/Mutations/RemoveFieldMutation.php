<?php

namespace Formhawk\CRO\Mutations;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\MinimumForm\ProviderCapabilityMatrix;
use Formhawk\Support\Sanitizer;

final class RemoveFieldMutation implements MutationInterface {
	public function type() {
		return ExperimentType::REMOVE_FIELD;
	}

	public function normalize( array $config ) {
		$field_key = Sanitizer::identifier( $config['field_key'] ?? '', '' );
		$safety    = isset( $config['safety'] ) ? sanitize_key( $config['safety'] ) : '';
		if ( '' === $field_key || ! in_array( $safety, array( 'safe', 'caution' ), true ) || empty( $config['dependency_verified'] ) ) {
			return null;
		}
		return array(
			'field_key'           => $field_key,
			'safety'              => $safety,
			'dependency_verified' => true,
		);
	}

	public function supports( $provider ) {
		return ( new ProviderCapabilityMatrix() )->supports_mutation( $provider, ProviderCapabilityMatrix::REMOVE_FIELD );
	}
}
