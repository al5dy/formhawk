<?php

namespace Formhawk\CRO\Mutations;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\CRO\ProviderCROCapabilities;

final class PlaceholderMutation implements MutationInterface {
	public function type() {
		return ExperimentType::PLACEHOLDER_PRESENTATION;
	}

	public function normalize( array $config ) {
		$mode = isset( $config['mode'] ) ? sanitize_key( $config['mode'] ) : '';
		return in_array( $mode, array( 'preserve', 'deemphasize' ), true ) ? array( 'mode' => $mode ) : null;
	}

	public function supports( $provider ) {
		return ProviderCROCapabilities::supports( $provider, $this->type() );
	}
}
