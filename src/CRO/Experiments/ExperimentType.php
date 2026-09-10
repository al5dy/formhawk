<?php

namespace Formhawk\CRO\Experiments;

final class ExperimentType {
	const FIELD_ORDER              = 'field_order';
	const PROGRESSIVE_DISCLOSURE   = 'progressive_disclosure';
	const MULTI_STEP               = 'multi_step';
	const SUBMIT_BUTTON            = 'submit_button';
	const LABEL_PRESENTATION       = 'label_presentation';
	const PLACEHOLDER_PRESENTATION = 'placeholder_presentation';
	const REMOVE_FIELD             = 'remove_field';
	const MAKE_OPTIONAL            = 'make_optional';
	const MAKE_REQUIRED            = 'make_required';

	public static function all() {
		return array(
			self::FIELD_ORDER,
			self::PROGRESSIVE_DISCLOSURE,
			self::MULTI_STEP,
			self::SUBMIT_BUTTON,
			self::LABEL_PRESENTATION,
			self::PLACEHOLDER_PRESENTATION,
			self::REMOVE_FIELD,
			self::MAKE_OPTIONAL,
			self::MAKE_REQUIRED,
		);
	}
}
