<?php

namespace Formhawk\Tests\Fixtures;

final class ContactForm7Double {
	private $id;
	private $title;

	public function __construct( $id, $title ) {
		$this->id    = $id;
		$this->title = $title;
	}

	public function id() {
		return $this->id;
	}

	public function title() {
		return $this->title;
	}
}
