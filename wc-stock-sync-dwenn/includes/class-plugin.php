<?php

if (!defined('ABSPATH')) exit;

class WCSSD_Plugin {
  const MENU_SLUG = 'wc-stock-sync-dwenn';
  const PROFILES_OPTION = 'wcssd_supplier_profiles';
  const ACTION_GROUP = 'wcssd';

  private $job_store;
  private $csv_parser;
  private $sku_resolver;
  private $stock_updater;
  private $admin_page;

  public function __construct() {
    $this->job_store = new WCSSD_JobStore();
    $this->csv_parser = new WCSSD_CsvParser();
    $this->sku_resolver = new WCSSD_SkuResolver();
    $this->stock_updater = new WCSSD_StockUpdater();
    $this->admin_page = new WCSSD_AdminPage($this->job_store);

    add_action('plugins_loaded', ['WCSSD_JobStore', 'maybe_upgrade'], 20);
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_post_wcssd_create_job', [$this, 'handle_create_job']);
    add_action('admin_post_wcssd_export_job', [$this, 'handle_export_job']);
    add_action('wp_ajax_wcssd_job_status', [$this, 'ajax_job_status']);
    add_action('wp_ajax_wcssd_start_job', [$this, 'ajax_start_job']);
    add_action('wp_ajax_wcssd_run_chunk', [$this, 'ajax_job_status']);
    add_action('wp_ajax_wcssd_cancel_job', [$this, 'ajax_cancel_job']);
    add_action('wp_ajax_wcssd_rollback_job', [$this, 'ajax_rollback_job']);
    add_action('wp_ajax_wcssd_delete_job', [$this, 'ajax_delete_job']);
    add_action('wcssd_analyze_job', [$this, 'analyze_job']);
    add_action('wcssd_process_job', [$this, 'process_job']);
    add_action('wcssd_rollback_job', [$this, 'process_rollback']);
    add_action('wcssd_cleanup_jobs', [$this, 'cleanup_jobs']);
    add_action('init', [__CLASS__, 'ensure_cleanup_schedule']);
  }

  public static function activate() {
    WCSSD_JobStore::install();
    self::ensure_cleanup_schedule();
  }

  public static function deactivate() {
    wp_clear_scheduled_hook('wcssd_cleanup_jobs');
  }

  public static function ensure_cleanup_schedule() {
    if (!wp_next_scheduled('wcssd_cleanup_jobs')) {
      wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wcssd_cleanup_jobs');
    }
  }

  public function admin_menu() {
    add_menu_page(
      'WooCommerce Stock Sync - Dwenn',
      'Stock Sync',
      'manage_woocommerce',
      self::MENU_SLUG,
      [$this->admin_page, 'render'],
      'dashicons-update',
      56
    );
  }

