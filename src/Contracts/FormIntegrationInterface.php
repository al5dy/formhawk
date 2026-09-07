<?php

namespace Formhawk\Contracts;

interface FormIntegrationInterface {
	public function id();

	public function label();

	public function is_available();

	public function capabilities();

	public function register();
}
