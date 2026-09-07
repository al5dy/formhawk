<?php

namespace Formhawk\Outcomes;

/** Immutable integer-minor-unit value; floating-point money is never accepted. */
final class OutcomeValue {
	private $minor;
	private $currency;

	public function __construct( $minor, $currency ) {
		if ( ! is_int( $minor ) || ! OutcomeNormalizer::valid_currency( $currency ) ) {
			throw new \InvalidArgumentException( 'Invalid outcome value.' );
		}
		$this->minor    = $minor;
		$this->currency = strtoupper( $currency );
	}

	public function minor() {
		return $this->minor; }
	public function currency() {
		return $this->currency; }
}
