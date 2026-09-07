<?php

namespace Formhawk\Tests\Fixtures;

final class ElementorHandlerDouble {
	public $errors;
	public $is_success;
	public $messages;

	public function __construct( array $errors = array(), $is_success = true, array $messages = array() ) {
		$this->errors     = $errors;
		$this->is_success = $is_success;
		$this->messages   = array_merge(
			array(
				'success'     => '',
				'error'       => '',
				'admin_error' => '',
			),
			$messages
		);
	}
}
