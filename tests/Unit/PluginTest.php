<?php

namespace Formhawk\Tests\Unit;

use Formhawk\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {
	private $had_request_uri;
	private $request_uri;

	protected function setUp(): void {
		parent::setUp();
		$this->had_request_uri = isset( $_SERVER['REQUEST_URI'] );
		$this->request_uri     = $this->had_request_uri ? $_SERVER['REQUEST_URI'] : null;
	}

	protected function tearDown(): void {
		if ( $this->had_request_uri ) {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}
		parent::tearDown();
	}

	public function test_current_path_ignores_non_scalar_server_input() {
		$_SERVER['REQUEST_URI'] = array( '/private?email=visitor@example.test' );

		$this->assertSame( '/', Plugin::current_path() );
	}
}
