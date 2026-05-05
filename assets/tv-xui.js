(function () {
  'use strict';

  let state = { plans: [], linked: [], logs: [], settings: {}, selectedClient: null };

  function byId(id) {
    return document.getElementById(id);
  }

  function setText(id, value) {
    const el = byId(id);
    if (el) el.textContent = value;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function brl(value) {
    return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  function setStatus(ok, text) {
    const dot = byId('tv-health-dot');
    if (dot) dot.className = 'tv-dot ' + (ok ? 'is-ok' : 'is-error');
    setText('tv-health-text', text);
  }

  function post(action, data) {
    const form = new URLSearchParams(data || {});
    form.set('action', action);
    return fetch('api.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form.toString()
    })
      .then((res) => res.json())
      .then((payload) => {
        if (!payload.ok) throw new Error((payload.data && payload.data.message) || 'Falha na operacao.');
        return payload.data || {};
      });
  }

  function get(action, params) {
    const query = new URLSearchParams(params || {});
    query.set('action', action);
    return fetch('api.php?' + query.toString(), {
      credentials: 'same-origin',
      cache: 'no-store'
    })
      .then((res) => res.json())
      .then((payload) => {
        if (!payload.ok) throw new Error((payload.data && payload.data.message) || 'Falha na consulta.');
        return payload.data || {};
      });
  }

  function renderKpis(kpis) {
    setText('kpi-plans', kpis.plans || 0);
    setText('kpi-linked', kpis.linked || 0);
    setText('kpi-active', kpis.active || 0);
    setText('kpi-blocked', kpis.blocked || 0);
  }

  function renderPlans(plans) {
    const select = byId('assign-plan');
    if (!select) return;
    select.innerHTML = '';
    plans.filter((plan) => Number(plan.active) === 1).forEach((plan) => {
      const opt = document.createElement('option');
      opt.value = plan.id;
      opt.textContent = plan.name + ' - ' + brl(plan.monthly_value) + ' - ' + plan.max_connections + ' tela(s)';
      select.appendChild(opt);
    });
  }

  function pill(status, text) {
    const clean = String(status || 'pending').replace(/[^a-z0-9_]+/g, '_');
    return '<span class="tv-pill is-' + clean + '">' + escapeHtml(text || status || 'pending') + '</span>';
  }

  function table(id, headers, rows, mapper) {
    const target = byId(id);
    if (!target) return;
    if (!rows || !rows.length) {
      target.innerHTML = '<tbody><tr><td><div class="tv-empty">Sem dados para exibir.</div></td></tr></tbody>';
      return;
    }
    let html = '<thead><tr>' + headers.map((h) => '<th>' + escapeHtml(h) + '</th>').join('') + '</tr></thead><tbody>';
    rows.forEach((row) => {
      html += '<tr>' + mapper(row).join('') + '</tr>';
    });
    html += '</tbody>';
    target.innerHTML = html;
  }

  function td(value, className) {
    return '<td' + (className ? ' class="' + className + '"' : '') + '>' + escapeHtml(value == null || value === '' ? '--' : value) + '</td>';
  }

  function linkInput(selectorPrefix, id) {
    return document.querySelector('[' + selectorPrefix + '="' + String(id).replace(/"/g, '\\"') + '"]');
  }

  function playlistCell(row) {
    const url = row.playlist_url || '';
    if (!url) return '<td class="is-muted">--</td>';
    return '<td><div class="tv-playlist-actions">' +
      '<input class="tv-playlist-input" readonly value="' + escapeHtml(url) + '">' +
      '<button type="button" title="Copiar lista" data-copy-playlist="' + escapeHtml(url) + '"><i class="bi-clipboard"></i></button>' +
      '<a class="tv-table-button" title="Abrir lista" target="_blank" rel="noopener" href="' + escapeHtml(url) + '"><i class="bi-box-arrow-up-right"></i></a>' +
      '</div></td>';
  }

  function renderLinked(rows) {
    table('linked-table', ['Cliente', 'Login', 'Plano TV', 'Status XUI', 'Telas', 'Outputs', 'Lista', 'Linha', 'Ultima sync', 'Acoes'], rows, (row) => [
      td(row.client_name),
      td(row.mk_login, 'is-muted'),
      td(row.plan_name),
      '<td>' + pill(row.status, row.status) + '</td>',
      '<td><input class="tv-mini-input" type="number" min="0" value="' + escapeHtml(row.max_connections_override && Number(row.max_connections_override) > 0 ? row.max_connections_override : row.effective_connections || '') + '" data-link-connections="' + escapeHtml(row.id) + '"></td>',
      '<td><input class="tv-outputs-input" type="text" value="' + escapeHtml(row.access_outputs_override || row.effective_outputs || '') + '" data-link-outputs="' + escapeHtml(row.id) + '"></td>',
      playlistCell(row),
      td(row.xui_line_id || '--', 'is-muted'),
      td(row.last_sync_at || '--', 'is-muted'),
      '<td><div class="tv-row-actions"><button type="button" title="Salvar e sincronizar" data-save-link="' + escapeHtml(row.id) + '"><i class="bi-check2-circle"></i></button><button type="button" title="Sincronizar" data-sync-link="' + escapeHtml(row.id) + '"><i class="bi-arrow-repeat"></i></button></div></td>'
    ]);
  }

  function renderLogs(rows) {
    table('logs-table', ['Quando', 'Acao', 'Resultado', 'Mensagem'], rows, (row) => [
      td(row.created_at, 'is-muted'),
      td(row.action),
      '<td>' + pill(row.result, row.result) + '</td>',
      td(row.message)
    ]);
  }

  function fillSettings(settings) {
    state.settings = settings || {};
    const pairs = {
      'set-xui-base-url': 'xui_base_url',
      'set-xui-access-code': 'xui_access_code',
      'set-package': 'xui_default_package_id',
      'set-bouquets': 'xui_default_bouquets',
      'set-outputs': 'xui_default_outputs',
      'set-expire-days': 'xui_default_expire_days',
      'set-force-server': 'xui_force_server_id',
      'set-prefix': 'username_prefix',
      'set-password-mode': 'password_mode'
    };
    Object.keys(pairs).forEach((id) => {
      const el = byId(id);
      if (el && settings[pairs[id]] != null) el.value = settings[pairs[id]];
    });
    if (byId('set-block-mkauth')) byId('set-block-mkauth').checked = settings.block_on_mkauth_blocked === '1';
    if (byId('set-block-overdue')) byId('set-block-overdue').checked = settings.block_on_overdue === '1';
    const apiKey = byId('set-xui-api-key');
    if (apiKey) apiKey.placeholder = settings.xui_api_key_masked ? 'atual: ' + settings.xui_api_key_masked : 'preencha para configurar';
  }

  function render(data) {
    state.plans = data.plans || [];
    state.linked = data.linked || [];
    state.logs = data.logs || [];
    renderKpis(data.kpis || {});
    renderPlans(state.plans);
    renderLinked(state.linked);
    renderLogs(state.logs);
    fillSettings(data.settings || {});
    fillLicense(data.license || {});
    setStatus(Boolean(data.configured), data.configured ? 'Integracao configurada e painel carregado.' : 'Painel carregado. Configure as credenciais da integracao.');
    setText('tv-updated-at', 'Atualizado em ' + new Date().toLocaleString('pt-BR'));
  }

  function fillLicense(license) {
    setText('license-status-text', license.message || '--');
    setText('license-customer', license.customer || '--');
    setText('license-expires', license.expires_at || '--');
    setText('license-machine-id', license.machine_id || '--');
  }

  function load() {
    setStatus(false, 'Carregando...');
    return get('dashboard')
      .then(render)
      .catch((error) => setStatus(false, error.message));
  }

  function searchClients(q) {
    return get('search_clients', { q: q || '', limit: 25 })
      .then((data) => renderClientResults(data.clients || []))
      .catch((error) => setStatus(false, error.message));
  }

  function renderClientResults(clients) {
    const target = byId('client-results');
    if (!target) return;
    target.innerHTML = '';
    if (!clients.length) {
      target.innerHTML = '<div class="tv-empty">Nenhum cliente encontrado.</div>';
      return;
    }
    clients.forEach((client) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'tv-result' + (state.selectedClient && Number(state.selectedClient.id) === Number(client.id) ? ' is-selected' : '');
      button.innerHTML = '<strong></strong><small></small>';
      button.querySelector('strong').textContent = client.nome || '--';
      button.querySelector('small').textContent = 'ID ' + client.id + ' | login ' + (client.login || '--') + ' | plano ' + (client.plano || '--');
      button.addEventListener('click', function () {
        state.selectedClient = client;
        if (byId('selected-client-id')) byId('selected-client-id').value = client.id;
        renderClientResults(clients);
      });
      target.appendChild(button);
    });
  }

  function bindForms() {
    const licenseForm = byId('license-form');
    const activationOnly = licenseForm && !byId('assign-form');
    if (licenseForm) {
      licenseForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const field = byId('license-json');
        post('install_license', { license_json: field ? field.value : '' })
          .then((data) => {
            setStatus(true, data.message || 'Licenca ativada.');
            window.setTimeout(function () { window.location.reload(); }, 700);
          })
          .catch((error) => setStatus(false, error.message));
      });
      if (activationOnly) return true;
    }

    const search = byId('client-search');
    let searchTimer = null;
    if (search) {
      search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => searchClients(search.value), 250);
      });
      search.addEventListener('focus', function () {
        if (!byId('client-results').children.length) searchClients(search.value);
      });
    }

    const assign = byId('assign-form');
    if (assign) {
      assign.addEventListener('submit', function (event) {
        event.preventDefault();
        const clientId = byId('selected-client-id') ? byId('selected-client-id').value : '';
        const planId = byId('assign-plan') ? byId('assign-plan').value : '';
        if (!clientId) {
          setStatus(false, 'Selecione um cliente.');
          return;
        }
        post('assign_client', {
          client_id: clientId,
          plan_id: planId,
          sync_enabled: byId('assign-sync') && byId('assign-sync').checked ? '1' : '0'
        })
          .then((data) => {
            setStatus(true, data.message || 'Plano vinculado.');
            load();
          })
          .catch((error) => setStatus(false, error.message));
      });
    }

    const plan = byId('plan-form');
    if (plan) {
      plan.addEventListener('submit', function (event) {
        event.preventDefault();
        post('save_plan', {
          id: byId('plan-id').value,
          name: byId('plan-name').value,
          monthly_value: byId('plan-value').value,
          xui_package_id: byId('plan-package').value,
          xui_bouquets: byId('plan-bouquets').value,
          max_connections: byId('plan-connections').value,
          access_outputs: byId('plan-outputs').value,
          description: byId('plan-description').value,
          active: byId('plan-active').checked ? '1' : '0'
        })
          .then(() => {
            plan.reset();
            byId('plan-active').checked = true;
            byId('plan-connections').value = '1';
            byId('plan-outputs').value = '1,2,3';
            setStatus(true, 'Plano salvo.');
            load();
          })
          .catch((error) => setStatus(false, error.message));
      });
    }

    const settings = byId('settings-form');
    if (settings) {
      settings.addEventListener('submit', function (event) {
        event.preventDefault();
        post('save_settings', {
          xui_base_url: byId('set-xui-base-url').value,
          xui_access_code: byId('set-xui-access-code').value,
          xui_api_key: byId('set-xui-api-key').value,
          xui_default_package_id: byId('set-package').value,
          xui_default_bouquets: byId('set-bouquets').value,
          xui_default_outputs: byId('set-outputs').value,
          xui_default_expire_days: byId('set-expire-days').value,
          xui_force_server_id: byId('set-force-server').value,
          username_prefix: byId('set-prefix').value,
          password_mode: byId('set-password-mode').value,
          block_on_mkauth_blocked: byId('set-block-mkauth').checked ? '1' : '0',
          block_on_overdue: byId('set-block-overdue').checked ? '1' : '0'
        })
          .then(() => {
            byId('set-xui-api-key').value = '';
            setStatus(true, 'Configuracao salva.');
            load();
          })
          .catch((error) => setStatus(false, error.message));
      });
    }
    return false;
  }

  document.addEventListener('click', function (event) {
    const copyButton = event.target.closest('[data-copy-playlist]');
    if (copyButton) {
      const url = copyButton.getAttribute('data-copy-playlist') || '';
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(() => setStatus(true, 'Lista copiada.')).catch(() => setStatus(false, 'Nao foi possivel copiar a lista.'));
      } else {
        setStatus(false, 'Copie a lista pelo campo de texto.');
      }
      return;
    }

    const saveButton = event.target.closest('[data-save-link]');
    if (saveButton) {
      const id = saveButton.getAttribute('data-save-link');
      const connections = linkInput('data-link-connections', id);
      const outputs = linkInput('data-link-outputs', id);
      post('update_client_tv', {
        link_id: id,
        max_connections_override: connections ? connections.value : '0',
        access_outputs_override: outputs ? outputs.value : '',
        sync_enabled: '1',
        sync_now: '1'
      })
        .then((data) => {
          const sync = data.sync || {};
          setStatus(true, sync.message || data.message || 'Cliente TV atualizado.');
          load();
        })
        .catch((error) => setStatus(false, error.message));
      return;
    }

    const button = event.target.closest('[data-sync-link]');
    if (!button) return;
    post('sync_client', { link_id: button.getAttribute('data-sync-link') })
      .then((data) => {
        setStatus(true, data.message || 'Cliente sincronizado.');
        load();
      })
      .catch((error) => setStatus(false, error.message));
  });

  document.addEventListener('DOMContentLoaded', function () {
    if (bindForms()) return;
    if (byId('tv-refresh')) byId('tv-refresh').addEventListener('click', load);
    if (byId('tv-sync-all')) {
      byId('tv-sync-all').addEventListener('click', function () {
        post('sync_all', {})
          .then((data) => {
            setStatus(true, 'Sincronizados: ' + data.processed + ' | falhas: ' + data.failed);
            load();
          })
          .catch((error) => setStatus(false, error.message));
      });
    }
    if (byId('test-xui')) {
      byId('test-xui').addEventListener('click', function () {
        post('test_xui', {})
          .then((data) => setStatus(true, data.message + ' Packages: ' + data.packages))
          .catch((error) => setStatus(false, error.message));
      });
    }
    load();
  });
})();
