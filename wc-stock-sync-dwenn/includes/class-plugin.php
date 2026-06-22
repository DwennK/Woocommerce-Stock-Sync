<?php

if (!defined('ABSPATH')) exit;

class WCSSD_Plugin {
  const MENU_SLUG = 'wc-stock-sync-dwenn';
  const JOB_TTL   = 1800;
  const PROFILES_OPTION = 'wcssd_supplier_profiles';

  private $job_store;
  private $csv_parser;
  private $sku_resolver;
  private $stock_updater;
  private $admin_page;

  public function __construct() {
    $this->job_store = new WCSSD_JobStore(self::JOB_TTL);
    $this->csv_parser = new WCSSD_CsvParser();
    $this->sku_resolver = new WCSSD_SkuResolver();
    $this->stock_updater = new WCSSD_StockUpdater();
    $this->admin_page = new WCSSD_AdminPage($this->job_store);

    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_post_wcssd_create_job', [$this, 'handle_create_job']);
    add_action('wp_ajax_wcssd_run_chunk', [$this, 'ajax_run_chunk']);
    add_action('wp_ajax_wcssd_cancel_job', [$this, 'ajax_cancel_job']);
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
    if (!current_user_can('manage_woocommerce')) {
      wp_die('Insufficient permissions.');
    }
    check_admin_referer('wcssd_create_job');

    if (empty($_FILES['wcssd_csv']) || empty($_FILES['wcssd_csv']['tmp_name'])) {
      wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
      exit;
    }

    $tmp = isset($_FILES['wcssd_csv']['tmp_name'])
      ? sanitize_text_field(wp_unslash($_FILES['wcssd_csv']['tmp_name']))
      : '';

    if (!is_uploaded_file($tmp)) {
      wp_die('Invalid upload.');
    }

    $max_size = 5 * 1024 * 1024;
    $uploaded_size = isset($_FILES['wcssd_csv']['size']) ? absint($_FILES['wcssd_csv']['size']) : 0;
    if ($uploaded_size > $max_size) {
      wp_die('Uploaded CSV is too large. Max 5 MB.');
    }

    if (!class_exists('WooCommerce')) {
      wp_die('WooCommerce is not active.');
    }

    $chunk_size = isset($_POST['wcssd_chunk']) ? (int)$_POST['wcssd_chunk'] : 25;
    if ($chunk_size < 5) $chunk_size = 5;
    if ($chunk_size > 200) $chunk_size = 200;

    $dry_run = !empty($_POST['wcssd_dry_run']);

    $prezero_enable = !empty($_POST['wcssd_prezero_enable']);
    $prezero_cats = [];
    if ($prezero_enable && !empty($_POST['wcssd_prezero_cats']) && is_array($_POST['wcssd_prezero_cats'])) {
      $prezero_cats = array_values(array_unique(array_filter(array_map('intval', $_POST['wcssd_prezero_cats']))));
    }
    if ($prezero_enable && empty($prezero_cats)) {
      $prezero_enable = false;
    }

    $saved_adjust = get_option('wcssd_price_adjust');
    $saved_amount = 0.0;
    $saved_round  = 'none';
    if (is_array($saved_adjust)) {
      if (isset($saved_adjust['amount'])) $saved_amount = floatval($saved_adjust['amount']);
      if (isset($saved_adjust['round']))  $saved_round  = ($saved_adjust['round'] === 'integer') ? 'integer' : 'none';
    }

    $profile_name = isset($_POST['wcssd_profile_name']) ? sanitize_text_field(wp_unslash($_POST['wcssd_profile_name'])) : '';
    $delimiter = isset($_POST['wcssd_delimiter']) ? sanitize_text_field(wp_unslash($_POST['wcssd_delimiter'])) : 'auto';
    $column_headers = [
      'sku' => isset($_POST['wcssd_col_sku']) ? sanitize_text_field(wp_unslash($_POST['wcssd_col_sku'])) : '',
      'available' => isset($_POST['wcssd_col_available']) ? sanitize_text_field(wp_unslash($_POST['wcssd_col_available'])) : '',
      'price' => isset($_POST['wcssd_col_price']) ? sanitize_text_field(wp_unslash($_POST['wcssd_col_price'])) : '',
    ];

    $posted_amount = isset($_POST['wcssd_price_adjust_amount']) ? floatval(wp_unslash($_POST['wcssd_price_adjust_amount'])) : null;
    $posted_round  = !empty($_POST['wcssd_price_adjust_round']) ? 'integer' : 'none';

    if (!empty($_POST['wcssd_price_adjust_save'])) {
      $to_save = [
        'amount' => ($posted_amount !== null) ? $posted_amount : $saved_amount,
        'round'  => $posted_round,
      ];
      update_option('wcssd_price_adjust', $to_save);
    }

    $adjust_amount = ($posted_amount !== null) ? floatval($posted_amount) : floatval($saved_amount);
    $adjust_round  = $posted_round;

    if (!empty($_POST['wcssd_profile_save']) && $profile_name !== '') {
      self::save_supplier_profile($profile_name, [
        'name' => $profile_name,
        'delimiter' => $delimiter,
        'columns' => $column_headers,
        'price_adjust_amount' => $adjust_amount,
        'price_adjust_round' => $adjust_round,
        'prezero_enable' => $prezero_enable,
        'prezero_cats' => $prezero_cats,
      ]);
    }

    try {
      $parsed = $this->csv_parser->parse_file($tmp, $adjust_amount, $adjust_round, $delimiter, $column_headers);
    } catch (RuntimeException $e) {
      wp_die(esc_html($e->getMessage()));
    }

    $sku_to_post = $this->sku_resolver->resolve_skus_to_posts($parsed['skus']);

    $task_plan = self::build_tasks_from_rows($parsed['rows'], $sku_to_post);
    $tasks = $task_plan['tasks'];
    $missing = $task_plan['missing'];

    $prezero_total = $prezero_enable ? $this->count_prezero_products($prezero_cats) : 0;
    $job_id = $this->job_store->make_job_id();
    $job = [
      'job_id'     => $job_id,
      'created'    => time(),
      'dry_run'    => (bool)$dry_run,
      'chunk'      => $chunk_size,
      'delimiter'  => $parsed['delimiter'],
      'profile_name' => $profile_name,

      'phase'           => ($prezero_enable ? 'prezero' : 'sync'),
      'prezero_enable'  => (bool)$prezero_enable,
      'prezero_cats'    => $prezero_cats,
      'prezero_offset'  => 0,
      'prezero_total'   => $prezero_total,
      'prezero_processed' => 0,
      'prezero_done'    => false,
      'prezero_done_at' => 0,

      'tasks'      => $tasks,
      'total'      => count($tasks),
      'processed'  => 0,
      'updated'    => 0,
      'missing'    => $missing,
      'errors'     => 0,
      'error_msgs' => [],
      'last_sku'   => '',
      'done'       => false,
      'report_lines' => [
        '[INFO] Job created at ' . gmdate('c'),
        '[INFO] Delimiter: ' . self::delimiter_label($parsed['delimiter']),
        '[INFO] Profile: ' . ($profile_name !== '' ? $profile_name : 'none'),
        '[INFO] Matched: ' . count($tasks) . ' / Missing: ' . $missing,
      ],
    ];

    $this->job_store->set($job_id, $job);
    $this->job_store->remember_for_current_user($job_id);

    $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&wcssd_job=' . urlencode($job_id));
    wp_safe_redirect($url);
    exit;
  }

