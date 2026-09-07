<?php
/**
 * Plugin Name:       Formhawk
 * Plugin URI:        https://wordpress.org/plugins/formhawk/
 * Description:       Privacy-first form analytics and self-optimizing CRO for WordPress forms, without cookies or visitor profiles.
 * Version:           0.4.0
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

define( 'FORMHAWK_VERSION', '0.4.0' );
define( 'FORMHAWK_DB_VERSION', '5' );
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
