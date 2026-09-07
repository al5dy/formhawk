<?php
/**
 * Plugin Name:       Formhawk
 * Plugin URI:        https://wordpress.org/plugins/formhawk/
 * Description:       Privacy-first form analytics, Field ROI and business-value CRO without cookies or submitted field values.
 * Version:           0.5.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            al5dy
 * Author URI:        https://profiles.wordpress.org/al5dy/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formhawk
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'FORMHAWK_VERSION', '0.5.0' );
define( 'FORMHAWK_DB_VERSION', '6' );
define( 'FORMHAWK_FILE', __FILE__ );
define( 'FORMHAWK_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORMHAWK_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Formhawk\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = FORMHAWK_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'Formhawk\\Infrastructure\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Formhawk\\Infrastructure\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Formhawk\Plugin::instance()->boot();
	},
	PHP_INT_MAX
);

/**
 * Privacy-safe developer API for recording a business outcome.
 *
 * The public submission ID is opaque. No lead field values are accepted.
 *
 * @param string      $submission_id Opaque Formhawk submission ID.
 * @param string      $status        Canonical outcome status.
 * @param int|null    $value_minor   Monetary value in integer minor units.
 * @param string      $currency      ISO 4217 currency code.
 * @param array       $context       Optional idempotency and occurrence context.
 * @return array|WP_Error
 */
function formhawk_record_outcome( $submission_id, $status, $value_minor = null, $currency = '', array $context = array() ) {
	$manager = new Formhawk\Outcomes\OutcomeManager();
	return $manager->record(
		array(
			'submission_id'      => $submission_id,
			'status'             => $status,
			'value_minor'        => $value_minor,
			'currency'           => $currency,
			'occurred_at'        => isset( $context['occurred_at'] ) ? $context['occurred_at'] : '',
			'external_reference' => isset( $context['external_reference'] ) ? $context['external_reference'] : '',
			'idempotency_key'    => isset( $context['idempotency_key'] ) ? $context['idempotency_key'] : '',
		),
		'developer_api'
	);
}
