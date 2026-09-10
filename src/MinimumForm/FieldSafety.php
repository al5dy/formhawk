<?php

namespace Formhawk\MinimumForm;

final class FieldSafety {
	const SAFE      = 'safe';
	const CAUTION   = 'caution';
	const PROTECTED = 'protected';
	const FORBIDDEN = 'forbidden';

	public static function all() {
		return array( self::SAFE, self::CAUTION, self::PROTECTED, self::FORBIDDEN );
	}
}
