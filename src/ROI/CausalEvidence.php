<?php

namespace Formhawk\ROI;

final class CausalEvidence {
	const OBSERVATIONAL       = 'observational';
	const QUASI_EXPERIMENTAL  = 'quasi_experimental';
	const EXPERIMENTAL        = 'experimental';
	const STRONG_EXPERIMENTAL = 'strong_experimental';

	public static function all() {
		return array( self::OBSERVATIONAL, self::QUASI_EXPERIMENTAL, self::EXPERIMENTAL, self::STRONG_EXPERIMENTAL ); }
	public static function is_causal( $level ) {
		return in_array( $level, array( self::EXPERIMENTAL, self::STRONG_EXPERIMENTAL ), true ); }
}
