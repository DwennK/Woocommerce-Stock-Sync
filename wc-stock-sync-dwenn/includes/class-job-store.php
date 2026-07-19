<?php

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Internal table names are derived from $wpdb->prefix; all values use prepare(), insert(), update(), or delete().

class WCSSD_JobStore {
  const DB_VERSION = '2.0.0';
  const DB_VERSION_OPTION = 'wcssd_db_version';

  private $jobs_table;
  private $items_table;
  private $logs_table;

  public function __construct($legacy_ttl = 0) {
    global $wpdb;

    $this->jobs_table = $wpdb->prefix . 'wcssd_jobs';
    $this->items_table = $wpdb->prefix . 'wcssd_job_items';
    $this->logs_table = $wpdb->prefix . 'wcssd_job_logs';
  }

  public static function install() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    $jobs = $wpdb->prefix . 'wcssd_jobs';
    $items = $wpdb->prefix . 'wcssd_job_items';
    $logs = $wpdb->prefix . 'wcssd_job_logs';

    dbDelta("CREATE TABLE {$jobs} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      job_key varchar(64) NOT NULL,
      owner_user_id bigint(20) unsigned NOT NULL,
      status varchar(32) NOT NULL,
      mode varchar(16) NOT NULL,
      chunk_size smallint(5) unsigned NOT NULL DEFAULT 25,
      delimiter varchar(16) NOT NULL DEFAULT '',
      profile_name varchar(191) NOT NULL DEFAULT '',
      config longtext NULL,
      lock_token varchar(64) NULL,
      lock_expires datetime NULL,
      created_at datetime NOT NULL,
      updated_at datetime NOT NULL,
      started_at datetime NULL,
      completed_at datetime NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY job_key (job_key),
      KEY owner_status (owner_user_id,status),
      KEY updated_at (updated_at)
    ) {$charset};");

    dbDelta("CREATE TABLE {$items} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      job_id bigint(20) unsigned NOT NULL,
      line_number bigint(20) unsigned NOT NULL DEFAULT 0,
      operation varchar(16) NOT NULL DEFAULT 'sync',
      sku varchar(191) NOT NULL DEFAULT '',
      product_id bigint(20) unsigned NOT NULL DEFAULT 0,
      product_type varchar(32) NOT NULL DEFAULT '',
      target_qty bigint(20) NULL,
      target_price varchar(32) NULL,
      original_data longtext NULL,
      raw_data longtext NULL,
      status varchar(32) NOT NULL,
      message text NULL,
      created_at datetime NOT NULL,
      updated_at datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY job_status (job_id,status),
      KEY job_operation (job_id,operation,status),
      KEY job_product (job_id,product_id),
      KEY sku (sku)
    ) {$charset};");

    dbDelta("CREATE TABLE {$logs} (
      id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
      job_id bigint(20) unsigned NOT NULL,
      level varchar(16) NOT NULL DEFAULT 'info',
      message text NOT NULL,
      created_at datetime NOT NULL,
      PRIMARY KEY  (id),
      KEY job_id (job_id,id)
    ) {$charset};");

