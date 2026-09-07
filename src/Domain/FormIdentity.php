<?php

namespace Formhawk\Domain;

final class FormIdentity {
	/**
	 * Preserve the 0.1.x key format for CF7 and generic forms so upgrades keep
	 * their existing aggregate history attached to the same records.
	 */
	public static function key( $provider, $provider_form_id, $page_path ) {
		$identity = ProviderCatalog::is_placement_scoped( $provider )
			? $provider_form_id . '|' . $page_path
			: $provider_form_id;

		return $provider . ':' . sha1( $identity );
	}
}
