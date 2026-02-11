<?php
/**
 * Plugin Name: WooCommerce Stock Sync - Dwenn
 * Description: Upload a CSV in wp-admin to update WooCommerce product/variation stock + price. Uses one DB lookup for SKUs, then processes in AJAX chunks with progress bar. Stores job in transient (auto-expiring) and deletes on finish/cancel. No CSV left on disk. Includes optional "Pre-zero stock by category" before sync.
 * Version: 1.3.1
 * Author: Dwenn Kaufmann
 * Author URI: https://dwenn.ch
 * Update URI: false
 */

if (!defined('ABSPATH')) exit;

/**
 * Naming notes:
 * - Human-facing name is handled by the plugin header + admin menu titles.
 * - Class/function names are purely technical; we use a unique prefix (WCSSD_) to avoid collisions.
 * - Slugs should be stable, lowercase, and URL-safe (no spaces).
 */
class WCSSD_WooCommerce_Stock_Sync_Dwenn {
  const MENU_SLUG = 'wc-stock-sync-dwenn';
  const JOB_TTL   = 1800; // 30 minutes

  public function __construct() {
    add_action('admin_menu', [$this, 'admin_menu']);

    // admin-post: handles the CSV upload and creates the transient job
    add_action('admin_post_wcssd_create_job', [$this, 'handle_create_job']);

    // AJAX endpoints: chunk runner + cancel
    add_action('wp_ajax_wcssd_run_chunk', [$this, 'ajax_run_chunk']);
    add_action('wp_ajax_wcssd_cancel_job', [$this, 'ajax_cancel_job']);
  }

  public function admin_menu() {
    add_menu_page(
      'WooCommerce Stock Sync - Dwenn', // Page title
      'Stock Sync',                     // Menu title (short)
      'manage_woocommerce',
      self::MENU_SLUG,
      [$this, 'render_page'],
      'dashicons-update',
      56
    );
  }

  private function current_user_id_safe() {
    $uid = get_current_user_id();
    return $uid ? (int)$uid : 0;
  }

  private function job_key($job_id) {
    return 'wcssd_job_' . sanitize_key($job_id);
  }

  private function last_job_meta_key() {
    // Stored per-user so each admin can resume their own job
    return 'wcssd_last_job_id';
  }

  private function make_job_id() {
    return wp_generate_password(20, false, false);
  }

  private function normalize_price($p) {
    $p = trim((string)$p);
    if ($p === '') return '0.00';
    $p = str_replace([' ', "'"], ['', ''], $p);
    $p = str_replace(',', '.', $p);
    $f = floatval($p);
    return number_format($f, 2, '.', '');
  }

  private function normalize_stock($s) {
    $s = trim((string)$s);
    if ($s === '') return 0;
    $s = str_replace([' ', "'"], ['', ''], $s);
    $f = floatval($s);
    return (int)round($f);
  }

  /**
   * Resolve SKUs to posts (product or product_variation) using one DB lookup (chunked IN()).
   * Returns map: sku => ['post_id'=>int, 'post_type'=>string, 'parent_id'=>int]
   */
  private function resolve_skus_to_posts(array $skus) {
    global $wpdb;

    $skus = array_values(array_unique(array_filter(array_map('trim', $skus))));
    if (!$skus) return [];

    $map = [];

    // Chunk IN() to avoid huge SQL queries
    $chunk_size = 500;
    $chunks = array_chunk($skus, $chunk_size);

    foreach ($chunks as $chunk) {
      $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
      $sql = "
        SELECT pm.meta_value AS sku, p.ID AS post_id, p.post_type, p.post_parent
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
        WHERE pm.meta_key = '_sku'
          AND pm.meta_value IN ($placeholders)
          AND p.post_type IN ('product', 'product_variation')
      ";

      $prepared = (count($chunk) === 1)
        ? $wpdb->prepare($sql, $chunk[0])
        : $wpdb->prepare($sql, ...$chunk);

      $rows = $wpdb->get_results($prepared);

      if ($rows) {
        foreach ($rows as $r) {
          $sku = (string)$r->sku;
          if (!isset($map[$sku])) {
            $map[$sku] = [
              'post_id'   => (int)$r->post_id,
              'post_type' => (string)$r->post_type,
              'parent_id' => (int)$r->post_parent,
            ];
          }
        }
      }
    }

    return $map;
  }