  public static function get_supplier_profiles() {
    $profiles = get_option(self::PROFILES_OPTION);
    return is_array($profiles) ? $profiles : [];
  }

  private static function save_supplier_profile($name, array $profile) {
    $profiles = self::get_supplier_profiles();
    $key = sanitize_key($name);
    if ($key === '') {
      return;
    }

    $profiles[$key] = $profile;
    update_option(self::PROFILES_OPTION, $profiles, false);
  }

  private static function delimiter_label($delimiter) {
    if ($delimiter === "\t") return 'tab';
    if ($delimiter === ';') return 'semicolon';
    if ($delimiter === ',') return 'comma';
    return (string)$delimiter;
  }

  private function count_prezero_products(array $cat_ids) {
    if (!$cat_ids) {
      return 0;
    }

    $q = new WP_Query([
      'post_type'      => 'product',
      'post_status'    => 'any',
      'fields'         => 'ids',
      'posts_per_page' => 1,
      'paged'          => 1,
      'orderby'        => 'ID',
      'order'          => 'ASC',
      'no_found_rows'  => false,
      'tax_query'      => [
        [
          'taxonomy' => 'product_cat',
          'field'    => 'term_id',
          'terms'    => array_map('intval', $cat_ids),
          'operator' => 'IN',
        ],
      ],
    ]);

    return isset($q->found_posts) ? (int)$q->found_posts : 0;
  }

  public function ajax_cancel_job() {
    if (!current_user_can('manage_woocommerce')) {
      wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
    }
    check_ajax_referer('wcssd_ajax');

    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
    if (!$job_id) wp_send_json_error(['message' => 'Missing job_id.'], 400);

    $this->job_store->delete($job_id);
    $this->job_store->clear_for_current_user();

    wp_send_json_success(['ok' => true]);
  }

