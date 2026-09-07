<?php

namespace Formhawk\CRO\Experiments;

final class Variant {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function id() {
		return isset( $this->data['id'] ) ? absint( $this->data['id'] ) : 0;
	}

	public function config() {
		$config = isset( $this->data['mutation_config'] ) ? json_decode( $this->data['mutation_config'], true ) : array();
		return is_array( $config ) ? $config : array();
	}

	public function to_array() {
		return $this->data;
	}
}
