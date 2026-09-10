<?php

namespace Formhawk\MinimumForm\Domain;

final class MinimumFormCandidate {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function data() {
		return $this->data;
	}

	public function score() {
		return (float) ( $this->data['score'] ?? 0 );
	}
}