  public static function build_tasks_from_rows(array $rows, array $sku_to_post) {
    $tasks = [];
    $missing = 0;
    $last_by_sku = [];

    foreach ($rows as $r) {
      $last_by_sku[$r['sku']] = $r;
    }

    foreach ($last_by_sku as $sku => $r) {
      if (!isset($sku_to_post[$sku])) {
        $missing++;
        continue;
      }

      $info = $sku_to_post[$sku];
      $type = ($info['post_type'] === 'product_variation') ? 'variation' : 'product';

      $tasks[] = [
        'sku'       => $sku,
        'type'      => $type,
        'id'        => (int)$info['post_id'],
        'parent_id' => (int)$info['parent_id'],
        'qty'       => (int)$r['qty'],
        'price'     => (string)$r['price'],
        'orig_price'=> (string)(isset($r['orig_price']) ? $r['orig_price'] : $r['price']),
      ];
    }

    return [
      'tasks' => $tasks,
      'missing' => $missing,
    ];
  }

  public function ajax_run_chunk() {
    if (!current_user_can('manage_woocommerce')) {
      wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
    }
    check_ajax_referer('wcssd_ajax');

    if (!class_exists('WooCommerce')) {
      wp_send_json_error(['message' => 'WooCommerce is not active.'], 400);
    }

    $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
    $peek   = !empty($_POST['peek']);

    if (!$job_id) wp_send_json_error(['message' => 'Missing job_id.'], 400);

    $job = $this->job_store->get($job_id);
    if (!is_array($job) || empty($job['job_id'])) {
      wp_send_json_error(['message' => 'Job not found or expired. Upload CSV again.'], 404);
    }

    $lines = [];
    if (empty($job['report_lines']) || !is_array($job['report_lines'])) {
      $job['report_lines'] = [];
    }

    if ($peek) {
      wp_send_json_success([
        'state' => $this->job_state($job),
        'lines' => $lines,
        'report' => !empty($job['report_lines']) ? implode("\n", $job['report_lines']) : '',
        'done'  => !empty($job['done']),
      ]);
    }

    if (!empty($job['done'])) {
      wp_send_json_success([
        'state' => $this->job_state($job),
        'lines' => $lines,
        'report' => !empty($job['report_lines']) ? implode("\n", $job['report_lines']) : '',
        'done'  => true,
      ]);
    }

    if (!empty($job['prezero_enable']) && empty($job['prezero_done'])) {
      $this->run_prezero_chunk($job_id, $job, $lines);
    }

    $this->run_sync_chunk($job_id, $job, $lines);
  }

  private function run_prezero_chunk($job_id, array $job, array $lines) {
    $chunk = isset($job['chunk']) ? (int)$job['chunk'] : 25;
    if ($chunk < 5) $chunk = 5;
    if ($chunk > 200) $chunk = 200;

    $offset  = isset($job['prezero_offset']) ? (int)$job['prezero_offset'] : 0;
    $cat_ids = (!empty($job['prezero_cats']) && is_array($job['prezero_cats'])) ? array_map('intval', $job['prezero_cats']) : [];

    if (!$cat_ids) {
      $job['prezero_done'] = true;
      $job['phase'] = 'sync';
      $job['report_lines'][] = "[WARN][PREZERO] No categories selected. Skipping pre-zero.";
      $this->job_store->set($job_id, $job);

      wp_send_json_success([
        'state' => $this->job_state($job),
        'lines' => ["[WARN][PREZERO] No categories selected. Skipping pre-zero."],
        'report' => !empty($job['report_lines']) ? implode("\n", $job['report_lines']) : '',
        'done'  => false,
      ]);
    }

    $q = new WP_Query([
      'post_type'      => 'product',
      'post_status'    => 'any',
      'fields'         => 'ids',
      'posts_per_page' => $chunk,
      'offset'         => $offset,
      'orderby'        => 'ID',
      'order'          => 'ASC',
      'no_found_rows'  => true,
      'tax_query'      => [
        [
          'taxonomy' => 'product_cat',
          'field'    => 'term_id',
          'terms'    => $cat_ids,
          'operator' => 'IN',
        ],
      ],
    ]);

    $ids = !empty($q->posts) ? array_map('intval', $q->posts) : [];

    if (!$ids) {
      $job['prezero_done'] = true;
      $job['prezero_done_at'] = time();
      $job['phase'] = 'sync';
      $job['last_sku'] = '';
      $lines[] = "[OK][PREZERO] Completed. Switching to sync phase.";
      $job['report_lines'] = array_merge($job['report_lines'], $lines);

      $this->job_store->set($job_id, $job);

      wp_send_json_success([
        'state' => $this->job_state($job),
        'lines' => $lines,
        'report' => !empty($job['report_lines']) ? implode("\n", $job['report_lines']) : '',
        'done'  => false,
      ]);
    }

    foreach ($ids as $pid) {
      try {
        $this->stock_updater->set_stock_zero_for_product_id($pid, !empty($job['dry_run']), $lines);
      } catch (Throwable $e) {
        $job['errors']++;
        $lines[] = "[ERROR][PREZERO] Product ID {$pid} -> " . $e->getMessage();
      }
    }

    $job['prezero_offset'] = $offset + count($ids);
    $job['prezero_processed'] = min((int)$job['prezero_total'], (int)$job['prezero_processed'] + count($ids));
    $job['last_sku'] = 'PREZERO…';
    $job['phase'] = 'prezero';
    $job['report_lines'] = array_merge($job['report_lines'], $lines);

    $this->job_store->set($job_id, $job);

    wp_send_json_success([
      'state' => $this->job_state($job),
      'lines' => $lines,
      'report' => !empty($job['report_lines']) ? implode("\n", $job['report_lines']) : '',
      'done'  => false,
    ]);
  }

