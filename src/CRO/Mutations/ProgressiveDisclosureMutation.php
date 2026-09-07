<?php

namespace Formhawk\CRO\Mutations;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\CRO\ProviderCROCapabilities;
use Formhawk\Support\Sanitizer;

class ProgressiveDisclosureMutation implements MutationInterface {
	public function type() {
		return ExperimentType::PROGRESSIVE_DISCLOSURE;
	}

	public function normalize( array $config ) {
		$fields = array();
		foreach ( array_slice( isset( $config['fields'] ) && is_array( $config['fields'] ) ? $config['fields'] : array(), 0, 20 ) as $key ) {
			$key = Sanitizer::identifier( $key, '' );
			if ( '' !== $key && ! in_array( $key, $fields, true ) ) {
				$fields[] = $key;
			}
		}
		if ( ! $fields ) {
			return null;
		}
		return array(
			'fields' => $fields,
			'label'  => __( 'Add additional information', 'formhawk' ),
		);
	}

	public function supports( $provider ) {
		return ProviderCROCapabilities::supports( $provider, $this->type() );
	}
}
