<?php

namespace Formhawk\MinimumForm\Domain;

/** Read model for the current optimized form state. */
final class MinimumFormProfile {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function data() {
		return $this->data;
	}

	public function status() {
		return isset( $this->data['status'] ) ? (string) $this->data['status'] : 'off';
	}

	public function progress() {
		$original = absint( $this->data['original_field_count'] ?? 0 );
		$decided  = absint( $this->data['decided_field_count'] ?? 0 );
		return array(
			'evaluated' => min( $original, $decided ),
			'total'     => $original,
		);
	}
}
