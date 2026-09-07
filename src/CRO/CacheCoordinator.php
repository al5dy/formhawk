<?php

namespace Formhawk\CRO;

/** Requests cache invalidation only when an experiment changes runtime HTML behavior. */
final class CacheCoordinator {
	public function purge() {
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		// LiteSpeed Cache treats this public action as a full-page purge request.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache public integration hook.
		do_action( 'litespeed_purge_all', 'Formhawk Autopilot runtime changed' );
		// Other cache/CDN integrations can subscribe without a Core dependency.
		do_action( 'formhawk_cro_runtime_changed' );
	}
}
