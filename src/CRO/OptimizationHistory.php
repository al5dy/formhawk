<?php

namespace Formhawk\CRO;

final class OptimizationHistory {
	private $experiments;

	public function __construct( ExperimentRepository $experiments = null ) {
		$this->experiments = $experiments ? $experiments : new ExperimentRepository();
	}

	public function record( array $decision ) {
		return $this->experiments->add_history( $decision );
	}

	public function for_form( $form_id, $limit = 50 ) {
		return $this->experiments->history( $form_id, $limit );
	}
}
