<?php

namespace Formhawk\CRO\Mutations;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\CRO\ProviderCROCapabilities;
use Formhawk\Support\Sanitizer;

final class MultiStepMutation implements MutationInterface {
	public function type() {
		return ExperimentType::MULTI_STEP;
	}

	public function normalize( array $config ) {
		$steps = array();
		foreach ( array_slice( isset( $config['steps'] ) && is_array( $config['steps'] ) ? $config['steps'] : array(), 0, 5 ) as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$keys = array();
			foreach ( array_slice( $step, 0, 12 ) as $key ) {
				$key = in_array( $key, array( '__first_half__', '__second_half__' ), true ) ? $key : Sanitizer::identifier( $key, '' );
				if ( '' !== $key && ! in_array( $key, $keys, true ) ) {
					$keys[] = $key;
				}
			}
			if ( $keys ) {
				$steps[] = $keys;
			}
		}
		return count( $steps ) >= 2 ? array( 'steps' => $steps ) : null;
	}

	public function supports( $provider ) {
		return ProviderCROCapabilities::supports( $provider, $this->type() );
	}
}
