<?php

namespace Formhawk\CRO\Experiments;

final class ExperimentStatus {
	const SUGGESTED           = 'suggested';
	const AWAITING_APPROVAL   = 'awaiting_approval';
	const RUNNING             = 'running';
	const PAUSED_GUARDRAIL    = 'paused_guardrail';
	const PAUSED_MANUAL       = 'paused_manual';
	const PROMOTED_MONITORING = 'promoted_monitoring';
	const COMPLETED           = 'completed';
	const REJECTED            = 'rejected';
	const INCONCLUSIVE        = 'inconclusive';
	const MANUALLY_STOPPED    = 'manually_stopped';
	const ROLLED_BACK         = 'rolled_back';

	public static function active() {
		return array( self::SUGGESTED, self::AWAITING_APPROVAL, self::RUNNING, self::PAUSED_GUARDRAIL, self::PAUSED_MANUAL, self::PROMOTED_MONITORING );
	}

	public static function runtime() {
		return array( self::RUNNING, self::PROMOTED_MONITORING );
	}

	public static function all() {
		return array_merge( self::active(), array( self::COMPLETED, self::REJECTED, self::INCONCLUSIVE, self::MANUALLY_STOPPED, self::ROLLED_BACK ) );
	}
}
