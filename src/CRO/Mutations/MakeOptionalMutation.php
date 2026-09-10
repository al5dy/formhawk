<?php

namespace Formhawk\CRO\Mutations;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\MinimumForm\ProviderCapabilityMatrix;
use Formhawk\Support\Sanitizer;

final class MakeOptionalMutation implements MutationInterface {
	public function type() {
		return ExperimentType::MAKE_OPTIONAL;
	}

	public function normalize( array $config ) {
		$field_key = Sanitizer::identifier( $config['field_key'] ?? '', '' );
		return '' === $field_key ? null : array(
			'field_key'                   => $field_key,
			'provider_semantics_verified' => ! empty( $config['provider_semantics_verified'] ),
		);
	}

	public function supports( $provider ) {
		return ( new ProviderCapabilityMatrix() )->supports_mutation( $provider, ProviderCapabilityMatrix::MAKE_OPTIONAL );
	}
}