  public function handle_create_job() {
    $this->assert_admin_request(false);

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified centrally by assert_admin_request().

    if (empty($_FILES['wcssd_csv']) || empty($_FILES['wcssd_csv']['tmp_name'])) {
      wp_die('CSV file is required.');
    }

    $tmp = sanitize_text_field(wp_unslash($_FILES['wcssd_csv']['tmp_name']));
    if (!is_uploaded_file($tmp)) wp_die('Invalid upload.');

    $uploaded_size = isset($_FILES['wcssd_csv']['size']) ? absint($_FILES['wcssd_csv']['size']) : 0;
    if ($uploaded_size <= 0 || $uploaded_size > 5 * 1024 * 1024) {
      wp_die('Uploaded CSV must be between 1 byte and 5 MB.');
    }

    $chunk_size = isset($_POST['wcssd_chunk']) ? (int)$_POST['wcssd_chunk'] : 25;
    $chunk_size = max(5, min(200, $chunk_size));
    $dry_run = !empty($_POST['wcssd_dry_run']);
    $skip_invalid = !empty($_POST['wcssd_skip_invalid']);
    $prezero_enable = !empty($_POST['wcssd_prezero_enable']);
    $prezero_cats = [];
    if ($prezero_enable && !empty($_POST['wcssd_prezero_cats']) && is_array($_POST['wcssd_prezero_cats'])) {
      $prezero_cats = array_values(array_unique(array_filter(array_map('intval', $_POST['wcssd_prezero_cats']))));
    }
    $prezero_enable = $prezero_enable && !empty($prezero_cats);

    $profile_name = isset($_POST['wcssd_profile_name']) ? sanitize_text_field(wp_unslash($_POST['wcssd_profile_name'])) : '';
    $delimiter = isset($_POST['wcssd_delimiter']) ? sanitize_text_field(wp_unslash($_POST['wcssd_delimiter'])) : 'auto';
    $column_headers = [
      'sku' => isset($_POST['wcssd_col_sku']) ? sanitize_text_field(wp_unslash($_POST['wcssd_col_sku'])) : '',
      'available' => isset($_POST['wcssd_col_available']) ? sanitize_text_field(wp_unslash($_POST['wcssd_col_available'])) : '',
      'price' => isset($_POST['wcssd_col_price']) ? sanitize_text_field(wp_unslash($_POST['wcssd_col_price'])) : '',
    ];
    $adjust_amount = isset($_POST['wcssd_price_adjust_amount'])
      ? (float)sanitize_text_field(wp_unslash($_POST['wcssd_price_adjust_amount']))
      : 0.0;
    $adjust_round = !empty($_POST['wcssd_price_adjust_round']) ? 'integer' : 'none';

    if (!empty($_POST['wcssd_price_adjust_save'])) {
      update_option('wcssd_price_adjust', ['amount' => $adjust_amount, 'round' => $adjust_round]);
    }
    if (!empty($_POST['wcssd_profile_save']) && $profile_name !== '') {
      self::save_supplier_profile($profile_name, [
        'name' => $profile_name,
        'delimiter' => $delimiter,
        'columns' => $column_headers,
        'price_adjust_amount' => $adjust_amount,
        'price_adjust_round' => $adjust_round,
        'skip_invalid' => $skip_invalid,
        'prezero_enable' => $prezero_enable,
        'prezero_cats' => $prezero_cats,
      ]);
    }

    $csv_path = wp_tempnam(isset($_FILES['wcssd_csv']['name']) ? sanitize_file_name(wp_unslash($_FILES['wcssd_csv']['name'])) : 'stock-sync.csv');
    if (!$csv_path || !move_uploaded_file($tmp, $csv_path)) {
      if ($csv_path && file_exists($csv_path)) unlink($csv_path);
      wp_die('Unable to retain the CSV for background analysis.');
    }

    $job_id = $this->job_store->make_job_id();
    $job_db_id = $this->job_store->create([
      'job_id' => $job_id,
      'owner_user_id' => $this->job_store->current_user_id_safe(),
      'status' => 'analyzing',
      'dry_run' => $dry_run,
      'chunk' => $chunk_size,
      'delimiter' => $delimiter,
      'profile_name' => $profile_name,
      'config' => [
        'csv_path' => $csv_path,
        'adjust_amount' => $adjust_amount,
        'adjust_round' => $adjust_round,
        'requested_delimiter' => $delimiter,
        'column_headers' => $column_headers,
        'skip_invalid' => $skip_invalid,
        'prezero_enable' => $prezero_enable,
        'prezero_cats' => $prezero_cats,
        'zero_ratio' => 0,
        'parser_warnings' => [],
      ],
    ]);
    if (!$job_db_id) {
      unlink($csv_path);
      wp_die('Unable to create persistent sync job.');
    }

    $this->job_store->add_log($job_db_id, 'info', 'Job created; background CSV analysis queued.');
    $this->job_store->remember_for_current_user($job_id);
    $this->schedule('wcssd_analyze_job', $job_id);
    $this->schedule_delayed('wcssd_analyze_job', $job_id, 620);

    wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG . '&wcssd_job=' . rawurlencode($job_id)));
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    exit;
  }

  public function analyze_job($job_id) {
    $job = $this->job_store->get($job_id);
    if (!$job || $job['status'] !== 'analyzing') return;

    $token = wp_generate_password(32, false, false);
    if (!$this->job_store->acquire_lock($job_id, $token, 600)) {
      $this->schedule_delayed('wcssd_analyze_job', $job_id, 120);
      return;
    }

    $config = !empty($job['config']) ? $job['config'] : [];
    $csv_path = !empty($config['csv_path']) ? (string)$config['csv_path'] : '';
    try {
      if ($csv_path === '' || !is_readable($csv_path)) {
        throw new RuntimeException('Temporary CSV is no longer available for analysis.');
      }

      $this->job_store->clear_items($job['id']);
      $parsed = $this->csv_parser->parse_file(
        $csv_path,
        isset($config['adjust_amount']) ? (float)$config['adjust_amount'] : 0.0,
        !empty($config['adjust_round']) ? (string)$config['adjust_round'] : 'none',
        !empty($config['requested_delimiter']) ? (string)$config['requested_delimiter'] : 'auto',
        !empty($config['column_headers']) && is_array($config['column_headers']) ? $config['column_headers'] : []
      );

      foreach ($parsed['errors'] as $error) {
        $this->insert_job_item_or_fail($job['id'], [
          'line' => $error['line'],
          'operation' => 'sync',
          'sku' => !empty($error['sku']) ? $error['sku'] : ($error['field'] === 'sku' ? $error['value'] : ''),
          'status' => self::validation_error_status($error),
          'message' => $error['field'] . ': ' . $error['message'],
          'raw' => $error,
        ]);
      }

      $prezero_enable = !empty($config['prezero_enable']);
      $prezero_cats = !empty($config['prezero_cats']) && is_array($config['prezero_cats']) ? $config['prezero_cats'] : [];
      $prezero_product_ids = $prezero_enable ? $this->get_prezero_product_ids($prezero_cats) : [];
      $prezero_affected_ids = [];
      foreach ($prezero_product_ids as $product_id) {
        $snapshot = $this->stock_updater->snapshot_product_tree($product_id);
        if (!$snapshot) continue;
        $needs_zero = $this->stock_updater->product_tree_needs_zero($snapshot);
        if ($needs_zero) {
          foreach ($snapshot['nodes'] as $node) {
            $prezero_affected_ids[(int)$node['product_id']] = true;
          }
        }
        $product = wc_get_product($product_id);
        $this->insert_job_item_or_fail($job['id'], [
          'operation' => 'prezero',
          'sku' => 'PREZERO-' . $product_id,
          'product_id' => $product_id,
          'product_type' => $product ? $product->get_type() : 'product',
          'qty' => 0,
          'price' => null,
          'original' => $snapshot,
          'status' => $needs_zero ? 'ready' : 'unchanged',
          'message' => 'Pre-zero category preview.',
        ]);
      }

      $matched_rows = 0;
      $matched_zero_targets = 0;
      $resolved = $this->sku_resolver->resolve_with_diagnostics($parsed['skus']);
      foreach ($parsed['rows'] as $row) {
        $sku = $row['sku'];
        $raw = [
          'stock' => $row['raw_stock'],
          'price' => $row['raw_price'],
          'original_supplier_price' => $row['orig_price'],
        ];
        if (isset($resolved['ambiguous'][$sku])) {
          $this->insert_job_item_or_fail($job['id'], [
            'line' => $row['line'],
            'sku' => $sku,
            'qty' => $row['qty'],
            'price' => $row['price'],
            'status' => 'conflict',
            'message' => 'SKU matches multiple WooCommerce products: ' . implode(', ', $resolved['ambiguous'][$sku]),
            'raw' => $raw,
          ]);
          continue;
        }
        if (!isset($resolved['map'][$sku])) {
          $this->insert_job_item_or_fail($job['id'], [
            'line' => $row['line'],
            'sku' => $sku,
            'qty' => $row['qty'],
            'price' => $row['price'],
            'status' => 'missing',
            'message' => 'SKU not found in WooCommerce.',
            'raw' => $raw,
          ]);
          continue;
        }

        $info = $resolved['map'][$sku];
        $product = wc_get_product((int)$info['post_id']);
        if (!$product) {
          $this->insert_job_item_or_fail($job['id'], [
            'line' => $row['line'],
            'sku' => $sku,
            'status' => 'missing',
            'message' => 'Resolved product can no longer be loaded.',
            'raw' => $raw,
          ]);
          continue;
        }

        $matched_rows++;
        if ((int)$row['qty'] === 0) $matched_zero_targets++;
        $snapshot = $this->stock_updater->snapshot_product($product);
        $needs_update = isset($prezero_affected_ids[(int)$product->get_id()])
          || $this->stock_updater->product_needs_update($product, (int)$row['qty'], (string)$row['price']);
        $this->insert_job_item_or_fail($job['id'], [
          'line' => $row['line'],
          'sku' => $sku,
          'product_id' => (int)$product->get_id(),
          'product_type' => $info['post_type'] === 'product_variation' ? 'variation' : 'product',
          'qty' => $row['qty'],
          'price' => $row['price'],
          'original' => $snapshot,
          'raw' => $raw,
          'status' => $needs_update ? 'ready' : 'unchanged',
          'message' => $needs_update ? 'Change ready for confirmation.' : 'No change required.',
        ]);
      }

      $fresh_job = $this->job_store->get($job_id);
      if (!$fresh_job || $fresh_job['status'] !== 'analyzing') return;

      $config['zero_ratio'] = $matched_rows ? $matched_zero_targets / $matched_rows : 0;
      $config['parser_warnings'] = $parsed['warnings'];
      $config['csv_path'] = '';
      $counts = $this->job_store->counts($job['id']);
      $sync = isset($counts['sync']) ? $counts['sync'] : [];
      $invalid = isset($sync['invalid']) ? (int)$sync['invalid'] : 0;
      $conflicts = isset($sync['conflict']) ? (int)$sync['conflict'] : 0;
      $skip_invalid = !empty($config['skip_invalid']);
      $blocked = $conflicts > 0 || ($invalid > 0 && !$skip_invalid);
      $status = $blocked ? 'invalid' : 'preview';
      $this->job_store->update_job($job_id, [
        'status' => $status,
        'delimiter' => $parsed['delimiter'],
        'config' => $config,
      ]);
      $this->job_store->add_log(
        $job['id'],
        $blocked ? 'error' : ($invalid > 0 ? 'warning' : 'info'),
        $blocked
          ? "Preview blocked by {$invalid} invalid row(s) and {$conflicts} SKU conflict(s)."
          : ($invalid > 0
            ? "Preview ready; {$invalid} invalid row(s) will be skipped after confirmation."
            : 'Preview ready; no WooCommerce data has been changed.')
      );
    } catch (Throwable $e) {
      $fresh_job = $this->job_store->get($job_id);
      if ($fresh_job && $fresh_job['status'] === 'analyzing') {
        $config['csv_path'] = '';
        $this->job_store->update_job($job_id, [
          'status' => 'analysis_failed',
          'completed_at' => gmdate('Y-m-d H:i:s'),
          'config' => $config,
        ]);
        $this->job_store->add_log($job['id'], 'error', 'CSV analysis failed: ' . $e->getMessage());
      }
    } finally {
      if ($csv_path !== '' && file_exists($csv_path)) unlink($csv_path);
      $this->job_store->release_lock($job_id, $token);
    }
  }

  public static function validation_error_status(array $error) {
    return !empty($error['message']) && strpos((string)$error['message'], 'Duplicate SKU') === 0
      ? 'conflict'
      : 'invalid';
  }

  private function insert_job_item_or_fail($job_db_id, array $item) {
    if (!$this->job_store->insert_item($job_db_id, $item)) {
      throw new RuntimeException('Unable to persist an analyzed CSV row.');
    }
  }

  public function ajax_job_status() {
    $job = $this->get_ajax_job();
    wp_send_json_success($this->response_payload($job));
  }

  public function ajax_start_job() {
    $job = $this->get_ajax_job();
    $state = $this->job_state($job);
    if ($job['status'] !== 'preview' || $job['dry_run'] || !$state['can_apply']) {
      wp_send_json_error(['message' => 'This preview cannot be applied. Resolve SKU conflicts, review validation errors, and use live mode.'], 409);
    }

    $now = gmdate('Y-m-d H:i:s');
    $this->job_store->update_job($job['job_id'], ['status' => 'queued', 'started_at' => $now]);
    $this->job_store->add_log($job['id'], 'info', 'Import confirmed by user and queued.');
    $this->schedule('wcssd_process_job', $job['job_id']);
    $this->schedule_delayed('wcssd_process_job', $job['job_id'], 620);
    $job = $this->job_store->get($job['job_id']);
    wp_send_json_success($this->response_payload($job));
  }

  public function ajax_cancel_job() {
    $job = $this->get_ajax_job();
    if (in_array($job['status'], ['completed', 'rolled_back', 'rollback_partial', 'cancelled', 'analysis_failed'], true)) {
      wp_send_json_error(['message' => 'This job is already finished.'], 409);
    }

    $status = in_array($job['status'], ['queued', 'running'], true) ? 'cancelling' : 'cancelled';
    $this->job_store->update_job($job['job_id'], [
      'status' => $status,
      'completed_at' => $status === 'cancelled' ? gmdate('Y-m-d H:i:s') : null,
    ]);
    $this->job_store->add_log($job['id'], 'warning', 'Cancellation requested.');
    $this->cleanup_job_csv($job);
    $this->job_store->clear_for_current_user();
    $job = $this->job_store->get($job['job_id']);
    wp_send_json_success($this->response_payload($job));
  }

  public function ajax_rollback_job() {
    $job = $this->get_ajax_job();
    if (!in_array($job['status'], ['completed', 'cancelled'], true)) {
      wp_send_json_error(['message' => 'Only a completed or partially cancelled import can be rolled back.'], 409);
    }

    $this->job_store->update_job($job['job_id'], ['status' => 'rolling_back']);
    $this->job_store->add_log($job['id'], 'warning', 'Rollback requested by user.');
    $this->schedule('wcssd_rollback_job', $job['job_id']);
    $this->schedule_delayed('wcssd_rollback_job', $job['job_id'], 620);
    $job = $this->job_store->get($job['job_id']);
    wp_send_json_success($this->response_payload($job));
  }

  public function ajax_delete_job() {
    $job = $this->get_ajax_job();
    $active = ['analyzing', 'queued', 'running', 'cancelling', 'rolling_back'];
    if (in_array($job['status'], $active, true)) {
      wp_send_json_error(['message' => 'Cancel the active job before deleting it.'], 409);
    }

    $this->cleanup_job_csv($job);
    if (!$this->job_store->delete($job['job_id'])) {
      wp_send_json_error(['message' => 'Unable to delete this job.'], 500);
    }
    $this->job_store->clear_for_current_user();
    wp_send_json_success(['deleted' => true]);
  }

  public function handle_export_job() {
    if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');
    check_admin_referer('wcssd_export_job');

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified directly above.
    $job_id = isset($_GET['job_id']) ? sanitize_text_field(wp_unslash($_GET['job_id'])) : '';
    $job = $job_id ? $this->job_store->get($job_id) : false;
    if (!$job || !$this->job_store->current_user_can_access_job($job)) wp_die('Job not found or access denied.');

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="wc-stock-sync-' . sanitize_file_name($job_id) . '.csv"');
    $output = fopen('php://output', 'w');
    if (!$output) wp_die('Unable to create CSV export.');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['line', 'operation', 'sku', 'product_id', 'type', 'old_stock', 'new_stock', 'raw_stock', 'old_price', 'new_price', 'raw_price', 'status', 'detail'], ';', '"', '\\');
    foreach ($this->job_store->export_rows($job['id']) as $row) {
      $cells = [
        $row['line_number'],
        $row['operation'],
        $row['sku'],
        $row['product_id'],
        $row['product_type'],
        $row['old_qty'],
        $row['target_qty'],
        $row['raw_stock'],
        $row['old_price'],
        $row['target_price'],
        $row['raw_price'],
        $row['status'],
        $row['message'],
      ];
      fputcsv($output, array_map([__CLASS__, 'csv_export_cell'], $cells), ';', '"', '\\');
    }
    fclose($output);
    exit;
  }

  public function process_job($job_id) {
    $job = $this->job_store->get($job_id);
    if (!$job || !in_array($job['status'], ['queued', 'running', 'cancelling'], true)) return;

    $token = wp_generate_password(32, false, false);
    if (!$this->job_store->acquire_lock($job_id, $token, 600)) {
      $this->schedule_delayed('wcssd_process_job', $job_id, 620);
      return;
    }

    $should_continue = false;
    try {
      $job = $this->job_store->get($job_id);
      if ($job['status'] === 'cancelling') {
        $this->job_store->update_job($job_id, ['status' => 'cancelled', 'completed_at' => gmdate('Y-m-d H:i:s')]);
        $this->job_store->add_log($job['id'], 'warning', 'Job cancelled before the next batch.');
        return;
      }
      if ($job['status'] === 'queued') $this->job_store->update_job($job_id, ['status' => 'running']);

      $items = $this->job_store->get_items($job['id'], ['ready', 'processing'], $job['chunk']);
      foreach ($items as $item) {
        $fresh_job = $this->job_store->get($job_id);
        if (!$fresh_job || $fresh_job['status'] === 'cancelling') break;

        $this->job_store->update_item($item['id'], ['status' => 'processing']);
        try {
          if ($item['operation'] === 'prezero') {
            $lines = [];
            $this->stock_updater->set_stock_zero_for_product_id($item['product_id'], false, $lines);
            $changed = true;
          } else {
            $product = wc_get_product($item['product_id']);
            if (!$product) throw new RuntimeException('Product not found during execution.');
            $changed = $this->stock_updater->apply_if_changed($product, (int)$item['target_qty'], (string)$item['target_price']);
          }
          $this->job_store->update_item($item['id'], [
            'status' => $changed ? 'applied' : 'skipped',
            'message' => $changed ? 'Applied successfully.' : 'Already up to date at execution time.',
          ]);
          $this->job_store->add_log($job['id'], 'info', $item['operation'] . ' ' . $item['sku'] . ($changed ? ' applied.' : ' unchanged.'));
        } catch (Throwable $e) {
          $message = $e->getMessage();
          try {
            if ($item['operation'] === 'prezero') {
              $this->stock_updater->restore_product_tree($item['original']);
            } else {
              $product = wc_get_product($item['product_id']);
              if ($product) $this->stock_updater->restore_product($product, $item['original']);
            }
            $message .= ' Original values restored after failure.';
          } catch (Throwable $restore_error) {
            $message .= ' Compensating restore failed: ' . $restore_error->getMessage();
          }
          $this->job_store->update_item($item['id'], ['status' => 'failed', 'message' => $message]);
          $this->job_store->add_log($job['id'], 'error', $item['sku'] . ': ' . $message);
        }
      }

      $fresh_job = $this->job_store->get($job_id);
      if ($fresh_job && $fresh_job['status'] === 'cancelling') {
        $this->job_store->update_job($job_id, ['status' => 'cancelled', 'completed_at' => gmdate('Y-m-d H:i:s')]);
        $this->job_store->add_log($job['id'], 'warning', 'Job cancelled after the active item finished.');
      } else {
        $remaining = $this->job_store->get_items($job['id'], ['ready', 'processing'], 1);
        if ($remaining) {
          $should_continue = true;
        } else {
          $this->job_store->update_job($job_id, ['status' => 'completed', 'completed_at' => gmdate('Y-m-d H:i:s')]);
          $this->job_store->add_log($job['id'], 'info', 'Import completed. Rollback snapshot retained.');
        }
      }
    } finally {
      $this->job_store->release_lock($job_id, $token);
    }

    if ($should_continue) $this->schedule('wcssd_process_job', $job_id);
  }

  public function process_rollback($job_id) {
    $job = $this->job_store->get($job_id);
    if (!$job || $job['status'] !== 'rolling_back') return;

    $token = wp_generate_password(32, false, false);
    if (!$this->job_store->acquire_lock($job_id, $token, 600)) {
      $this->schedule_delayed('wcssd_rollback_job', $job_id, 620);
      return;
    }
    $should_continue = false;
    try {
      $items = $this->job_store->get_items($job['id'], ['applied', 'rollback_processing'], $job['chunk'], true);
      foreach ($items as $item) {
        $this->job_store->update_item($item['id'], ['status' => 'rollback_processing']);
        try {
          if ($item['operation'] === 'prezero') {
            $conflicts = $this->stock_updater->restore_product_tree_safely($item['original']);
            if ($conflicts) {
              throw new RuntimeException('Rollback conflict for product IDs: ' . implode(', ', $conflicts));
            }
          } else {
            $product = wc_get_product($item['product_id']);
            if (!$product) throw new RuntimeException('Product not found during rollback.');
            if (!$this->stock_updater->product_matches_snapshot($product, $item['original'])) {
              if (!$this->stock_updater->product_matches_target($product, (int)$item['target_qty'], (string)$item['target_price'])) {
                throw new RuntimeException('Product changed after this import; current values were preserved.');
              }
              $this->stock_updater->restore_product($product, $item['original']);
            }
          }
          $this->job_store->update_item($item['id'], ['status' => 'rolled_back', 'message' => 'Original values restored.']);
          $this->job_store->add_log($job['id'], 'info', $item['sku'] . ' rolled back.');
        } catch (Throwable $e) {
          $this->job_store->update_item($item['id'], ['status' => 'rollback_failed', 'message' => $e->getMessage()]);
          $this->job_store->add_log($job['id'], 'error', 'Rollback ' . $item['sku'] . ': ' . $e->getMessage());
        }
      }

      $remaining = $this->job_store->get_items($job['id'], ['applied', 'rollback_processing'], 1, true);
      if ($remaining) {
        $should_continue = true;
      } else {
        $counts = $this->job_store->counts($job['id']);
        $sync_failed = isset($counts['sync']['rollback_failed']) ? (int)$counts['sync']['rollback_failed'] : 0;
        $prezero_failed = isset($counts['prezero']['rollback_failed']) ? (int)$counts['prezero']['rollback_failed'] : 0;
        $rollback_failed = $sync_failed + $prezero_failed;
        $status = self::rollback_completion_status($rollback_failed);
        $this->job_store->update_job($job_id, ['status' => $status, 'completed_at' => gmdate('Y-m-d H:i:s')]);
        $this->job_store->add_log(
          $job['id'],
          $rollback_failed > 0 ? 'error' : 'warning',
          $rollback_failed > 0
            ? "Rollback completed with {$rollback_failed} conflict(s) or failure(s)."
            : 'Rollback completed.'
        );
      }
    } finally {
      $this->job_store->release_lock($job_id, $token);
    }

    if ($should_continue) $this->schedule('wcssd_rollback_job', $job_id);
  }

  public static function get_supplier_profiles() {
    $profiles = get_option(self::PROFILES_OPTION);
    return is_array($profiles) ? $profiles : [];
  }

  public static function build_tasks_from_rows(array $rows, array $sku_to_post) {
    $tasks = [];
    $missing = 0;
    $last_by_sku = [];
    foreach ($rows as $row) $last_by_sku[$row['sku']] = $row;
    foreach ($last_by_sku as $sku => $row) {
      if (!isset($sku_to_post[$sku])) {
        $missing++;
        continue;
      }
      $info = $sku_to_post[$sku];
      $tasks[] = [
        'sku' => $sku,
        'type' => $info['post_type'] === 'product_variation' ? 'variation' : 'product',
        'id' => (int)$info['post_id'],
        'parent_id' => (int)$info['parent_id'],
        'qty' => (int)$row['qty'],
        'price' => (string)$row['price'],
        'orig_price' => (string)(isset($row['orig_price']) ? $row['orig_price'] : $row['price']),
      ];
    }
    return ['tasks' => $tasks, 'missing' => $missing];
  }

  private static function save_supplier_profile($name, array $profile) {
    $profiles = self::get_supplier_profiles();
    $key = sanitize_key($name);
    if ($key === '') return;
    $profiles[$key] = $profile;
    update_option(self::PROFILES_OPTION, $profiles, false);
  }

  private function get_prezero_product_ids(array $cat_ids) {
    if (!$cat_ids) return [];
    $query = new WP_Query([
      'post_type' => 'product',
      'post_status' => 'any',
      'fields' => 'ids',
      'posts_per_page' => -1,
      'orderby' => 'ID',
      'order' => 'ASC',
      'no_found_rows' => true,
      'tax_query' => [[
        'taxonomy' => 'product_cat',
        'field' => 'term_id',
        'terms' => array_map('intval', $cat_ids),
        'operator' => 'IN',
      ]],
    ]);
    return !empty($query->posts) ? array_map('intval', $query->posts) : [];
  }

  private function get_ajax_job() {
    $this->assert_admin_request(true);
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified centrally by assert_admin_request().
    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
    if (!$job_id) wp_send_json_error(['message' => 'Missing job_id.'], 400);
    $job = $this->job_store->get($job_id);
    if (!$job) wp_send_json_error(['message' => 'Job not found.'], 404);
    if (!$this->job_store->current_user_can_access_job($job)) {
      wp_send_json_error(['message' => 'Job access denied.'], 403);
    }
    return $job;
  }

  private function assert_admin_request($ajax) {
    if (!current_user_can('manage_woocommerce')) {
      if ($ajax) wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
      wp_die('Insufficient permissions.');
    }
    if (!class_exists('WooCommerce')) {
      if ($ajax) wp_send_json_error(['message' => 'WooCommerce is not active.'], 400);
      wp_die('WooCommerce is not active.');
    }
    if ($ajax) check_ajax_referer('wcssd_ajax');
    else check_admin_referer('wcssd_create_job');
  }

  private function response_payload(array $job) {
    // phpcs:disable WordPress.Security.NonceVerification.Missing -- AJAX nonce is verified before this method is called.
    $preview_status = isset($_POST['preview_status']) ? sanitize_key(wp_unslash($_POST['preview_status'])) : 'all';
    $preview_search = isset($_POST['preview_search']) ? sanitize_text_field(wp_unslash($_POST['preview_search'])) : '';
    $preview_page = isset($_POST['preview_page']) ? max(1, absint($_POST['preview_page'])) : 1;
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    $per_page = 100;
    $preview_total = $this->job_store->preview_count($job['id'], $preview_status, $preview_search);
    $preview_pages = max(1, (int)ceil($preview_total / $per_page));
    $preview_page = min($preview_page, $preview_pages);
    return [
      'state' => $this->job_state($job),
      'preview' => $this->job_store->preview(
        $job['id'],
        $per_page,
        ($preview_page - 1) * $per_page,
        $preview_status,
        $preview_search
      ),
      'preview_meta' => [
        'page' => $preview_page,
        'pages' => $preview_pages,
        'total' => $preview_total,
        'status' => $preview_status,
        'search' => $preview_search,
      ],
      'report' => $this->job_store->report($job['id']),
      'done' => in_array($job['status'], ['completed', 'rolled_back', 'rollback_partial', 'cancelled', 'analysis_failed'], true),
    ];
  }

  private function job_state(array $job) {
    $counts = $this->job_store->counts($job['id']);
    $sync = isset($counts['sync']) ? $counts['sync'] : [];
    $prezero = isset($counts['prezero']) ? $counts['prezero'] : [];
    $count = static function(array $source, $key) {
      return isset($source[$key]) ? (int)$source[$key] : 0;
    };
    $sync_total = array_sum($sync);
    $ready = $count($sync, 'ready') + $count($prezero, 'ready');
    $processing = $count($sync, 'processing') + $count($prezero, 'processing');
    $applied = $count($sync, 'applied') + $count($prezero, 'applied');
    $failed = $count($sync, 'failed') + $count($prezero, 'failed');
    $skipped = $count($sync, 'skipped') + $count($prezero, 'skipped');
    $rolled_back = $count($sync, 'rolled_back') + $count($prezero, 'rolled_back');
    $change_total = $ready + $processing + $applied + $failed + $skipped + $rolled_back;
    $processed = $applied + $failed + $skipped + $rolled_back;
    $invalid = $count($sync, 'invalid');
    $conflicts = $count($sync, 'conflict');
    $config = !empty($job['config']) ? $job['config'] : [];
    $skip_invalid = !empty($config['skip_invalid']);

    return [
      'status' => $job['status'],
      'phase' => $job['status'],
      'processed' => $processed,
      'total' => $change_total,
      'rows' => $sync_total,
      'changed' => $change_total,
      'updated' => $applied,
      'unchanged' => $count($sync, 'unchanged') + $count($sync, 'skipped'),
      'missing' => $count($sync, 'missing'),
      'invalid' => $invalid,
      'conflicts' => $conflicts,
      'errors' => $invalid + $conflicts + $failed + $count($sync, 'rollback_failed') + $count($prezero, 'rollback_failed'),
      'failed' => $failed,
      'rolled_back' => $rolled_back,
      'prezero_processed' => $count($prezero, 'applied') + $count($prezero, 'rolled_back'),
      'prezero_total' => array_sum($prezero),
      'dry_run' => !empty($job['dry_run']),
      'delimiter' => self::delimiter_label($job['delimiter']),
      'profile' => $job['profile_name'],
      'zero_warning' => !empty($config['zero_ratio']) && (float)$config['zero_ratio'] >= 0.5,
      'skip_invalid' => $skip_invalid,
      'can_apply' => $job['status'] === 'preview' && empty($job['dry_run']) && $conflicts === 0 && ($invalid === 0 || $skip_invalid) && $ready > 0,
      'can_rollback' => in_array($job['status'], ['completed', 'cancelled'], true) && $applied > 0,
      'can_delete' => !in_array($job['status'], ['analyzing', 'queued', 'running', 'cancelling', 'rolling_back'], true),
      'done' => in_array($job['status'], ['completed', 'rolled_back', 'rollback_partial', 'cancelled', 'analysis_failed'], true),
    ];
  }

  public function cleanup_jobs() {
    $days = max(1, (int)apply_filters('wcssd_job_retention_days', 30));
    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
    $this->job_store->cleanup_terminal_before($cutoff);
  }

  public static function rollback_completion_status($failed_count) {
    return (int)$failed_count > 0 ? 'rollback_partial' : 'rolled_back';
  }

  public static function csv_export_cell($value) {
    $value = (string)$value;
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
  }

  private function cleanup_job_csv(array $job) {
    $config = !empty($job['config']) && is_array($job['config']) ? $job['config'] : [];
    $csv_path = !empty($config['csv_path']) ? (string)$config['csv_path'] : '';
    if ($csv_path !== '' && file_exists($csv_path)) unlink($csv_path);
    if ($csv_path !== '') {
      $config['csv_path'] = '';
      $this->job_store->update_job($job['job_id'], ['config' => $config]);
    }
  }

  private static function delimiter_label($delimiter) {
    if ($delimiter === "\t") return 'tab';
    if ($delimiter === ';') return 'semicolon';
    if ($delimiter === ',') return 'comma';
    return (string)$delimiter;
  }

  private function schedule($hook, $job_id) {
    if (function_exists('as_enqueue_async_action')) {
      as_enqueue_async_action($hook, [$job_id], self::ACTION_GROUP, false);
      return;
    }
    wp_schedule_single_event(time() + 1, $hook, [$job_id]);
  }

  private function schedule_delayed($hook, $job_id, $delay) {
    $timestamp = time() + max(30, (int)$delay);
    if (function_exists('as_schedule_single_action')) {
      as_schedule_single_action($timestamp, $hook, [$job_id], self::ACTION_GROUP, false);
      return;
    }
    wp_schedule_single_event($timestamp, $hook, [$job_id]);
  }
}
