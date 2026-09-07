<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Integrations\IntegrationRegistry;
use Formhawk\Tests\Fixtures\IntegrationDouble;
use PHPUnit\Framework\TestCase;

final class IntegrationRegistryTest extends TestCase {
	public function test_only_available_integrations_register() {
		$available   = new IntegrationDouble( 'available', true, array( 'server_success' ) );
		$unavailable = new IntegrationDouble( 'unavailable', false );
		$registry    = new IntegrationRegistry( array( $available, $unavailable ) );

		$registry->register();

		$this->assertSame( 1, $available->registrations );
		$this->assertSame( 0, $unavailable->registrations );
		$this->assertTrue( $registry->has_capability( 'available', 'server_success' ) );
		$this->assertFalse( $registry->has_capability( 'unavailable', 'server_success' ) );
	}
}