  private function wc_set_stock_and_price($product, int $qty, string $price) {
    // Stock
    $product->set_manage_stock(true);
    $product->set_stock_quantity($qty);
    $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');

    // Price: regular price is enough for this use case
    $product->set_regular_price($price);

    $product->save();
  }

  /**
   * Pre-zero helper: set stock to 0 for a product; if variable, set all variations to 0 too.
   */
  private function wc_set_stock_zero_for_product_id(int $product_id, bool $dry_run, array &$lines) {
    $p = wc_get_product($product_id);
    if (!$p) return;

    $type = $p->get_type();

    if ($type === 'variable') {
      $children = $p->get_children();

      if ($children) {
        foreach ($children as $vid) {
          $v = wc_get_product($vid);
          if (!$v) continue;

          if (!$dry_run) {
            $v->set_manage_stock(true);
            $v->set_stock_quantity(0);
            $v->set_stock_status('outofstock');
            $v->save();
          }
        }
      }

      // Parent: status is safe even if parent stock mgmt differs
      if (!$dry_run) {
        $p->set_stock_status('outofstock');
        $p->save();
      }

      $lines[] = $dry_run
        ? "[DRY][PREZERO] variable ID={$product_id} -> variations set to 0"
        : "[OK][PREZERO] variable ID={$product_id} -> variations set to 0";
      return;
    }

    // Simple/others
    if (!$dry_run) {
      $p->set_manage_stock(true);
      $p->set_stock_quantity(0);
      $p->set_stock_status('outofstock');
      $p->save();
    }

    $lines[] = $dry_run
      ? "[DRY][PREZERO] {$type} ID={$product_id} -> stock=0"
      : "[OK][PREZERO] {$type} ID={$product_id} -> stock=0";
  }

