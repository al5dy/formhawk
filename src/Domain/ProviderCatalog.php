<?php

namespace Formhawk\Domain;

final class ProviderCatalog {
	const GENERIC   = 'html';
	const CF7       = 'cf7';
	const WPFORMS   = 'wpforms';
	const ELEMENTOR = 'elementor';

	const CAP_FRONTEND_TRACKING      = 'frontend_tracking';
	const CAP_SERVER_SUCCESS         = 'server_success';
	const CAP_SERVER_FAILURE         = 'server_failure';
	const CAP_SERVER_VALIDATION      = 'server_validation';
	const CAP_MAIL_SUCCESS           = 'mail_success';
	const CAP_MAIL_FAILURE           = 'mail_failure';
	const CAP_MULTI_STEP             = 'multi_step';
	const CAP_STABLE_FIELD_IDS       = 'stable_field_ids';
	const CAP_DYNAMIC_RENDERING      = 'dynamic_rendering';
	const CAP_SUBMISSION_ATTRIBUTION = 'submission_attribution';
	const CAP_FIELD_ROI              = 'field_roi';

	public static function ids() {
		return array( self::GENERIC, self::CF7, self::WPFORMS, self::ELEMENTOR );
	}

	public static function is_known( $provider ) {
		return in_array( (string) $provider, self::ids(), true );
	}

	public static function is_placement_scoped( $provider ) {
		return self::GENERIC === $provider;
	}

	public static function server_success_ids() {
		return array( self::CF7, self::WPFORMS, self::ELEMENTOR );
	}

	public static function has_server_success( $provider ) {
		return in_array( $provider, self::server_success_ids(), true );
	}

	public static function has_server_validation( $provider ) {
		return in_array( $provider, array( self::CF7, self::WPFORMS, self::ELEMENTOR ), true );
	}

	public static function has_server_failure( $provider ) {
		return in_array( $provider, array( self::CF7, self::ELEMENTOR ), true );
	}
}
