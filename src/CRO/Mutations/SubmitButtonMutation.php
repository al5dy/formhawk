<?php

namespace Formhawk\CRO\Mutations;

use Formhawk\CRO\Experiments\ExperimentType;
use Formhawk\CRO\ProviderCROCapabilities;

final class SubmitButtonMutation implements MutationInterface {

	public function type() {
		return ExperimentType::SUBMIT_BUTTON;
	}

	public function normalize( array $config ) {
		$text = isset( $config['text'] ) && is_scalar( $config['text'] ) ? sanitize_text_field( (string) $config['text'] ) : '';
		return in_array( $text, $this->allowed(), true ) ? array( 'text' => $text ) : null;
	}

	public function supports( $provider ) {
		return ProviderCROCapabilities::supports( $provider, $this->type() );
	}

	private function allowed() {
		return array(
			__( 'Send request', 'formhawk' ),
			__( 'Get a quote', 'formhawk' ),
			__( 'Request information', 'formhawk' ),
			__( 'Contact us', 'formhawk' ),
			__( 'Continue', 'formhawk' ),
		);
	}
}
