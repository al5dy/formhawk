<?php

$formhawk_wp_root = getenv( 'FORMHAWK_WP_ROOT' );
if ( ! $formhawk_wp_root ) {
	$formhawk_wp_root = dirname( __DIR__, 4 );
}

if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}

require_once rtrim( $formhawk_wp_root, '/\\' ) . '/wp-load.php';
