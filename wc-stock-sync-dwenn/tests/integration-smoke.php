<?php

if (!defined('ABSPATH')) exit;

function wcssd_integration_assert($condition, $message) {
  // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only test failure detail.
  if (!$condition) throw new RuntimeException($message);
}

function wcssd_run_integration_smoke() {
  global $wpdb;

  wcssd_integration_assert(class_exists('WooCommerce'), 'WooCommerce is not active.');
  wcssd_integration_assert(class_exists('WCSSD_Plugin'), 'Stock Sync plugin is not active.');

  foreach (['wcssd_jobs', 'wcssd_job_items', 'wcssd_job_logs'] as $suffix) {
    $table = $wpdb->prefix . $suffix;
    wcssd_integration_assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, "Missing table: {$table}");
  }

  $sku = 'WCSSD-CI-' . wp_generate_password(10, false, false);
  $product = new WC_Product_Simple();
  $product->set_name('Stock Sync integration product');
  $product->set_sku($sku);
  $product->set_manage_stock(false);
  $product->set_stock_status('outofstock');
  $product->set_regular_price('10.00');
  $product_id = $product->save();

  $store = new WCSSD_JobStore();
  $job_id = $store->make_job_id();
  $job_db_id = 0;

  try {
    $resolver = new WCSSD_SkuResolver();
    $resolved = $resolver->resolve_with_diagnostics([$sku, 'WCSSD-MISSING']);
    wcssd_integration_assert(isset($resolved['map'][$sku]), 'Existing product SKU was not resolved.');
    wcssd_integration_assert(!isset($resolved['map']['WCSSD-MISSING']), 'Missing SKU unexpectedly resolved.');

    $snapshot = (new WCSSD_StockUpdater())->snapshot_product(wc_get_product($product_id));
    $job_db_id = $store->create([
    'job_id' => $job_id,
    'owner_user_id' => 1,
    'status' => 'queued',
    'dry_run' => false,
    'chunk' => 25,
    'delimiter' => ',',
    'profile_name' => 'CI',
    'config' => ['skip_invalid' => false],
    ]);
    wcssd_integration_assert($job_db_id > 0, 'Persistent job was not created.');
    wcssd_integration_assert($store->insert_item($job_db_id, [
    'line' => 2,
    'sku' => $sku,
    'product_id' => $product_id,
    'product_type' => 'product',
    'qty' => 7,
    'price' => '12.50',
    'original' => $snapshot,
    'raw' => ['stock' => '7', 'price' => '12.50'],
    'status' => 'ready',
    'message' => 'CI change.',
    ]), 'Job item was not created.');

    $preview = $store->preview($job_db_id, 100, 0, 'ready', $sku);
    wcssd_integration_assert(count($preview) === 1 && $preview[0]['sku'] === $sku, 'Filtered preview did not return the job item.');
    wcssd_integration_assert(count($store->export_rows($job_db_id)) === 1, 'Detailed export did not return the job item.');

    do_action('wcssd_process_job', $job_id);
    $completed = $store->get($job_id);
    $updated_product = wc_get_product($product_id);
    wcssd_integration_assert($completed && $completed['status'] === 'completed', 'Background import did not complete.');
    wcssd_integration_assert($updated_product->get_stock_quantity() === 7, 'Imported stock was not applied.');
    wcssd_integration_assert((float)$updated_product->get_regular_price('edit') === 12.5, 'Imported price was not applied.');

    $store->update_job($job_id, ['status' => 'rolling_back']);
    do_action('wcssd_rollback_job', $job_id);
    $rolled_back = $store->get($job_id);
    $restored_product = wc_get_product($product_id);
    wcssd_integration_assert($rolled_back && $rolled_back['status'] === 'rolled_back', 'Rollback did not complete cleanly.');
    wcssd_integration_assert(!$restored_product->get_manage_stock(), 'Original stock management setting was not restored.');
    wcssd_integration_assert((float)$restored_product->get_regular_price('edit') === 10.0, 'Original price was not restored.');

    WP_CLI::success('WooCommerce Stock Sync integration smoke test passed.');
  } finally {
    if ($job_db_id) $store->delete($job_id);
    if ($product_id) wp_delete_post($product_id, true);
  }
}

wcssd_run_integration_smoke();
