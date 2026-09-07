<?php

namespace Formhawk\Infrastructure;

use Formhawk\Contracts\ModuleGateInterface;

/** Licensing-neutral module boundary for a separate Pro add-on to filter. */
final class ModuleGate implements ModuleGateInterface {
	public function enabled( $module ) {
		return (bool) apply_filters( 'formhawk_module_enabled', true, sanitize_key( $module ) );
	}
}
