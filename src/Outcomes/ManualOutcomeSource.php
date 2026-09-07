<?php

namespace Formhawk\Outcomes;

/** Marker adapter documenting the first-party manual outcome source. */
final class ManualOutcomeSource implements OutcomeSourceInterface {
	public function id() {
		return 'manual'; }
	public function capabilities() {
		return array( 'record_outcomes', 'record_value_adjustments' ); }
	public function register() {}
}