  public function render_page() {
    if (!current_user_can('manage_woocommerce')) {
      wp_die('Insufficient permissions.');
    }

    // Categories for pre-zero selection
    $cats = get_terms([
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
    ]);
    if (is_wp_error($cats)) $cats = [];

    $uid = $this->current_user_id_safe();
    $last_job_id = $uid ? get_user_meta($uid, $this->last_job_meta_key(), true) : '';
    $resume_job  = '';

    // Load saved price-adjust defaults
    $saved_adjust = get_option('wcssd_price_adjust');
    if ($saved_adjust === false || $saved_adjust === null) {
      $saved_amount = 0;
      $saved_round = 'integer';
      $has_saved = false;
    } else {
      $saved_amount = isset($saved_adjust['amount']) ? $saved_adjust['amount'] : 0;
      $saved_round = isset($saved_adjust['round']) ? $saved_adjust['round'] : 'none';
      $has_saved = true;
    }

    if ($last_job_id) {
      $job = get_transient($this->job_key($last_job_id));
      if (is_array($job) && !empty($job['job_id'])) {
        $resume_job = $job['job_id'];
      }
    }

    $create_action = admin_url('admin-post.php');
    $ajax_url = admin_url('admin-ajax.php');
    $nonce_create = wp_create_nonce('wcssd_create_job');
    $nonce_ajax   = wp_create_nonce('wcssd_ajax');

    ?>
    <div class="wrap">
      <h1>WooCommerce Stock Sync - Dwenn</h1>

      <div style="max-width: 980px;">
        <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px 16px 8px 16px;margin-bottom:16px;">
          <h2 style="margin-top:0;">Upload CSV</h2>
          <p style="margin-top:0;color:#50575e;">
            Required columns: <code>Sku</code>, <code>Available</code>, <code>Price</code>.
            Only SKUs that exist in WooCommerce will be updated.
          </p>

          <form method="post" action="<?php echo esc_url($create_action); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="wcssd_create_job" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_create); ?>" />

            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="wcssd_csv">CSV file</label></th>
                <td>
                  <input type="file" id="wcssd_csv" name="wcssd_csv" accept=".csv,text/csv" required />
                </td>
              </tr>

              <tr>
                <th scope="row">Pré-traitement</th>
                <td>
                  <label style="display:block;margin-bottom:8px;">
                    <input type="checkbox" id="wcssd_prezero_enable" name="wcssd_prezero_enable" value="1" />
                    Mettre le stock à <strong>0</strong> pour certains produits <strong>avant</strong> la sync (utile si des SKU disparaissent du CSV)
                  </label>

                  <div id="wcssd_prezero_cats_container" style="padding:10px;border:1px solid #dcdcde;border-radius:10px;max-height:220px;overflow:auto;background:#fafafa;">
                    <strong>Catégories à mettre à zéro :</strong>
                    <div style="margin-top:8px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 14px;">
                      <?php foreach ($cats as $c): ?>
                        <label>
                          <input type="checkbox" name="wcssd_prezero_cats[]" value="<?php echo esc_attr($c->term_id); ?>" />
                          <?php echo esc_html($c->name); ?>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <p class="description" style="margin:8px 0 0 0;">
                      Choisis uniquement les catégories liées à ton fournisseur.
                    </p>
                  </div>
                </td>
              </tr>

              <tr>
                <th scope="row"><label for="wcssd_price_adjust">Price adjustment</label></th>
                <td>
                  <input type="number" step="0.01" id="wcssd_price_adjust" name="wcssd_price_adjust_amount" value="<?php echo esc_attr($saved_amount); ?>" />
                  <p class="description">Fixed increase to add to each CSV price (e.g. 100). Can be negative.</p>

                  <!-- Checkbox (not radio): you can turn rounding on/off -->
                  <label style="display:block;margin-top:6px;">
                    <input type="checkbox" name="wcssd_price_adjust_round" value="integer" <?php echo ((!$has_saved || $saved_round === 'integer') ? 'checked' : ''); ?> />
                    Round to nearest integer
                  </label>

                  <label style="display:block;margin-top:6px;">
                    <input type="checkbox" name="wcssd_price_adjust_save" value="1" <?php echo (!$has_saved ? 'checked' : ''); ?> />
                    Save as default
                  </label>
                </td>
              </tr>

              <tr>
                <th scope="row"><label for="wcssd_chunk">Chunk size</label></th>
                <td>
                  <input type="number" id="wcssd_chunk" name="wcssd_chunk" min="5" max="200" value="25" />
                  <p class="description">How many matched SKUs to process per AJAX request (25–50 is usually ideal).</p>
                </td>
              </tr>

              <tr>
                <th scope="row">Options</th>
                <td>
                  <label>
                    <input type="checkbox" name="wcssd_dry_run" value="1" />
                    Dry run (no changes, just simulate)
                  </label>
                </td>
              </tr>
            </table>

            <?php submit_button('Upload and Start Sync', 'primary', 'submit', true); ?>
          </form>
        </div>

        <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px;">
          <h2 style="margin-top:0;">Progress</h2>

          <div id="wcssd_notice_area"></div>

          <div id="wcssd_job_area" style="display:none;">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
              <button class="button button-primary" id="wcssd_start_btn">Start / Resume</button>
              <button class="button" id="wcssd_cancel_btn">Cancel job</button>
              <span id="wcssd_job_label" style="color:#50575e;"></span>
            </div>

            <div style="height:14px;background:#f0f0f1;border-radius:999px;overflow:hidden;border:1px solid #dcdcde;">
              <div id="wcssd_bar" style="height:100%;width:0%;background:linear-gradient(90deg,#2271b1,#3c8dbc);"></div>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;">
              <div><strong>Processed:</strong> <span id="wcssd_processed">0</span>/<span id="wcssd_total">0</span></div>
              <div><strong>Updated:</strong> <span id="wcssd_updated">0</span></div>
              <div><strong>Missing:</strong> <span id="wcssd_missing">0</span></div>
              <div><strong>Errors:</strong> <span id="wcssd_errors">0</span></div>
              <div><strong>Mode:</strong> <span id="wcssd_mode">—</span></div>
            </div>

            <div style="margin-top:10px;color:#50575e;">
              <strong>Current:</strong> <span id="wcssd_current">—</span>
            </div>

            <details style="margin-top:12px;">
              <summary>Show log</summary>
              <pre id="wcssd_log" style="white-space:pre-wrap;background:#0b1220;color:#d7e0ea;padding:12px;border-radius:10px;max-height:260px;overflow:auto;"></pre>
            </details>
          </div>

          <?php if ($resume_job): ?>
            <script>
              window._WCSSD_RESUME_JOB_ = <?php echo json_encode($resume_job); ?>;
            </script>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const ajaxUrl = <?php echo json_encode($ajax_url); ?>;
      const nonceAjax = <?php echo json_encode($nonce_ajax); ?>;

      const area = document.getElementById('wcssd_job_area');
      const notice = document.getElementById('wcssd_notice_area');
      const startBtn = document.getElementById('wcssd_start_btn');
      const cancelBtn = document.getElementById('wcssd_cancel_btn');

      const bar = document.getElementById('wcssd_bar');
      const processedEl = document.getElementById('wcssd_processed');
      const totalEl = document.getElementById('wcssd_total');
      const updatedEl = document.getElementById('wcssd_updated');
      const missingEl = document.getElementById('wcssd_missing');
      const errorsEl = document.getElementById('wcssd_errors');
      const currentEl = document.getElementById('wcssd_current');
      const modeEl = document.getElementById('wcssd_mode');
      const logEl = document.getElementById('wcssd_log');
      const jobLabel = document.getElementById('wcssd_job_label');

      let jobId = window._WCSSD_RESUME_JOB_ || '';
      let running = false;

      function setNotice(type, msg) {
        notice.innerHTML = '';
        var d = document.createElement('div');
        d.className = 'notice notice-' + type;
        d.style.margin = '0 0 12px 0';
        var p = document.createElement('p');
        p.textContent = msg;
        d.appendChild(p);
        notice.appendChild(d);
      }

      function logLine(line) {
        logEl.textContent += (line + "\n");
        logEl.scrollTop = logEl.scrollHeight;
      }

      function updateUI(state) {
        processedEl.textContent = state.processed;
        totalEl.textContent = state.total;
        updatedEl.textContent = state.updated;
        missingEl.textContent = state.missing;
        errorsEl.textContent = state.errors;

        const phase = state.phase ? String(state.phase) : '';
        modeEl.textContent = (state.dry_run ? 'Dry run' : 'Live') + (phase ? (' / ' + phase) : '');

        jobLabel.textContent = jobId ? ('Job: ' + jobId) : '';
        currentEl.textContent = state.current || '—';

        const pct = state.total > 0 ? Math.round((state.processed / state.total) * 100) : 0;
        bar.style.width = pct + '%';
      }

      async function post(params) {
        const body = new URLSearchParams(params);
        const res = await fetch(ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
          body
        });
        return res.json();
      }

