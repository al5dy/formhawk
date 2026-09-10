<?php

namespace Formhawk\CRO;

use Formhawk\CRO\Mutations\FieldOrderMutation;
use Formhawk\CRO\Mutations\LabelMutation;
use Formhawk\CRO\Mutations\MultiStepMutation;
use Formhawk\CRO\Mutations\PlaceholderMutation;
use Formhawk\CRO\Mutations\ProgressiveDisclosureMutation;
use Formhawk\CRO\Mutations\SubmitButtonMutation;
use Formhawk\CRO\Mutations\MutationInterface;
use Formhawk\CRO\Mutations\RemoveFieldMutation;
use Formhawk\CRO\Mutations\MakeOptionalMutation;
use Formhawk\CRO\Mutations\MakeRequiredMutation;

final class MutationRegistry {
	private $mutations = array();

	public function __construct( array $mutations = array() ) {
		if ( ! $mutations ) {
			$mutations = array(
				new FieldOrderMutation(),
				new ProgressiveDisclosureMutation(),
				new MultiStepMutation(),
				new SubmitButtonMutation(),
				new LabelMutation(),
				new PlaceholderMutation(),
				new RemoveFieldMutation(),
				new MakeOptionalMutation(),
				new MakeRequiredMutation(),
			);
		}
		foreach ( $mutations as $mutation ) {
			if ( $mutation instanceof MutationInterface ) {
				$this->mutations[ $mutation->type() ] = $mutation;
			}
		}
	}

	public function get( $type ) {
		$mutations = $this->all();
		return isset( $mutations[ $type ] ) && $mutations[ $type ] instanceof MutationInterface ? $mutations[ $type ] : null;
	}

	public function all() {
		/** @param array $mutations Registered mutation strategies keyed by type. */
		$mutations = apply_filters( 'formhawk_cro_mutation_types', $this->mutations );
		return is_array( $mutations ) ? $mutations : $this->mutations;
	}
}
