<?php

namespace Formhawk\MinimumForm\Domain;

final class MinimumFormOptimizationRun {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function data() {
		return $this->data;
	}

	public function accepts_new_experiment() {
		return in_array( (string) ( $this->data['status'] ?? '' ), array( 'collecting', 'optimizing' ), true )
			&& empty( $this->data['active_experiment_id'] );
	}
}
