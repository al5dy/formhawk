<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Admin\Admin;
use Formhawk\Analytics\EventIngestor;
use Formhawk\Analytics\FormRepository;
use Formhawk\Integrations\ContactForm7;
use Formhawk\Integrations\ElementorForms;
use Formhawk\Integrations\GenericForm;
use Formhawk\Integrations\IntegrationRegistry;
use Formhawk\Integrations\WPForms;
use PHPUnit\Framework\TestCase;

final class AdminIntegrationTest extends TestCase {
	private $user_id;
	private $get;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'submit_button' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		$this->user_id = get_current_user_id();
		// Test fixture snapshot; no request data is processed by the test itself.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->get = $_GET;
	}

	protected function tearDown(): void {
		wp_set_current_user( $this->user_id );
		$_GET = $this->get;
		parent::tearDown();
	}

	public function test_diagnostics_names_all_first_class_providers_and_degrades_gracefully() {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
			)
		);
		if ( empty( $admins ) ) {
			$this->markTestSkipped( 'No administrator is available for the admin rendering test.' );
		}
		wp_set_current_user( $admins[0]->ID );
		$_GET['tab'] = 'diagnostics';

		$forms    = new FormRepository();
		$ingestor = new EventIngestor( $forms );
		$registry = new IntegrationRegistry(
			array(
				new ContactForm7( $ingestor ),
				new WPForms( $ingestor ),
				new ElementorForms( $ingestor ),
				new GenericForm(),
			)
		);
		$admin    = new Admin( $forms, $registry );

		ob_start();
		$admin->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Contact Form 7', $html );
		$this->assertStringContainsString( 'WPForms', $html );
		$this->assertStringContainsString( 'Elementor', $html );
	}
}
