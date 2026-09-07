<?php

namespace Formhawk\Outcomes;

interface OutcomeSourceInterface {
	public function id();
	public function capabilities();
	public function register();
}
