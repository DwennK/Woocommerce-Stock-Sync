<?php
/**
 * Plugin Name: WooCommerce Stock Sync - Dwenn
 * Description: Upload a CSV in wp-admin to update WooCommerce product/variation stock and price in AJAX chunks.
 * Version: 1.4.1
 * Author: Dwenn Kaufmann
 * Author URI: https://dwenn.ch
 * Update URI: false
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/includes/class-job-store.php';
require_once __DIR__ . '/includes/class-csv-parser.php';
require_once __DIR__ . '/includes/class-sku-resolver.php';
require_once __DIR__ . '/includes/class-stock-updater.php';
require_once __DIR__ . '/includes/class-admin-page.php';
require_once __DIR__ . '/includes/class-plugin.php';

class_alias('WCSSD_Plugin', 'WCSSD_WooCommerce_Stock_Sync_Dwenn');

new WCSSD_Plugin();
