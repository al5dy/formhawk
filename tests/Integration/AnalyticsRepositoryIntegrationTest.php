<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Analytics\AnalyticsRepository;
use Formhawk\Infrastructure\Database;
use PHPUnit\Framework\TestCase;

final class AnalyticsRepositoryIntegrationTest extends TestCase {
	public function test_overview_is_bounded_and_totals_are_independent_from_the_page() {
		global $wpdb;

		$repository = new AnalyticsRepository();
		$rows       = $repository->overview( 30, 2, 7 );
		$query      = $wpdb->last_query;
		$totals     = $repository->overview_totals( 30 );
		$form_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::forms_table() ) );

		$this->assertLessThanOrEqual( 7, count( $rows ) );
		$this->assertStringContainsString( 'LIMIT 7 OFFSET 7', $query );
		$this->assertSame( $form_count, $totals['forms'] );
		$this->assertArrayHasKey( 'confirmed_successes', $totals );
	}
}