      async function tick() {
        if (!running || !jobId) return;

        const data = await post({
          action: 'wcssd_run_chunk',
          _wpnonce: nonceAjax,
          job_id: jobId
        });

        if (!data || !data.success) {
          running = false;
          setNotice('error', (data && data.data && data.data.message) ? data.data.message : 'AJAX error.');
          return;
        }

        updateUI(data.data.state);

        if (data.data.lines && data.data.lines.length) {
          data.data.lines.forEach(logLine);
        }

        if (data.data.done) {
          running = false;
          setNotice('success', 'Done. Job cleaned up.');
          return;
        }

        setTimeout(tick, 150);
      }

      startBtn.addEventListener('click', function(){
        if (!jobId) {
          setNotice('warning', 'No active job. Upload a CSV first.');
          return;
        }
        if (running) return;
        running = true;
        setNotice('info', 'Running…');
        tick();
      });

      cancelBtn.addEventListener('click', async function(){
        if (!jobId) return;
        running = false;

        const data = await post({
          action: 'wcssd_cancel_job',
          _wpnonce: nonceAjax,
          job_id: jobId
        });

        if (data && data.success) {
          setNotice('success', 'Job cancelled and cleaned up.');
          jobId = '';
          jobLabel.textContent = '';
          bar.style.width = '0%';
          currentEl.textContent = '—';
        } else {
          setNotice('error', (data && data.data && data.data.message) ? data.data.message : 'Cancel failed.');
        }
      });

      async function initResume() {
        if (!jobId) return;
        area.style.display = 'block';
        setNotice('info', 'Resumable job detected. Click Start/Resume.');

        const data = await post({
          action: 'wcssd_run_chunk',
          _wpnonce: nonceAjax,
          job_id: jobId,
          peek: '1'
        });

        if (data && data.success) {
          updateUI(data.data.state);
          if (data.data.lines && data.data.lines.length) data.data.lines.forEach(logLine);
        }
      }

