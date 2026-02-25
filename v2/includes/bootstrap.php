<?php
/**
 * Sync v2 bootstrap: load v2 includes in dependency order. Do not modify includes/.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$v2_dir = dirname( __FILE__ );

require_once $v2_dir . '/helpers.php';
require_once $v2_dir . '/api.php';
require_once $v2_dir . '/sync-motors.php';
require_once $v2_dir . '/sync-woocommerce.php';
require_once $v2_dir . '/cron.php';
require_once $v2_dir . '/cli.php';
