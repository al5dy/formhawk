<?php

namespace Formhawk\Outcomes;

final class OutcomeStatus {
	const SUBMITTED        = 'submitted';
	const QUALIFIED        = 'qualified';
	const UNQUALIFIED      = 'unqualified';
	const WON              = 'won';
	const LOST             = 'lost';
	const SPAM             = 'spam';
	const DUPLICATE        = 'duplicate';
	const UNKNOWN          = 'unknown';
	const VALUE_ADJUSTMENT = 'value_adjustment';

	public static function all() {
		return array( self::SUBMITTED, self::QUALIFIED, self::UNQUALIFIED, self::WON, self::LOST, self::SPAM, self::DUPLICATE, self::UNKNOWN, self::VALUE_ADJUSTMENT );
	}

	public static function states() {
		return array( self::SUBMITTED, self::QUALIFIED, self::UNQUALIFIED, self::WON, self::LOST, self::SPAM, self::DUPLICATE, self::UNKNOWN );
	}

	public static function is_state( $status ) {
		return in_array( $status, self::states(), true );
	}
}
