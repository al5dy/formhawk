<?php

namespace Formhawk\Integrations;

use Formhawk\Contracts\FormIntegrationInterface;
use Formhawk\Domain\ProviderCatalog;

final class GenericForm implements FormIntegrationInterface {
	public function id() {
		return ProviderCatalog::GENERIC;
	}

	public function label() {
		return __( 'Standard HTML', 'formhawk' );
	}

	public function is_available() {
		return true;
	}

	public function capabilities() {
		return array(
			ProviderCatalog::CAP_FRONTEND_TRACKING,
			ProviderCatalog::CAP_DYNAMIC_RENDERING,
			ProviderCatalog::CAP_REMOVE_FIELD,
			ProviderCatalog::CAP_FIELD_ORDER,
			ProviderCatalog::CAP_PROGRESSIVE_DISCLOSURE,
		);
	}

	public function register() {}
}
