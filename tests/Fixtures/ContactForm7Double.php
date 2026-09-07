<?php

namespace Formhawk\Tests\Fixtures;

final class ContactForm7Double {
	private $id;
	private $title;
	private $tags;

	public function __construct( $id, $title, array $tags = array() ) {
		$this->id    = $id;
		$this->title = $title;
		$this->tags  = $tags;
	}

	public function id() {
		return $this->id;
	}

	public function title() {
		return $this->title;
	}

	public function scan_form_tags() {
		return $this->tags;
	}
}