      area.style.display = jobId ? 'block' : 'none';
      initResume();

      // Pre-zero categories toggle: disable category checkboxes until pre-zero enabled
      (function(){
        const prezeroEnable = document.getElementById('wcssd_prezero_enable');
        const prezeroCats = Array.from(document.querySelectorAll('input[name="wcssd_prezero_cats[]"]'));
        function setPrezeroState() {
          const enabled = prezeroEnable && prezeroEnable.checked;
          prezeroCats.forEach(function(i){ i.disabled = !enabled; });
        }
        if (prezeroEnable) {
          setPrezeroState();
          prezeroEnable.addEventListener('change', setPrezeroState);
        }
      })();

      const url = new URL(window.location.href);
      const fromJob = url.searchParams.get('wcssd_job');
      if (fromJob) {
        jobId = fromJob;
        area.style.display = 'block';
        setNotice('success', 'Job created. Starting…');
        url.searchParams.delete('wcssd_job');
        window.history.replaceState({}, '', url.toString());
        initResume().then(function(){
          if (!running) {
            running = true;
            setNotice('info', 'Running…');
            tick();
          }
        });
      }

      const created = url.searchParams.get('wcssd_created');
      if (created === '1') {
        setNotice('success', 'Job created. Click Start/Resume.');
        url.searchParams.delete('wcssd_created');
        window.history.replaceState({}, '', url.toString());
      }
    })();
    </script>
    <?php
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

    $tmp = $_FILES['wcssd_csv']['tmp_name'];

    if (!is_uploaded_file($tmp)) {
      wp_die('Invalid upload.');
    }

    $max_size = 5 * 1024 * 1024; // 5 MB
    if (isset($_FILES['wcssd_csv']['size']) && $_FILES['wcssd_csv']['size'] > $max_size) {
      wp_die('Uploaded CSV is too large. Max 5 MB.');
    }

    if (!class_exists('WooCommerce')) {
      wp_die('WooCommerce is not active.');
    }

    $chunk_size = isset($_POST['wcssd_chunk']) ? (int)$_POST['wcssd_chunk'] : 25;
    if ($chunk_size < 5) $chunk_size = 5;
    if ($chunk_size > 200) $chunk_size = 200;

    $dry_run = !empty($_POST['wcssd_dry_run']);

    // Pre-zero options
    $prezero_enable = !empty($_POST['wcssd_prezero_enable']);
    $prezero_cats = [];
    if ($prezero_enable && !empty($_POST['wcssd_prezero_cats']) && is_array($_POST['wcssd_prezero_cats'])) {
      $prezero_cats = array_values(array_unique(array_filter(array_map('intval', $_POST['wcssd_prezero_cats']))));
    }
    // Safety: if enabled but no categories, disable to avoid "all products to zero"
    if ($prezero_enable && empty($prezero_cats)) {
      $prezero_enable = false;
    }

    // Load saved defaults for price adjust
    $saved_adjust = get_option('wcssd_price_adjust');
    $saved_amount = 0.0;
    $saved_round  = 'none';
    if (is_array($saved_adjust)) {
      if (isset($saved_adjust['amount'])) $saved_amount = floatval($saved_adjust['amount']);
      if (isset($saved_adjust['round']))  $saved_round  = ($saved_adjust['round'] === 'integer') ? 'integer' : 'none';
    }

    // Parse CSV now (no file saved permanently)
    $handle = fopen($tmp, 'r');
    if (!$handle) {
      wp_die('Unable to read uploaded CSV.');
    }

    // Support UTF-8 BOM
    $first = fgets($handle);
    if ($first === false) {
      fclose($handle);
      wp_die('CSV appears empty.');
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $headers = str_getcsv($first);

    $required = ['Sku', 'Available', 'Price'];
    $header_map = [];
    foreach ($headers as $idx => $h) {
      $header_map[trim($h)] = $idx;
    }
    foreach ($required as $col) {
      if (!isset($header_map[$col])) {
        fclose($handle);
        wp_die('Missing required column: ' . esc_html($col));
      }
    }

    $rows = [];
    $skus = [];

    // Determine price adjustment to apply for this run.
    // Note: number input always posts something (default "0"), but we keep it robust anyway.
    $posted_amount = isset($_POST['wcssd_price_adjust_amount']) ? floatval($_POST['wcssd_price_adjust_amount']) : null;
    $posted_round  = !empty($_POST['wcssd_price_adjust_round']) ? 'integer' : 'none';

    // If user checked "Save as default", persist settings.
    if (!empty($_POST['wcssd_price_adjust_save'])) {
      $to_save = [
        'amount' => ($posted_amount !== null) ? $posted_amount : $saved_amount,
        'round'  => $posted_round,
      ];
      update_option('wcssd_price_adjust', $to_save);
    }

    // Use posted override if provided, otherwise fallback to saved values
    $adjust_amount = ($posted_amount !== null) ? floatval($posted_amount) : floatval($saved_amount);
    $adjust_round  = $posted_round;

    while (($line = fgets($handle)) !== false) {
      $cols = str_getcsv($line);
      $sku = isset($cols[$header_map['Sku']]) ? trim((string)$cols[$header_map['Sku']]) : '';
      if ($sku === '') continue;

      $qty = isset($cols[$header_map['Available']]) ? $this->normalize_stock($cols[$header_map['Available']]) : 0;
      $price = isset($cols[$header_map['Price']]) ? $this->normalize_price($cols[$header_map['Price']]) : '0.00';

      // Apply fixed adjustment (+ optional rounding)
      $orig_f = floatval($price);
      $adj_f  = $orig_f + floatval($adjust_amount);
      if ($adjust_round === 'integer') {
        $adj_f = round($adj_f);
      }
      $adj_price = number_format($adj_f, 2, '.', '');

      $rows[] = ['sku'=>$sku, 'qty'=>$qty, 'price'=>$adj_price, 'orig_price'=>$price];
      $skus[] = $sku;
    }
    fclose($handle);

    // Resolve SKUs in one (chunked) DB lookup
    $sku_to_post = $this->resolve_skus_to_posts($skus);

    $tasks = [];
    $missing = 0;

    // If CSV has duplicate SKUs, keep last occurrence
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

    $job_id = $this->make_job_id();
    $job = [
      'job_id'     => $job_id,
      'created'    => time(),
      'dry_run'    => (bool)$dry_run,
      'chunk'      => $chunk_size,

      // pre-zero
      'phase'           => ($prezero_enable ? 'prezero' : 'sync'),
      'prezero_enable'  => (bool)$prezero_enable,
      'prezero_cats'    => $prezero_cats,
      'prezero_offset'  => 0,
      'prezero_done'    => false,
      'prezero_done_at' => 0,

      // sync
      'tasks'      => $tasks,
      'total'      => count($tasks),
      'processed'  => 0,
      'updated'    => 0,
      'missing'    => $missing,
      'errors'     => 0,
      'error_msgs' => [],
      'last_sku'   => '',
    ];

    set_transient($this->job_key($job_id), $job, self::JOB_TTL);

    $uid = $this->current_user_id_safe();
    if ($uid) {
      update_user_meta($uid, $this->last_job_meta_key(), $job_id);
    }

    $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&wcssd_job=' . urlencode($job_id));
    wp_safe_redirect($url);
    exit;
  }

  public function ajax_cancel_job() {
    if (!current_user_can('manage_woocommerce')) {
      wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
    }
    check_ajax_referer('wcssd_ajax');

    $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
    if (!$job_id) wp_send_json_error(['message' => 'Missing job_id.'], 400);

    delete_transient($this->job_key($job_id));

    $uid = $this->current_user_id_safe();
    if ($uid) delete_user_meta($uid, $this->last_job_meta_key());

    wp_send_json_success(['ok' => true]);
  }

  public function ajax_run_chunk() {
    if (!current_user_can('manage_woocommerce')) {
      wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
    }
    check_ajax_referer('wcssd_ajax');

    if (!class_exists('WooCommerce')) {
      wp_send_json_error(['message' => 'WooCommerce is not active.'], 400);
    }

    $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
    $peek   = !empty($_POST['peek']);

    if (!$job_id) wp_send_json_error(['message' => 'Missing job_id.'], 400);

    $key = $this->job_key($job_id);
    $job = get_transient($key);
    if (!is_array($job) || empty($job['job_id'])) {
      wp_send_json_error(['message' => 'Job not found or expired. Upload CSV again.'], 404);
    }

    $lines = [];

    // Peek = return current state without performing updates
    if ($peek) {
      wp_send_json_success([
        'state' => $this->job_state($job),
        'lines' => $lines,
        'done'  => (!empty($job['prezero_enable']) && empty($job['prezero_done'])) ? false : ($job['processed'] >= $job['total']),
      ]);
    }

    // -------- PRE-ZERO PHASE (runs before sync, chunked) --------
    if (!empty($job['prezero_enable']) && empty($job['prezero_done'])) {

      $chunk = isset($job['chunk']) ? (int)$job['chunk'] : 25;
      if ($chunk < 5) $chunk = 5;
      if ($chunk > 200) $chunk = 200;

      $offset  = isset($job['prezero_offset']) ? (int)$job['prezero_offset'] : 0;
      $cat_ids = (!empty($job['prezero_cats']) && is_array($job['prezero_cats'])) ? array_map('intval', $job['prezero_cats']) : [];

      if (!$cat_ids) {
        $job['prezero_done'] = true;
        $job['phase'] = 'sync';
        set_transient($key, $job, self::JOB_TTL);

        wp_send_json_success([
          'state' => $this->job_state($job),
          'lines' => ["[WARN][PREZERO] No categories selected. Skipping pre-zero."],
          'done'  => false,
        ]);
      }

      // NOTE: offset pagination is okay for a one-off admin job.
      // If you ever do this on huge catalogs, consider paging by ID > last_id.
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
        // finished pre-zero
        $job['prezero_done'] = true;
        $job['prezero_done_at'] = time();
        $job['phase'] = 'sync';
        $job['last_sku'] = '';
        $lines[] = "[OK][PREZERO] Completed. Switching to sync phase.";

        set_transient($key, $job, self::JOB_TTL);

        wp_send_json_success([
          'state' => $this->job_state($job),
          'lines' => $lines,
          'done'  => false,
        ]);
      }

      foreach ($ids as $pid) {
        try {
          $this->wc_set_stock_zero_for_product_id($pid, !empty($job['dry_run']), $lines);
        } catch (Throwable $e) {
          $job['errors']++;
          $lines[] = "[ERROR][PREZERO] Product ID {$pid} -> " . $e->getMessage();
        }
      }

      $job['prezero_offset'] = $offset + count($ids);
      $job['last_sku'] = 'PREZERO…';
      $job['phase'] = 'prezero';

      set_transient($key, $job, self::JOB_TTL);

      wp_send_json_success([
        'state' => $this->job_state($job),
        'lines' => $lines,
        'done'  => false,
      ]);
    }
    // -------- END PRE-ZERO PHASE --------

    // -------- SYNC PHASE (CSV tasks) --------
    $chunk = isset($job['chunk']) ? (int)$job['chunk'] : 25;
    if ($chunk < 5) $chunk = 5;
    if ($chunk > 200) $chunk = 200;

    $job['phase'] = 'sync';

    $start = (int)$job['processed'];
    $end   = min($job['total'], $start + $chunk);

    if ($start >= $job['total']) {
      // done: cleanup
      delete_transient($key);
      $uid = $this->current_user_id_safe();
      if ($uid) delete_user_meta($uid, $this->last_job_meta_key());

      wp_send_json_success([
        'state' => $this->job_state($job),
        'lines' => $lines,
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
            $this->wc_set_stock_and_price($product, (int)$t['qty'], (string)$t['price']);
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

    // Persist updated job state & refresh TTL
    set_transient($key, $job, self::JOB_TTL);

    $done = ($job['processed'] >= $job['total']);
    if ($done) {
      delete_transient($key);
      $uid = $this->current_user_id_safe();
      if ($uid) delete_user_meta($uid, $this->last_job_meta_key());
    }

    wp_send_json_success([
      'state' => $this->job_state($job),
      'lines' => $lines,
      'done'  => $done,
    ]);
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
    ];
  }
}

new WCSSD_WooCommerce_Stock_Sync_Dwenn();
