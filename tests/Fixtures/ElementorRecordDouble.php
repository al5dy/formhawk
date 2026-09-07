<?php

namespace Formhawk\Tests\Fixtures;

final class ElementorRecordDouble {
	private $settings;
	private $submitted_values;

	public function __construct( $id, $name, $post_id = 0, array $fields = array(), array $submitted_values = array() ) {
		$this->settings         = array(
			'id'           => $id,
			'form_name'    => $name,
			'form_post_id' => $post_id,
			'form_fields'  => $fields,
		);
		$this->submitted_values = $submitted_values;
	}

	public function get_form_settings( $key ) {
		return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : null;
	}
}
