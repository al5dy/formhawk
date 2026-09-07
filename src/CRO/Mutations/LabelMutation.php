<?php

namespace Formhawk\CRO\Mutations;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\CRO\ProviderCROCapabilities;

final class LabelMutation implements MutationInterface {
	public function type() {
		return ExperimentType::LABEL_PRESENTATION;
	}

	public function normalize( array $config ) {
		$position = isset( $config['position'] ) ? sanitize_key( $config['position'] ) : '';
		return in_array( $position, array( 'above', 'inline' ), true ) ? array( 'position' => $position ) : null;
	}

	public function supports( $provider ) {
		return ProviderCROCapabilities::supports( $provider, $this->type() );
	}
}
