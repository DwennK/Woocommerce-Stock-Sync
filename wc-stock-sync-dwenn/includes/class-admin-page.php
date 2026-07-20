<?php

if (!defined('ABSPATH')) exit;

class WCSSD_AdminPage {
  private $job_store;

  public function __construct(WCSSD_JobStore $job_store) {
    $this->job_store = $job_store;
  }

  public function render() {
    if (!current_user_can('manage_woocommerce')) wp_die('Insufficient permissions.');

    $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    if (is_wp_error($cats)) $cats = [];
    $profiles = WCSSD_Plugin::get_supplier_profiles();
    $resume_job = $this->job_store->get_resume_job_id();
    $recent_jobs = $this->job_store->recent_for_current_user(10);
    $saved_adjust = get_option('wcssd_price_adjust');
    $saved_amount = is_array($saved_adjust) && isset($saved_adjust['amount']) ? $saved_adjust['amount'] : 0;
    $saved_round = is_array($saved_adjust) && isset($saved_adjust['round']) ? $saved_adjust['round'] : 'none';
    $ajax_url = admin_url('admin-ajax.php');
    $admin_post_url = admin_url('admin-post.php');
    $nonce_ajax = wp_create_nonce('wcssd_ajax');
    $nonce_export = wp_create_nonce('wcssd_export_job');
    ?>
    <div class="wrap wcssd-wrap">
      <h1>WooCommerce Stock Sync</h1>
      <p class="wcssd-lead">Analysez le fichier, vérifiez chaque changement, puis confirmez l’import. Aucune donnée WooCommerce n’est modifiée pendant la prévisualisation.</p>

      <div class="wcssd-grid">
        <section class="wcssd-card">
          <h2>1. Analyser un CSV</h2>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <input type="hidden" name="action" value="wcssd_create_job" />
            <?php wp_nonce_field('wcssd_create_job'); ?>

            <table class="form-table" role="presentation">
              <tr>
                <th scope="row"><label for="wcssd_profile_select">Profil fournisseur</label></th>
                <td>
                  <select id="wcssd_profile_select">
                    <option value="">Aucun profil</option>
                    <?php foreach ($profiles as $profile_key => $profile): ?>
                      <option value="<?php echo esc_attr($profile_key); ?>"><?php echo esc_html(!empty($profile['name']) ? $profile['name'] : $profile_key); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" id="wcssd_profile_name" name="wcssd_profile_name" placeholder="Nom du profil" />
                  <label><input type="checkbox" name="wcssd_profile_save" value="1" /> Enregistrer</label>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="wcssd_csv">Fichier CSV</label></th>
                <td><input type="file" id="wcssd_csv" name="wcssd_csv" accept=".csv,text/csv" required /></td>
              </tr>
              <tr>
                <th scope="row"><label for="wcssd_delimiter">Séparateur</label></th>
                <td>
                  <select id="wcssd_delimiter" name="wcssd_delimiter">
                    <option value="auto">Détection automatique</option>
                    <option value=",">Virgule</option>
                    <option value=";">Point-virgule</option>
                    <option value="tab">Tabulation</option>
                  </select>
                </td>
              </tr>
              <tr>
                <th scope="row">Colonnes</th>
                <td class="wcssd-columns">
                  <label>SKU <input type="text" id="wcssd_col_sku" name="wcssd_col_sku" value="Sku" /></label>
                  <label>Stock <input type="text" id="wcssd_col_available" name="wcssd_col_available" value="Available" /></label>
                  <label>Prix <input type="text" id="wcssd_col_price" name="wcssd_col_price" value="Price" /></label>
                </td>
              </tr>
              <tr>
                <th scope="row">Pré-zero</th>
                <td>
                  <label><input type="checkbox" id="wcssd_prezero_enable" name="wcssd_prezero_enable" value="1" /> Mettre à zéro les catégories sélectionnées avant la synchronisation</label>
                  <div id="wcssd_prezero_cats_container" class="wcssd-categories">
                    <?php foreach ($cats as $cat): ?>
                      <label><input type="checkbox" name="wcssd_prezero_cats[]" value="<?php echo esc_attr($cat->term_id); ?>" /> <?php echo esc_html($cat->name); ?></label>
                    <?php endforeach; ?>
                  </div>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="wcssd_price_adjust">Ajustement du prix</label></th>
                <td>
                  <input type="number" step="0.01" id="wcssd_price_adjust" name="wcssd_price_adjust_amount" value="<?php echo esc_attr($saved_amount); ?>" />
                  <label><input type="checkbox" name="wcssd_price_adjust_round" value="integer" <?php checked($saved_round, 'integer'); ?> /> Arrondir à l’entier</label>
                  <label><input type="checkbox" name="wcssd_price_adjust_save" value="1" /> Enregistrer par défaut</label>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="wcssd_chunk">Taille des lots</label></th>
                <td><input type="number" id="wcssd_chunk" name="wcssd_chunk" min="5" max="200" value="25" /></td>
              </tr>
              <tr>
                <th scope="row">Mode</th>
                <td>
                  <label><input type="checkbox" name="wcssd_dry_run" value="1" /> Prévisualisation uniquement, sans possibilité d’appliquer</label><br />
                  <label><input type="checkbox" id="wcssd_skip_invalid" name="wcssd_skip_invalid" value="1" /> Ignorer les lignes invalides après confirmation</label>
                  <p class="description">Les SKU dupliqués ou ambigus restent toujours bloquants.</p>
                </td>
              </tr>
            </table>
            <?php submit_button('Analyser le CSV', 'primary', 'submit', true); ?>
          </form>
        </section>

        <section class="wcssd-card" id="wcssd_job_area" style="display:none;">
          <div class="wcssd-heading">
            <div><h2>2. Prévisualiser et appliquer</h2><span id="wcssd_job_label"></span></div>
            <span class="wcssd-status" id="wcssd_status">—</span>
          </div>
          <div id="wcssd_notice_area"></div>
          <div class="wcssd-actions">
            <button class="button button-primary" id="wcssd_start_btn" disabled>Appliquer les changements</button>
            <button class="button" id="wcssd_cancel_btn">Annuler le job</button>
            <button class="button" id="wcssd_rollback_btn" disabled>Restaurer les anciennes valeurs</button>
            <button class="button" id="wcssd_export_btn" disabled>Exporter le rapport</button>
            <button class="button button-link-delete" id="wcssd_delete_btn" disabled>Supprimer ce job</button>
          </div>
          <div class="wcssd-progress"><div id="wcssd_bar"></div></div>
          <div class="wcssd-stats">
            <div><strong id="wcssd_changed">0</strong><span>à modifier</span></div>
            <div><strong id="wcssd_unchanged">0</strong><span>inchangés</span></div>
            <div><strong id="wcssd_missing">0</strong><span>SKU absents</span></div>
            <div><strong id="wcssd_invalid">0</strong><span>invalides</span></div>
            <div><strong id="wcssd_conflicts">0</strong><span>bloquants</span></div>
            <div><strong id="wcssd_updated">0</strong><span>appliqués</span></div>
            <div><strong id="wcssd_errors">0</strong><span>erreurs</span></div>
          </div>

          <div class="wcssd-preview-heading">
            <h3>Aperçu détaillé <small>(100 lignes par page, invalides en premier)</small></h3>
            <div class="wcssd-preview-filters">
              <label>État
                <select id="wcssd_preview_status">
                  <option value="all">Tous</option>
                  <option value="invalid">Invalides</option>
                  <option value="conflict">Bloquants</option>
                  <option value="missing">SKU absents</option>
                  <option value="ready">À modifier</option>
                  <option value="unchanged">Inchangés</option>
                  <option value="applied">Appliqués</option>
                  <option value="failed">Échecs</option>
                  <option value="rollback_failed">Conflits de rollback</option>
                </select>
              </label>
              <label>SKU <input type="search" id="wcssd_preview_search" placeholder="Rechercher…" /></label>
              <button class="button" id="wcssd_preview_filter_btn">Filtrer</button>
            </div>
          </div>
          <div class="wcssd-table-wrap">
            <table class="widefat striped">
              <thead><tr><th>Ligne</th><th>Opération</th><th>SKU</th><th>Stock actuel → nouveau</th><th>Prix actuel → nouveau</th><th>État</th><th>Détail</th></tr></thead>
              <tbody id="wcssd_preview_body"></tbody>
            </table>
          </div>
          <div class="wcssd-pagination">
            <button class="button" id="wcssd_preview_prev" disabled>Précédent</button>
            <span id="wcssd_preview_page">Page 1/1</span>
            <button class="button" id="wcssd_preview_next" disabled>Suivant</button>
          </div>
          <details><summary>Journal technique</summary><pre id="wcssd_log"></pre></details>
        </section>

        <?php if ($recent_jobs): ?>
          <section class="wcssd-card">
            <h2>Historique récent</h2>
            <div class="wcssd-table-wrap">
              <table class="widefat striped">
                <thead><tr><th>Date</th><th>Profil</th><th>Mode</th><th>État</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($recent_jobs as $recent_job): ?>
                    <tr>
                      <td><?php echo esc_html($recent_job['created_at']); ?> UTC</td>
                      <td><?php echo esc_html($recent_job['profile_name'] !== '' ? $recent_job['profile_name'] : '—'); ?></td>
                      <td><?php echo esc_html($recent_job['mode']); ?></td>
                      <td><?php echo esc_html($recent_job['status']); ?></td>
                      <td><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wc-stock-sync-dwenn&wcssd_job=' . rawurlencode($recent_job['job_key']))); ?>">Ouvrir</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>
      </div>
    </div>

    <style>
      .wcssd-wrap{max-width:1440px}
      .wcssd-lead{max-width:900px;color:#50575e;font-size:14px}
      .wcssd-grid{display:grid;gap:18px}
      .wcssd-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
      .wcssd-card h2{margin-top:0}
      .wcssd-columns{display:flex;gap:12px;flex-wrap:wrap}
      .wcssd-columns label{display:grid;gap:4px}
      .wcssd-categories{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px 14px;max-height:190px;overflow:auto;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:10px;margin-top:8px}
      .wcssd-heading,.wcssd-actions,.wcssd-stats,.wcssd-preview-heading,.wcssd-preview-filters,.wcssd-pagination{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      .wcssd-heading{justify-content:space-between}
      .wcssd-preview-heading{justify-content:space-between;margin-top:18px}
      .wcssd-preview-heading h3{margin:0}
      .wcssd-preview-filters label{display:flex;gap:6px;align-items:center}
      .wcssd-pagination{justify-content:flex-end;margin-top:10px}
      .wcssd-heading h2{margin-bottom:2px}
      .wcssd-status{border-radius:999px;background:#f0f0f1;padding:5px 10px;font-weight:600}
      .wcssd-actions{margin:14px 0}
      .wcssd-progress{height:12px;background:#f0f0f1;border-radius:999px;overflow:hidden}
      .wcssd-progress div{height:100%;width:0;background:#2271b1;transition:width .25s}
      .wcssd-stats{margin:14px 0}
      .wcssd-stats div{min-width:110px;border:1px solid #dcdcde;border-radius:8px;padding:10px}
      .wcssd-stats strong,.wcssd-stats span{display:block}
      .wcssd-stats strong{font-size:20px}
      .wcssd-stats span{color:#646970}
      .wcssd-table-wrap{overflow:auto;max-height:500px}
      .wcssd-table-wrap table{min-width:980px}
      .wcssd-table-wrap td{vertical-align:top}
      .wcssd-row-invalid td{background:#fcf0f1}
      .wcssd-row-ready td{background:#f0f6fc}
      .wcssd-row-applied td{background:#edfaef}
      #wcssd_log{white-space:pre-wrap;background:#0b1220;color:#d7e0ea;padding:12px;border-radius:8px;max-height:260px;overflow:auto}
      @media(max-width:782px){
        .wcssd-card{padding:12px;max-width:100%;box-sizing:border-box;overflow:hidden}
        .wcssd-card .form-table,.wcssd-card .form-table tbody,.wcssd-card .form-table tr,.wcssd-card .form-table th,.wcssd-card .form-table td{display:block;width:100%;box-sizing:border-box}
        .wcssd-card .form-table th{padding:12px 0 4px}
        .wcssd-card .form-table td{padding:4px 0 12px}
        .wcssd-card input[type="text"],.wcssd-card input[type="number"],.wcssd-card select{max-width:100%}
        .wcssd-columns{display:grid;grid-template-columns:1fr}
        .wcssd-categories{grid-template-columns:1fr}
        .wcssd-stats div{min-width:calc(50% - 28px)}
        .wcssd-actions .button{width:100%}
      }
    </style>

    <script>
    (function(){
      const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
      const adminPostUrl = <?php echo wp_json_encode($admin_post_url); ?>;
      const nonce = <?php echo wp_json_encode($nonce_ajax); ?>;
      const exportNonce = <?php echo wp_json_encode($nonce_export); ?>;
      const profiles = <?php echo wp_json_encode($profiles); ?>;
      let jobId = <?php echo wp_json_encode($resume_job); ?> || '';
      let reportText = '';
      let pollTimer = null;
      let previewPage = 1;
      const byId = id => document.getElementById(id);
      const area = byId('wcssd_job_area');

      function notice(type, message) {
        const target = byId('wcssd_notice_area');
        target.innerHTML = '';
        const box = document.createElement('div');
        box.className = 'notice notice-' + type;
        const p = document.createElement('p');
        p.textContent = message;
        box.appendChild(p);
        target.appendChild(box);
      }

      async function post(action, extra) {
        const params = Object.assign({
          action, _wpnonce: nonce, job_id: jobId,
          preview_status: byId('wcssd_preview_status').value,
          preview_search: byId('wcssd_preview_search').value,
          preview_page: previewPage
        }, extra || {});
        const response = await fetch(ajaxUrl, {
          method: 'POST', credentials: 'same-origin',
          headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
          body: new URLSearchParams(params)
        });
        let data;
        try { data = await response.json(); } catch (error) { throw new Error('Réponse serveur inattendue.'); }
        if (!data || !data.success) throw new Error(data && data.data && data.data.message ? data.data.message : 'Erreur AJAX.');
        return data.data;
      }

      function value(value) { return value === null || typeof value === 'undefined' || value === '' ? '—' : String(value); }

      function renderPreview(rows) {
        const body = byId('wcssd_preview_body');
        body.innerHTML = '';
        (rows || []).forEach(row => {
          const tr = document.createElement('tr');
          tr.className = 'wcssd-row-' + row.status;
          const values = [
            row.line_number || '—', row.operation, row.sku || '—',
            value(row.old_qty) + ' → ' + value(row.target_qty),
            value(row.old_price) + ' → ' + value(row.target_price),
            row.status, row.message || ''
          ];
          values.forEach(item => { const td = document.createElement('td'); td.textContent = item; tr.appendChild(td); });
          body.appendChild(tr);
        });
      }

      function render(payload) {
        const state = payload.state;
        area.style.display = 'block';
        byId('wcssd_job_label').textContent = 'Job ' + jobId;
        byId('wcssd_status').textContent = state.status;
        ['changed','unchanged','missing','invalid','conflicts','updated','errors'].forEach(key => { byId('wcssd_' + key).textContent = state[key] || 0; });
        const pct = state.total ? Math.round((state.processed / state.total) * 100) : 0;
        byId('wcssd_bar').style.width = pct + '%';
        byId('wcssd_start_btn').disabled = !state.can_apply;
        byId('wcssd_rollback_btn').disabled = !state.can_rollback;
        byId('wcssd_delete_btn').disabled = !state.can_delete;
        byId('wcssd_cancel_btn').disabled = ['completed','rolled_back','rollback_partial','cancelled','analysis_failed'].includes(state.status);
        reportText = payload.report || '';
        byId('wcssd_log').textContent = reportText;
        byId('wcssd_export_btn').disabled = !reportText;
        renderPreview(payload.preview);
        const meta = payload.preview_meta || {page:1,pages:1,total:0};
        previewPage = meta.page;
        byId('wcssd_preview_page').textContent = 'Page ' + meta.page + '/' + meta.pages + ' · ' + meta.total + ' ligne(s)';
        byId('wcssd_preview_prev').disabled = meta.page <= 1;
        byId('wcssd_preview_next').disabled = meta.page >= meta.pages;

        if (state.status === 'analysis_failed') notice('error', 'L’analyse du CSV a échoué. Consultez le journal technique puis relancez un import.');
        else if (state.status === 'invalid' && state.conflicts) notice('error', 'Import bloqué : corrigez les SKU dupliqués ou ambigus indiqués dans l’aperçu.');
        else if (state.status === 'invalid') notice('error', 'Import bloqué : corrigez les lignes invalides ou activez leur exclusion avant une nouvelle analyse.');
        else if (state.status === 'preview' && state.dry_run) notice('info', 'Prévisualisation terminée. Le mode sans écriture ne permet pas d’appliquer les changements.');
        else if (state.status === 'preview' && state.invalid && state.skip_invalid) notice('warning', 'Prévisualisation prête : ' + state.invalid + ' ligne(s) invalide(s) seront ignorées après confirmation.');
        else if (state.status === 'preview') notice(
          state.zero_warning ? 'warning' : 'success',
          state.zero_warning
            ? 'Prévisualisation prête. Attention : au moins 50 % des lignes ciblent un stock à zéro.'
            : 'Prévisualisation prête. Vérifiez les changements avant de les appliquer.'
        );
        else if (state.status === 'completed') notice(state.errors ? 'warning' : 'success', 'Import terminé. Les anciennes valeurs restent disponibles pour un rollback.');
        else if (state.status === 'rolled_back') notice('success', 'Rollback terminé : les anciennes valeurs ont été restaurées.');
        else if (state.status === 'rollback_partial') notice('warning', 'Rollback partiel : certaines valeurs ont été préservées à cause de conflits ou d’erreurs.');
        else if (state.status === 'cancelled') notice('warning', 'Job annulé.');
        else notice(
          'info',
          'Traitement durable en cours : ' + state.processed + '/' + state.total + '. Vous pouvez fermer cette page.'
        );

        clearTimeout(pollTimer);
        if (['analyzing','queued','running','cancelling','rolling_back'].includes(state.status)) pollTimer = setTimeout(refresh, 1500);
      }

      async function refresh() {
        if (!jobId) return;
        try { render(await post('wcssd_job_status')); } catch (error) { notice('error', error.message); }
      }

      byId('wcssd_start_btn').addEventListener('click', async function(){
        if (!jobId) return;
        const warning = byId('wcssd_notice_area').textContent.indexOf('50 %') !== -1;
        const skipped = Number(byId('wcssd_invalid').textContent || 0);
        let question = warning ? 'Une grande partie du catalogue passera à zéro. Confirmer l’import ?' : 'Appliquer exactement les changements prévisualisés ?';
        if (skipped) question += ' ' + skipped + ' ligne(s) invalide(s) seront ignorées.';
        if (!window.confirm(question)) return;
        try { render(await post('wcssd_start_job')); } catch (error) { notice('error', error.message); }
      });
      byId('wcssd_cancel_btn').addEventListener('click', async function(){
        if (!jobId || !window.confirm('Annuler ce job ? Les changements déjà appliqués resteront disponibles pour rollback.')) return;
        try { render(await post('wcssd_cancel_job')); } catch (error) { notice('error', error.message); }
      });
      byId('wcssd_rollback_btn').addEventListener('click', async function(){
        if (!jobId || !window.confirm('Restaurer les valeurs enregistrées avant cet import ?')) return;
        try { render(await post('wcssd_rollback_job')); } catch (error) { notice('error', error.message); }
      });
      byId('wcssd_export_btn').addEventListener('click', function(){
        if (!jobId) return;
        const url = new URL(adminPostUrl);
        url.search = new URLSearchParams({action:'wcssd_export_job',job_id:jobId,_wpnonce:exportNonce}).toString();
        window.location.href = url.toString();
      });
      byId('wcssd_delete_btn').addEventListener('click', async function(){
        if (!jobId || !window.confirm('Supprimer définitivement ce job, son rapport et ses snapshots ?')) return;
        try {
          await post('wcssd_delete_job');
          window.location.reload();
        } catch (error) { notice('error', error.message); }
      });
      byId('wcssd_preview_filter_btn').addEventListener('click', function(){ previewPage = 1; refresh(); });
      byId('wcssd_preview_search').addEventListener('keydown', function(event){
        if (event.key === 'Enter') { event.preventDefault(); previewPage = 1; refresh(); }
      });
      byId('wcssd_preview_prev').addEventListener('click', function(){ if (previewPage > 1) { previewPage--; refresh(); } });
      byId('wcssd_preview_next').addEventListener('click', function(){ previewPage++; refresh(); });

      const prezeroEnable = byId('wcssd_prezero_enable');
      const prezeroCats = Array.from(document.querySelectorAll('input[name="wcssd_prezero_cats[]"]'));
      function prezeroState(){ prezeroCats.forEach(input => { input.disabled = !prezeroEnable.checked; }); }
      prezeroEnable.addEventListener('change', prezeroState); prezeroState();

      byId('wcssd_profile_select').addEventListener('change', function(event){
        const profile = profiles[event.target.value]; if (!profile) return;
        byId('wcssd_profile_name').value = profile.name || '';
        byId('wcssd_delimiter').value = profile.delimiter || 'auto';
        byId('wcssd_col_sku').value = profile.columns && profile.columns.sku ? profile.columns.sku : 'Sku';
        byId('wcssd_col_available').value = profile.columns && profile.columns.available ? profile.columns.available : 'Available';
        byId('wcssd_col_price').value = profile.columns && profile.columns.price ? profile.columns.price : 'Price';
        byId('wcssd_price_adjust').value = typeof profile.price_adjust_amount !== 'undefined' ? profile.price_adjust_amount : 0;
        byId('wcssd_skip_invalid').checked = !!profile.skip_invalid;
        prezeroEnable.checked = !!profile.prezero_enable;
        const selected = (profile.prezero_cats || []).map(String);
        prezeroCats.forEach(input => { input.checked = selected.includes(String(input.value)); });
        prezeroState();
      });

      const pageUrl = new URL(window.location.href);
      const urlJob = pageUrl.searchParams.get('wcssd_job');
      if (urlJob) {
        jobId = urlJob; pageUrl.searchParams.delete('wcssd_job'); window.history.replaceState({}, '', pageUrl.toString());
      }
      if (jobId) refresh();
    })();
    </script>
    <?php
  }
}
