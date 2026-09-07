<?php

namespace Formhawk\CRO\Mutations;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\CRO\ProviderCROCapabilities;
use Formhawk\Support\Sanitizer;

final class FieldOrderMutation implements MutationInterface {
	public function type() {
		return ExperimentType::FIELD_ORDER;
	}

	public function normalize( array $config ) {
		$order = array();
		foreach ( array_slice( isset( $config['field_order'] ) && is_array( $config['field_order'] ) ? $config['field_order'] : array(), 0, 30 ) as $key ) {
			$key = '__all_except_target__' === $key ? $key : Sanitizer::identifier( $key, '' );
			if ( '' !== $key && ! in_array( $key, $order, true ) ) {
				$order[] = $key;
			}
		}
		return count( $order ) >= 2 ? array( 'field_order' => $order ) : null;
	}

	public function supports( $provider ) {
		return ProviderCROCapabilities::supports( $provider, $this->type() );
	}
}
