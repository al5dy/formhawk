<?php

namespace Formhawk\Tests\Fixtures;

use Formhawk\Contracts\FormIntegrationInterface;

final class IntegrationDouble implements FormIntegrationInterface {
	private $provider;
	private $available;
	private $declared_capabilities;
	public $registrations = 0;

	public function __construct( $provider, $available, array $capabilities = array() ) {
		$this->provider              = $provider;
		$this->available             = $available;
		$this->declared_capabilities = $capabilities;
	}

	public function id() {
		return $this->provider;
	}

	public function label() {
		return $this->provider;
	}

	public function is_available() {
		return $this->available;
	}

	public function capabilities() {
		return $this->declared_capabilities;
	}

	public function register() {
		++$this->registrations;
	}
}
