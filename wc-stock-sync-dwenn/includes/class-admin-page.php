<?php

if (!defined('ABSPATH')) exit;

class WCSSD_AdminPage {
  private $job_store;

  public function __construct(WCSSD_JobStore $job_store) {
    $this->job_store = $job_store;
  }

  public function render() {
    if (!current_user_can('manage_woocommerce')) {
      wp_die('Insufficient permissions.');
    }

    $cats = get_terms([
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
    ]);
    if (is_wp_error($cats)) $cats = [];

    $resume_job = $this->job_store->get_resume_job_id();
    $profiles = WCSSD_Plugin::get_supplier_profiles();

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
            Required columns used by the sync: <code>Sku</code>, <code>Available</code>, <code>Price</code>.
            Extra columns are ignored and header matching is flexible.
            Only SKUs that exist in WooCommerce will be updated.
          </p>

          <form method="post" action="<?php echo esc_url($create_action); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="wcssd_create_job" />
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce_create); ?>" />

            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="wcssd_profile_select">Supplier profile</label></th>
                <td>
                  <select id="wcssd_profile_select">
                    <option value="">No profile</option>
                    <?php foreach ($profiles as $profile_key => $profile): ?>
                      <option value="<?php echo esc_attr($profile_key); ?>"><?php echo esc_html(!empty($profile['name']) ? $profile['name'] : $profile_key); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" id="wcssd_profile_name" name="wcssd_profile_name" placeholder="Profile name" style="margin-left:8px;min-width:220px;" />
                  <label style="margin-left:8px;">
                    <input type="checkbox" name="wcssd_profile_save" value="1" />
                    Save profile
                  </label>
                  <p class="description">Profiles store delimiter, column names, price adjustment, and pre-zero categories.</p>
                </td>
              </tr>

              <tr>
                <th scope="row"><label for="wcssd_csv">CSV file</label></th>
                <td>
                  <input type="file" id="wcssd_csv" name="wcssd_csv" accept=".csv,text/csv" required />
                </td>
              </tr>

              <tr>
                <th scope="row"><label for="wcssd_delimiter">CSV delimiter</label></th>
                <td>
                  <select id="wcssd_delimiter" name="wcssd_delimiter">
                    <option value="auto">Auto-detect</option>
                    <option value=",">Comma (,)</option>
                    <option value=";">Semicolon (;)</option>
                    <option value="tab">Tab</option>
                  </select>
                </td>
              </tr>

              <tr>
                <th scope="row">CSV columns</th>
                <td>
                  <label style="display:inline-block;margin-right:10px;">
                    SKU
                    <input type="text" id="wcssd_col_sku" name="wcssd_col_sku" value="Sku" style="display:block;min-width:160px;" />
                  </label>
                  <label style="display:inline-block;margin-right:10px;">
                    Stock
                    <input type="text" id="wcssd_col_available" name="wcssd_col_available" value="Available" style="display:block;min-width:160px;" />
                  </label>
                  <label style="display:inline-block;">
                    Price
                    <input type="text" id="wcssd_col_price" name="wcssd_col_price" value="Price" style="display:block;min-width:160px;" />
                  </label>
                  <p class="description">Leave defaults unless the supplier uses different header names.</p>
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
              <button class="button" id="wcssd_export_btn" disabled>Exporter le rapport</button>
              <span id="wcssd_job_label" style="color:#50575e;"></span>
            </div>

