<?php

namespace Formhawk\CRO\Experiments;

final class Experiment {
	private $data;

	public function __construct( array $data ) {
		$this->data = $data;
	}

	public function id() {
		return isset( $this->data['id'] ) ? absint( $this->data['id'] ) : 0;
	}

	public function form_id() {
		return isset( $this->data['form_id'] ) ? absint( $this->data['form_id'] ) : 0;
	}

	public function status() {
		return isset( $this->data['status'] ) ? (string) $this->data['status'] : '';
	}

	public function to_array() {
		return $this->data;
	}
}
