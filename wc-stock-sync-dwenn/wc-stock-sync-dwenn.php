<?php
/**
 * Plugin Name: WooCommerce Stock Sync - Dwenn
 * Description: Validate and preview supplier CSV changes, then sync WooCommerce stock and prices in durable background jobs.
 * Version: 2.1.0
 * Author: Dwenn Kaufmann
 * Author URI: https://dwenn.ch
 * Update URI: false
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/class-job-store.php';
require_once __DIR__ . '/includes/class-csv-parser.php';
require_once __DIR__ . '/includes/class-sku-resolver.php';
require_once __DIR__ . '/includes/class-stock-updater.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-plugin.php';

class_alias('WCSSD_Plugin', 'WCSSD_WooCommerce_Stock_Sync_Dwenn');

register_activation_hook(__FILE__, ['WCSSD_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WCSSD_Plugin', 'deactivate']);

new WCSSD_Plugin();
