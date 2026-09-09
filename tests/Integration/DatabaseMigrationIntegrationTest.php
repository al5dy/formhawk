<?php

namespace Formhawk\Tests\Integration;

use Formhawk\Infrastructure\Database;
use Formhawk\Infrastructure\Migrations\Version6;
use PHPUnit\Framework\TestCase;

final class DatabaseMigrationIntegrationTest extends TestCase {
	private $original_prefix;
	private $original_version;
	private $test_tables = array();

	protected function setUp(): void {
		global $wpdb;

		parent::setUp();
		$this->original_prefix  = $wpdb->prefix;
		$this->original_version = get_option( 'formhawk_db_version', '' );
		$wpdb->prefix           = $this->original_prefix . 'fh_migration_' . strtolower( wp_generate_password( 6, false, false ) ) . '_';
		$this->test_tables      = Database::all_tables();
	}

	protected function tearDown(): void {
		global $wpdb;

		foreach ( $this->test_tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Removes only randomly prefixed migration-test tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
		$wpdb->prefix = $this->original_prefix;
		update_option( 'formhawk_db_version', $this->original_version, false );

		parent::tearDown();
	}

	public function test_version_one_aggregates_survive_idempotent_upgrade_without_fabricated_placements() {
		global $wpdb;

		$this->create_version_one_schema();
		$forms  = Database::forms_table();
		$daily  = Database::daily_table();
		$fields = Database::fields_table();

		$wpdb->insert(
			$forms,
			array(
				'form_key'             => 'cf7:' . sha1( '71' ),
				'provider'             => 'cf7',
				'provider_form_id'     => '71',
				'title'                => 'Historical form',
				'page_path'            => '/historical',
				'first_seen'           => '2025-01-01 12:00:00',
				'last_seen'            => '2025-01-02 12:00:00',
				'last_success_at'      => '2025-01-02 12:00:00',
				'last_failure_at'      => null,
				'last_mail_failure_at' => null,
				'last_failure_code'    => '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$form_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$daily,
			array(
				'form_id'             => $form_id,
				'stat_date'           => '2025-01-02',
				'views'               => 11,
				'starts'              => 8,
				'submissions'         => 7,
				'confirmed_successes' => 3,
				'abandons'            => 2,
				'failures'            => 1,
				'mail_failures'       => 1,
				'duration_total_ms'   => 9000,
				'duration_samples'    => 3,
			),
			array( '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' )
		);
		$wpdb->insert(
			$fields,
			array(
				'form_id'           => $form_id,
				'stat_date'         => '2025-01-02',
				'field_key'         => 'email',
				'field_label'       => 'Email',
				'interactions'      => 5,
				'abandonments'      => 1,
				'validation_errors' => 2,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d' )
		);

		update_option( 'formhawk_db_version', '1', false );
		Database::maybe_upgrade();

		$this->assertSame( '7', (string) get_option( 'formhawk_db_version', '' ) );
		$this->assertTrue( Database::tables_exist() );
		$this->assertTrue( Database::schema_is_current() );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::submissions_table() ) ), 'Historical aggregate traffic must not be fabricated into outcome linkage.' );
		$this->assertSame( array( 'field_definition_id', 'currency' ), $this->index_columns( Database::field_roi_results_table(), 'PRIMARY' ) );
		$this->assertSame( array( 'form_id', 'stat_date', 'submitted_at_utc' ), $this->index_columns( Database::submissions_table(), 'form_submitted' ) );

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d', $daily, $form_id ),
			ARRAY_A
		);
		$this->assertSame( '7', $row['submissions'] );
		$this->assertSame( '3', $row['confirmed_successes'] );
		$this->assertSame( '0', $row['submit_attempts'] );
		$this->assertSame( '0', $row['validation_failures'] );
		$this->assertSame( '0', $row['mail_successes'] );
		$this->assertSame(
			'0',
			$wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Database::placements_table() ) )
		);

		Database::maybe_upgrade();
		$this->assertTrue( Version6::run() );
		$this->assertTrue( Version6::run() );
		$this->assertSame(
			'1',
			$wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $daily ) )
		);
	}

	private function index_columns( $table, $key_name ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $key_name ) {
					return $row['Key_name'] === $key_name;
				}
			)
		);
		usort(
			$rows,
			static function ( $left, $right ) {
				return absint( $left['Seq_in_index'] ) <=> absint( $right['Seq_in_index'] );
			}
		);
		return wp_list_pluck( $rows, 'Column_name' );
	}

	private function create_version_one_schema() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$forms           = Database::forms_table();
		$daily           = Database::daily_table();
		$fields          = Database::fields_table();

		// These definitions reproduce Formhawk's shipped version-one schema.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed, randomly prefixed test-only table definitions cannot use identifier placeholders with CREATE TABLE/dbDelta.
		$wpdb->query(
			"CREATE TABLE {$forms} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_key varchar(191) NOT NULL,
				provider varchar(32) NOT NULL DEFAULT 'html',
				provider_form_id varchar(191) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL DEFAULT '',
				page_path varchar(500) NOT NULL DEFAULT '',
				first_seen datetime NOT NULL,
				last_seen datetime NOT NULL,
				last_success_at datetime DEFAULT NULL,
				last_failure_at datetime DEFAULT NULL,
				last_mail_failure_at datetime DEFAULT NULL,
				last_failure_code varchar(64) NOT NULL DEFAULT '',
				PRIMARY KEY (id), UNIQUE KEY form_key (form_key)
			) {$charset_collate}"
		);
		$wpdb->query(
			"CREATE TABLE {$daily} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				stat_date date NOT NULL,
				views bigint(20) unsigned NOT NULL DEFAULT 0,
				starts bigint(20) unsigned NOT NULL DEFAULT 0,
				submissions bigint(20) unsigned NOT NULL DEFAULT 0,
				confirmed_successes bigint(20) unsigned NOT NULL DEFAULT 0,
				abandons bigint(20) unsigned NOT NULL DEFAULT 0,
				failures bigint(20) unsigned NOT NULL DEFAULT 0,
				mail_failures bigint(20) unsigned NOT NULL DEFAULT 0,
				duration_total_ms bigint(20) unsigned NOT NULL DEFAULT 0,
				duration_samples bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (id), UNIQUE KEY form_date (form_id, stat_date)
			) {$charset_collate}"
		);
		$wpdb->query(
			"CREATE TABLE {$fields} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				stat_date date NOT NULL,
				field_key varchar(191) NOT NULL,
				field_label varchar(191) NOT NULL DEFAULT '',
				interactions bigint(20) unsigned NOT NULL DEFAULT 0,
				abandonments bigint(20) unsigned NOT NULL DEFAULT 0,
				validation_errors bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY (id), UNIQUE KEY form_date_field (form_id, stat_date, field_key)
			) {$charset_collate}"
		);
		// phpcs:enable
	}
}