            <div style="height:14px;background:#f0f0f1;border-radius:999px;overflow:hidden;border:1px solid #dcdcde;">
              <div id="wcssd_bar" style="height:100%;width:0%;background:linear-gradient(90deg,#2271b1,#3c8dbc);"></div>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;">
              <div><strong>Processed:</strong> <span id="wcssd_processed">0</span>/<span id="wcssd_total">0</span></div>
              <div><strong>Pre-zero:</strong> <span id="wcssd_prezero_processed">0</span>/<span id="wcssd_prezero_total">0</span></div>
              <div><strong>Updated:</strong> <span id="wcssd_updated">0</span></div>
              <div><strong>Missing:</strong> <span id="wcssd_missing">0</span></div>
              <div><strong>Errors:</strong> <span id="wcssd_errors">0</span></div>
              <div><strong>Delimiter:</strong> <span id="wcssd_delimiter_label">—</span></div>
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
              window._WCSSD_RESUME_JOB_ = <?php echo wp_json_encode($resume_job); ?>;
            </script>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <script>
    (function(){
      const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
      const nonceAjax = <?php echo wp_json_encode($nonce_ajax); ?>;
      const profiles = <?php echo wp_json_encode($profiles); ?>;

      const area = document.getElementById('wcssd_job_area');
      const notice = document.getElementById('wcssd_notice_area');
      const startBtn = document.getElementById('wcssd_start_btn');
      const cancelBtn = document.getElementById('wcssd_cancel_btn');
      const exportBtn = document.getElementById('wcssd_export_btn');

      const bar = document.getElementById('wcssd_bar');
      const processedEl = document.getElementById('wcssd_processed');
      const totalEl = document.getElementById('wcssd_total');
      const prezeroProcessedEl = document.getElementById('wcssd_prezero_processed');
      const prezeroTotalEl = document.getElementById('wcssd_prezero_total');
      const updatedEl = document.getElementById('wcssd_updated');
      const missingEl = document.getElementById('wcssd_missing');
      const errorsEl = document.getElementById('wcssd_errors');
      const delimiterLabelEl = document.getElementById('wcssd_delimiter_label');
      const currentEl = document.getElementById('wcssd_current');
      const modeEl = document.getElementById('wcssd_mode');
      const logEl = document.getElementById('wcssd_log');
      const jobLabel = document.getElementById('wcssd_job_label');

      let jobId = window._WCSSD_RESUME_JOB_ || '';
      let running = false;
      let reportText = '';

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
        prezeroProcessedEl.textContent = state.prezero_processed || 0;
        prezeroTotalEl.textContent = state.prezero_total || 0;
        updatedEl.textContent = state.updated;
        missingEl.textContent = state.missing;
        errorsEl.textContent = state.errors;
        delimiterLabelEl.textContent = state.delimiter || '—';

        const phase = state.phase ? String(state.phase) : '';
        modeEl.textContent = (state.dry_run ? 'Dry run' : 'Live') + (phase ? (' / ' + phase) : '');

        jobLabel.textContent = jobId ? ('Job: ' + jobId) : '';
        currentEl.textContent = state.current || '—';

        const pct = state.total > 0 ? Math.round((state.processed / state.total) * 100) : 0;
        bar.style.width = pct + '%';
        exportBtn.disabled = !reportText;
      }

      function setReport(text) {
        reportText = text || logEl.textContent || '';
        exportBtn.disabled = !reportText;
      }

      function downloadReport() {
        if (!reportText) return;
        const blob = new Blob([reportText], {type: 'text/plain;charset=utf-8'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'wc-stock-sync-report-' + (jobId || 'job') + '.txt';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
      }

      async function post(params) {
        const body = new URLSearchParams(params);
        let res;

        try {
          res = await fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body
          });
        } catch (error) {
          throw new Error('Network error. Please retry.');
        }

        let data;
        try {
          data = await res.json();
        } catch (error) {
          throw new Error('Unexpected server response.');
        }

        if (!res.ok && (!data || !data.data || !data.data.message)) {
          throw new Error('HTTP error ' + res.status + '.');
        }

        return data;
      }

      async function tick() {
        if (!running || !jobId) return;

        let data;
        try {
          data = await post({
            action: 'wcssd_run_chunk',
            _wpnonce: nonceAjax,
            job_id: jobId
          });
        } catch (error) {
          running = false;
          setNotice('error', (error && error.message) ? error.message : 'AJAX error.');
          return;
        }

        if (!data || !data.success) {
          running = false;
          setNotice('error', (data && data.data && data.data.message) ? data.data.message : 'AJAX error.');
          return;
        }

        updateUI(data.data.state);

        if (data.data.lines && data.data.lines.length) {
          data.data.lines.forEach(logLine);
        }
        setReport(data.data.report || logEl.textContent);

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

        let data;
        try {
          data = await post({
            action: 'wcssd_cancel_job',
            _wpnonce: nonceAjax,
            job_id: jobId
          });
        } catch (error) {
          setNotice('error', (error && error.message) ? error.message : 'Cancel failed.');
          return;
        }

        if (data && data.success) {
          setNotice('success', 'Job cancelled and cleaned up.');
          jobId = '';
          jobLabel.textContent = '';
          bar.style.width = '0%';
          currentEl.textContent = '—';
          setReport('');
        } else {
          setNotice('error', (data && data.data && data.data.message) ? data.data.message : 'Cancel failed.');
        }
      });

      exportBtn.addEventListener('click', function(event){
        event.preventDefault();
        downloadReport();
      });

      async function initResume() {
        if (!jobId) return;
        area.style.display = 'block';
        setNotice('info', 'Resumable job detected. Click Start/Resume.');

        let data;
        try {
          data = await post({
            action: 'wcssd_run_chunk',
            _wpnonce: nonceAjax,
            job_id: jobId,
            peek: '1'
          });
        } catch (error) {
          setNotice('error', (error && error.message) ? error.message : 'Resume failed.');
          return;
        }

        if (data && data.success) {
          updateUI(data.data.state);
          if (data.data.lines && data.data.lines.length) data.data.lines.forEach(logLine);
          setReport(data.data.report || logEl.textContent);
        }
      }

      area.style.display = jobId ? 'block' : 'none';
      initResume();

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

      (function(){
        const profileSelect = document.getElementById('wcssd_profile_select');
        const profileName = document.getElementById('wcssd_profile_name');
        const delimiter = document.getElementById('wcssd_delimiter');
        const colSku = document.getElementById('wcssd_col_sku');
        const colAvailable = document.getElementById('wcssd_col_available');
        const colPrice = document.getElementById('wcssd_col_price');
        const adjust = document.getElementById('wcssd_price_adjust');
        const round = document.querySelector('input[name="wcssd_price_adjust_round"]');
        const prezeroEnable = document.getElementById('wcssd_prezero_enable');
        const prezeroCats = Array.from(document.querySelectorAll('input[name="wcssd_prezero_cats[]"]'));

        function setProfile(profile) {
          if (!profile) return;
          profileName.value = profile.name || '';
          delimiter.value = profile.delimiter || 'auto';
          colSku.value = profile.columns && profile.columns.sku ? profile.columns.sku : 'Sku';
          colAvailable.value = profile.columns && profile.columns.available ? profile.columns.available : 'Available';
          colPrice.value = profile.columns && profile.columns.price ? profile.columns.price : 'Price';
          adjust.value = typeof profile.price_adjust_amount !== 'undefined' ? profile.price_adjust_amount : 0;
          round.checked = profile.price_adjust_round === 'integer';
          prezeroEnable.checked = !!profile.prezero_enable;

          const catIds = (profile.prezero_cats || []).map(function(id){ return String(id); });
          prezeroCats.forEach(function(input){
            input.checked = catIds.indexOf(String(input.value)) !== -1;
            input.disabled = !prezeroEnable.checked;
          });
        }

        if (profileSelect) {
          profileSelect.addEventListener('change', function(){
            setProfile(profiles[profileSelect.value]);
          });
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
}