    update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
  }

  public static function maybe_upgrade() {
    if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
      self::install();
    }
  }

  public function current_user_id_safe() {
    $uid = get_current_user_id();
    return $uid ? (int)$uid : 0;
  }

  public function make_job_id() {
    return wp_generate_password(32, false, false);
  }

  public function last_job_meta_key() {
    return 'wcssd_last_job_id';
  }

  public function create(array $job) {
    global $wpdb;

    $now = gmdate('Y-m-d H:i:s');
    $inserted = $wpdb->insert(
      $this->jobs_table,
      [
        'job_key' => $job['job_id'],
        'owner_user_id' => (int)$job['owner_user_id'],
        'status' => (string)$job['status'],
        'mode' => !empty($job['dry_run']) ? 'dry_run' : 'live',
        'chunk_size' => (int)$job['chunk'],
        'delimiter' => (string)$job['delimiter'],
        'profile_name' => (string)$job['profile_name'],
        'config' => wp_json_encode($job['config']),
        'created_at' => $now,
        'updated_at' => $now,
      ],
      ['%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
    );

    return $inserted ? (int)$wpdb->insert_id : 0;
  }

  public function get($job_id) {
    global $wpdb;

    $sql = $wpdb->prepare("SELECT * FROM {$this->jobs_table} WHERE job_key = %s", $job_id);
    $row = $wpdb->get_row($sql, ARRAY_A);
    if (!$row) return false;

    $config = !empty($row['config']) ? json_decode($row['config'], true) : [];
    $row['job_id'] = $row['job_key'];
    $row['owner_user_id'] = (int)$row['owner_user_id'];
    $row['chunk'] = (int)$row['chunk_size'];
    $row['dry_run'] = $row['mode'] === 'dry_run';
    $row['config'] = is_array($config) ? $config : [];
    return $row;
  }

  public function insert_item($job_db_id, array $item) {
    global $wpdb;

    $now = gmdate('Y-m-d H:i:s');
    return (bool)$wpdb->insert(
      $this->items_table,
      [
        'job_id' => (int)$job_db_id,
        'line_number' => isset($item['line']) ? (int)$item['line'] : 0,
        'operation' => isset($item['operation']) ? (string)$item['operation'] : 'sync',
        'sku' => isset($item['sku']) ? (string)$item['sku'] : '',
        'product_id' => isset($item['product_id']) ? (int)$item['product_id'] : 0,
        'product_type' => isset($item['product_type']) ? (string)$item['product_type'] : '',
        'target_qty' => array_key_exists('qty', $item) ? $item['qty'] : null,
        'target_price' => array_key_exists('price', $item) ? $item['price'] : null,
        'original_data' => !empty($item['original']) ? wp_json_encode($item['original']) : null,
        'raw_data' => !empty($item['raw']) ? wp_json_encode($item['raw']) : null,
        'status' => (string)$item['status'],
        'message' => isset($item['message']) ? (string)$item['message'] : '',
        'created_at' => $now,
        'updated_at' => $now,
      ],
      ['%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
    );
  }

  public function get_items($job_db_id, array $statuses, $limit, $rollback = false) {
    global $wpdb;

    $statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));
    if (!$statuses) return [];

    $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
    $order = $rollback ? 'id DESC' : "CASE WHEN operation = 'prezero' THEN 0 ELSE 1 END, id ASC";
    $params = array_merge([(int)$job_db_id], $statuses, [max(1, (int)$limit)]);
    $sql = "SELECT * FROM {$this->items_table} WHERE job_id = %d AND status IN ({$placeholders}) ORDER BY {$order} LIMIT %d";
    $prepared = $wpdb->prepare($sql, ...$params);
    $rows = $wpdb->get_results($prepared, ARRAY_A);

    foreach ($rows as &$row) {
      $row['id'] = (int)$row['id'];
      $row['job_id'] = (int)$row['job_id'];
      $row['product_id'] = (int)$row['product_id'];
      $row['target_qty'] = $row['target_qty'] === null ? null : (int)$row['target_qty'];
      $original = !empty($row['original_data']) ? json_decode($row['original_data'], true) : [];
      $raw = !empty($row['raw_data']) ? json_decode($row['raw_data'], true) : [];
      $row['original'] = is_array($original) ? $original : [];
      $row['raw'] = is_array($raw) ? $raw : [];
    }

    return $rows;
  }

  public function update_item($item_id, array $data) {
    global $wpdb;

    $allowed = ['status', 'message'];
    $update = ['updated_at' => gmdate('Y-m-d H:i:s')];
    foreach ($allowed as $field) {
      if (array_key_exists($field, $data)) {
        $update[$field] = $data[$field];
      }
    }
    return (bool)$wpdb->update($this->items_table, $update, ['id' => (int)$item_id]);
  }

  public function update_job($job_id, array $data) {
    global $wpdb;

    $allowed = ['status', 'started_at', 'completed_at', 'lock_token', 'lock_expires'];
    $update = ['updated_at' => gmdate('Y-m-d H:i:s')];
    foreach ($allowed as $field) {
      if (array_key_exists($field, $data)) {
        $update[$field] = $data[$field];
      }
    }
    return (bool)$wpdb->update($this->jobs_table, $update, ['job_key' => $job_id]);
  }

  public function counts($job_db_id) {
    global $wpdb;

    $sql = $wpdb->prepare(
      "SELECT operation, status, COUNT(*) AS amount FROM {$this->items_table} WHERE job_id = %d GROUP BY operation, status",
      (int)$job_db_id
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    $counts = [];
    foreach ($rows as $row) {
      $counts[$row['operation']][$row['status']] = (int)$row['amount'];
    }
    return $counts;
  }

  public function preview($job_db_id, $limit = 100) {
    global $wpdb;

    $sql = $wpdb->prepare(
      "SELECT line_number,operation,sku,product_id,product_type,target_qty,target_price,original_data,status,message
       FROM {$this->items_table} WHERE job_id = %d ORDER BY operation DESC,line_number ASC,id ASC LIMIT %d",
      (int)$job_db_id,
      max(1, min(500, (int)$limit))
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    foreach ($rows as &$row) {
      $original = !empty($row['original_data']) ? json_decode($row['original_data'], true) : [];
      if (!empty($original['nodes']) && is_array($original['nodes'])) {
        $nonzero = 0;
        foreach ($original['nodes'] as $node) {
          if (isset($node['qty']) && (int)$node['qty'] !== 0) $nonzero++;
        }
        $row['old_qty'] = $nonzero . '/' . count($original['nodes']) . ' non-zero';
      } else {
        $row['old_qty'] = isset($original['qty']) ? $original['qty'] : null;
      }
      $row['old_price'] = isset($original['regular_price']) ? $original['regular_price'] : null;
      unset($row['original_data']);
    }
    return $rows;
  }

  public function add_log($job_db_id, $level, $message) {
    global $wpdb;

    return (bool)$wpdb->insert(
      $this->logs_table,
      [
        'job_id' => (int)$job_db_id,
        'level' => sanitize_key($level),
        'message' => (string)$message,
        'created_at' => gmdate('Y-m-d H:i:s'),
      ],
      ['%d', '%s', '%s', '%s']
    );
  }

  public function report($job_db_id) {
    global $wpdb;

    $sql = $wpdb->prepare(
      "SELECT level,message,created_at FROM {$this->logs_table} WHERE job_id = %d ORDER BY id ASC",
      (int)$job_db_id
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    $lines = [];
    foreach ($rows as $row) {
      $lines[] = '[' . strtoupper($row['level']) . '] ' . $row['created_at'] . ' ' . $row['message'];
    }
    return implode("\n", $lines);
  }

  public function acquire_lock($job_id, $token, $seconds = 120) {
    global $wpdb;

    $expires = gmdate('Y-m-d H:i:s', time() + max(30, (int)$seconds));
    $sql = $wpdb->prepare(
      "UPDATE {$this->jobs_table}
       SET lock_token = %s, lock_expires = %s, updated_at = %s
       WHERE job_key = %s AND (lock_token IS NULL OR lock_expires IS NULL OR lock_expires < %s)",
      $token,
      $expires,
      gmdate('Y-m-d H:i:s'),
      $job_id,
      gmdate('Y-m-d H:i:s')
    );
    return $wpdb->query($sql) === 1;
  }

  public function release_lock($job_id, $token) {
    global $wpdb;

    $sql = $wpdb->prepare(
      "UPDATE {$this->jobs_table} SET lock_token = NULL, lock_expires = NULL WHERE job_key = %s AND lock_token = %s",
      $job_id,
      $token
    );
    return $wpdb->query($sql) === 1;
  }

  public function remember_for_current_user($job_id) {
    $uid = $this->current_user_id_safe();
    if ($uid) update_user_meta($uid, $this->last_job_meta_key(), $job_id);
  }

  public function clear_for_current_user() {
    $uid = $this->current_user_id_safe();
    if ($uid) delete_user_meta($uid, $this->last_job_meta_key());
  }

  public function get_resume_job_id() {
    $uid = $this->current_user_id_safe();
    $job_id = $uid ? get_user_meta($uid, $this->last_job_meta_key(), true) : '';
    $job = $job_id ? $this->get($job_id) : false;
    return $job && $this->current_user_can_access_job($job) ? $job_id : '';
  }

  public function recent_for_current_user($limit = 20) {
    global $wpdb;

    $uid = $this->current_user_id_safe();
    if (!$uid) return [];
    $sql = $wpdb->prepare(
      "SELECT job_key,status,mode,profile_name,created_at,completed_at
       FROM {$this->jobs_table} WHERE owner_user_id = %d ORDER BY id DESC LIMIT %d",
      $uid,
      max(1, min(100, (int)$limit))
    );
    return $wpdb->get_results($sql, ARRAY_A);
  }

  public function current_user_can_access_job(array $job) {
    $uid = $this->current_user_id_safe();
    return $uid && !empty($job['owner_user_id']) && (int)$job['owner_user_id'] === $uid;
  }

  public function delete($job_id) {
    global $wpdb;

    $job = $this->get($job_id);
    if (!$job) return false;
    $wpdb->delete($this->logs_table, ['job_id' => (int)$job['id']]);
    $wpdb->delete($this->items_table, ['job_id' => (int)$job['id']]);
    return (bool)$wpdb->delete($this->jobs_table, ['id' => (int)$job['id']]);
  }
}
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
