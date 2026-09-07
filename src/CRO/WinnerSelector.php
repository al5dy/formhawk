<?php

namespace Formhawk\CRO;

final class WinnerSelector {
	private $statistics;

	public function __construct( StatisticalEngine $statistics = null ) {
		$this->statistics = $statistics ? $statistics : new StatisticalEngine();
	}

	public function select( array $control, array $variant, array $policy, $runtime_days ) {
		return $this->statistics->evaluate( $control, $variant, $policy, $runtime_days );
	}
}