  private function run_sync_chunk($job_id, array $job, array $lines) {
    $chunk = isset($job['chunk']) ? (int)$job['chunk'] : 25;
    if ($chunk < 5) $chunk = 5;
    if ($chunk > 200) $chunk = 200;

    $job['phase'] = 'sync';

    $start = (int)$job['processed'];
    $end   = min($job['total'], $start + $chunk);

    if ($start >= $job['total']) {
      if (empty($job['done'])) {
        $job['report_lines'][] = $this->build_report_summary($job);
      }
      $job['done'] = true;
      $job['phase'] = 'done';
      $this->job_store->set($job_id, $job);

      wp_send_json_success([
        'state' => $this->job_state($job),
        'lines' => $lines,
        'report' => !empty($job['report_lines']) ? implode("\n", $job['report_lines']) : '',
        'done'  => true,
      ]);
    }

    for ($i = $start; $i < $end; $i++) {
      $t = $job['tasks'][$i];
      $sku = $t['sku'];
      $job['last_sku'] = $sku;

      try {
        if (!$job['dry_run']) {
          $product = wc_get_product($t['id']);
          if (!$product) {
            $job['errors']++;
            $lines[] = "[ERROR] SKU {$sku} -> Product not found (ID {$t['id']})";
          } else {
            $this->stock_updater->set_stock_and_price($product, (int)$t['qty'], (string)$t['price']);
            $job['updated']++;
            $lines[] = "[OK] {$t['type']} sku={$sku} qty={$t['qty']} orig={$t['orig_price']} -> new={$t['price']}";
          }
        } else {
          $job['updated']++;
          $lines[] = "[DRY] {$t['type']} sku={$sku} qty={$t['qty']} orig={$t['orig_price']} -> new={$t['price']}";
        }
      } catch (Throwable $e) {
        $job['errors']++;
        $msg = $e->getMessage();
        $lines[] = "[ERROR] SKU {$sku} -> " . $msg;
        if (count($job['error_msgs']) < 50) {
          $job['error_msgs'][] = "SKU {$sku}: {$msg}";
        }
      }

      $job['processed']++;
    }

    $job['report_lines'] = array_merge($job['report_lines'], $lines);
    $this->job_store->set($job_id, $job);

    $done = ($job['processed'] >= $job['total']);
    if ($done) {
      if (empty($job['done'])) {
        $job['report_lines'][] = $this->build_report_summary($job);
      }
      $job['done'] = true;
      $job['phase'] = 'done';
      $this->job_store->set($job_id, $job);
    }

    wp_send_json_success([
      'state' => $this->job_state($job),
      'lines' => $lines,
      'report' => !empty($job['report_lines']) ? implode("\n", $job['report_lines']) : '',
      'done'  => $done,
    ]);
  }

  private function build_report_summary(array $job) {
    return "[SUMMARY] processed={$job['processed']} total={$job['total']} updated={$job['updated']} missing={$job['missing']} errors={$job['errors']}";
  }

  private function job_state(array $job) {
    return [
      'processed' => (int)$job['processed'],
      'total'     => (int)$job['total'],
      'updated'   => (int)$job['updated'],
      'missing'   => (int)$job['missing'],
      'errors'    => (int)$job['errors'],
      'dry_run'   => !empty($job['dry_run']),
      'current'   => !empty($job['last_sku']) ? $job['last_sku'] : '',
      'phase'     => !empty($job['phase']) ? (string)$job['phase'] : 'sync',
      'prezero_processed' => !empty($job['prezero_processed']) ? (int)$job['prezero_processed'] : 0,
      'prezero_total' => !empty($job['prezero_total']) ? (int)$job['prezero_total'] : 0,
      'delimiter' => !empty($job['delimiter']) ? self::delimiter_label($job['delimiter']) : '',
      'profile' => !empty($job['profile_name']) ? (string)$job['profile_name'] : '',
      'done' => !empty($job['done']),
    ];
  }
}
