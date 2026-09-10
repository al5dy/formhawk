<?php

namespace Formhawk\MinimumForm\Domain;

/** Append-only evidence record for one semantic field experiment. */
final class MinimumFormDecision {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function data() {
		return $this->data;
	}

	public function is_winner() {
		return 'promote' === ( $this->data['decision'] ?? '' );
	}
}
