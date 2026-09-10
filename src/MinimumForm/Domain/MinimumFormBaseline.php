<?php

namespace Formhawk\MinimumForm\Domain;

/** Immutable baseline record; the original provider definition is never edited. */
final class MinimumFormBaseline {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function data() {
		return $this->data;
	}

	public function mutations() {
		return isset( $this->data['mutations'] ) && is_array( $this->data['mutations'] ) ? $this->data['mutations'] : array();
	}

	public function matches_schema( $fingerprint, $dependency_hash ) {
		return hash_equals( (string) ( $this->data['schema_fingerprint'] ?? '' ), (string) $fingerprint )
			&& hash_equals( (string) ( $this->data['dependency_hash'] ?? '' ), (string) $dependency_hash );
	}
}
