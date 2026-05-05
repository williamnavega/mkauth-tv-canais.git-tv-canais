<?php
if (PHP_SAPI === 'cli' && !defined('ADMIN2URL')) {
    define('ADMIN2URL', 'http://localhost/admin/');
}
include('addons.class.php');

if (!isset($Manifest)) {
    $Manifest = json_decode((string) @file_get_contents(__DIR__ . '/manifest.json'));
}
if (!$Manifest) {
    $Manifest = (object) array('name' => 'TV Canais', 'version' => '1.0.8');
}
require_once __DIR__ . '/lib.php';
$LicenseStatus = tv_public_license_status(tv_license_status());
?>
<!DOCTYPE html>
<html lang="pt-BR" class="has-navbar-fixed-top">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta charset="utf-8">
<title>MK-AUTH :: <?php echo htmlspecialchars($Manifest->name, ENT_QUOTES, 'UTF-8'); ?></title>
<link href="../../estilos/mk-auth.css" rel="stylesheet" type="text/css" />
<link href="../../estilos/font-awesome.css" rel="stylesheet" type="text/css" />
<link href="../../estilos/bi-icons.css" rel="stylesheet" type="text/css" />
<link href="assets/tv-xui.css?v=<?php echo htmlspecialchars($Manifest->version, ENT_QUOTES, 'UTF-8'); ?>" rel="stylesheet" type="text/css" />
<script src="../../scripts/jquery.js"></script>
<script src="../../scripts/mk-auth.js"></script>
</head>
<body>
<?php include('../../topo.php'); ?>

<?php if (empty($LicenseStatus['valid'])): ?>
<main class="tv-shell">
  <aside class="tv-sidebar">
    <div class="tv-brand">
      <span class="tv-brand-mark"><i class="bi-shield-lock"></i></span>
      <span><strong>TV Canais</strong><small>Licenciamento</small></span>
    </div>
    <nav class="tv-nav">
      <a class="is-active" href="#licenca"><i class="bi-shield-exclamation"></i><span>Licenca</span></a>
    </nav>
  </aside>

  <section class="tv-main" id="licenca">
    <header class="tv-topbar">
      <div>
        <p class="tv-kicker">Addon MK-Auth</p>
        <h1>Licenca necessaria</h1>
      </div>
      <span class="tv-license-pill is-error"><i class="bi-x-circle"></i><span>Bloqueado</span></span>
    </header>

    <section class="tv-status-line">
      <span id="tv-health-dot" class="tv-dot is-error"></span>
      <span id="tv-health-text"><?php echo htmlspecialchars($LicenseStatus['message'], ENT_QUOTES, 'UTF-8'); ?></span>
      <span id="tv-updated-at"></span>
    </section>

    <section class="tv-panel tv-panel-full tv-license-block">
      <div class="tv-panel-head"><div><p class="tv-kicker">Validacao offline</p><h2><?php echo htmlspecialchars($LicenseStatus['message'], ENT_QUOTES, 'UTF-8'); ?></h2></div></div>
      <p>Gere uma licenca no painel central e instale o arquivo JSON neste MK-Auth. A chave publica tambem precisa estar no caminho configurado.</p>
      <div class="tv-license-grid">
        <div><strong>Machine ID</strong><code><?php echo htmlspecialchars($LicenseStatus['machine_id'], ENT_QUOTES, 'UTF-8'); ?></code></div>
        <div><strong>Arquivo de licenca</strong><code><?php echo htmlspecialchars($LicenseStatus['license_file'], ENT_QUOTES, 'UTF-8'); ?></code></div>
        <div><strong>Chave publica</strong><code><?php echo htmlspecialchars($LicenseStatus['public_key_file'], ENT_QUOTES, 'UTF-8'); ?></code></div>
      </div>
    </section>

    <section class="tv-panel tv-panel-full">
      <div class="tv-panel-head"><div><p class="tv-kicker">Ativacao</p><h2>Colar licenca do cliente</h2></div></div>
      <form id="license-form" class="tv-form">
        <label>JSON da licenca<textarea id="license-json" rows="12" placeholder='{"product":"tv-canais","customer":"..."}'></textarea></label>
        <button type="submit"><i class="bi-shield-check"></i><span>Ativar licenca</span></button>
      </form>
    </section>
  </section>
</main>

<?php include('../../baixo.php'); ?>
<script src="assets/tv-xui.js?v=<?php echo htmlspecialchars($Manifest->version, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="../../menu.js.hhvm"></script>
</body>
</html>
<?php exit; ?>
<?php endif; ?>

<main class="tv-shell">
  <aside class="tv-sidebar">
    <div class="tv-brand">
      <span class="tv-brand-mark"><i class="bi-tv"></i></span>
      <span><strong>TV Canais</strong><small>Planos adicionais</small></span>
    </div>
    <nav class="tv-nav">
      <a class="is-active" href="#painel"><i class="bi-grid-1x2-fill"></i><span>Painel</span></a>
      <a href="#vincular"><i class="bi-person-plus"></i><span>Vincular cliente</span></a>
      <a href="#planos"><i class="bi-collection-play"></i><span>Planos TV</span></a>
      <a href="#clientes"><i class="bi-people"></i><span>Clientes TV</span></a>
      <a href="#config"><i class="bi-sliders"></i><span>Configuracao</span></a>
      <a href="#licenca"><i class="bi-shield-check"></i><span>Licenca</span></a>
      <a href="#logs"><i class="bi-list-check"></i><span>Logs</span></a>
    </nav>
  </aside>

  <section class="tv-main" id="painel">
    <header class="tv-topbar">
      <div>
        <p class="tv-kicker">Addon MK-Auth</p>
        <h1><?php echo htmlspecialchars($Manifest->name, ENT_QUOTES, 'UTF-8'); ?></h1>
      </div>
      <div class="tv-actions">
        <span class="tv-license-pill is-ok" title="Licenca valida"><i class="bi-shield-check"></i><span><?php echo htmlspecialchars($LicenseStatus['customer'] ?: 'Licenciado', ENT_QUOTES, 'UTF-8'); ?> ate <?php echo htmlspecialchars($LicenseStatus['expires_at'], ENT_QUOTES, 'UTF-8'); ?></span></span>
        <button type="button" class="tv-icon-button" id="tv-refresh" title="Atualizar"><i class="bi-arrow-clockwise"></i></button>
        <button type="button" class="tv-icon-button" id="tv-sync-all" title="Sincronizar todos"><i class="bi-cloud-arrow-up"></i></button>
        <span class="tv-user"><i class="bi-person-circle"></i><span>Admin</span></span>
      </div>
    </header>

    <section class="tv-status-line">
      <span id="tv-health-dot" class="tv-dot"></span>
      <span id="tv-health-text">Carregando...</span>
      <span id="tv-updated-at"></span>
    </section>

    <section class="tv-kpis">
      <article class="tv-kpi"><span class="tv-kpi-icon is-blue"><i class="bi-collection-play"></i></span><div><small>Planos TV</small><strong id="kpi-plans">--</strong><em>cadastrados</em></div></article>
      <article class="tv-kpi"><span class="tv-kpi-icon is-green"><i class="bi-people-fill"></i></span><div><small>Clientes com TV</small><strong id="kpi-linked">--</strong><em>vinculados</em></div></article>
      <article class="tv-kpi"><span class="tv-kpi-icon is-emerald"><i class="bi-play-circle-fill"></i></span><div><small>Ativos na TV</small><strong id="kpi-active">--</strong><em>sincronizados</em></div></article>
      <article class="tv-kpi"><span class="tv-kpi-icon is-red"><i class="bi-lock-fill"></i></span><div><small>Bloqueados</small><strong id="kpi-blocked">--</strong><em>MK-Auth/TV</em></div></article>
    </section>

    <section class="tv-grid" id="vincular">
      <article class="tv-panel">
        <div class="tv-panel-head"><div><p class="tv-kicker">Atendimento</p><h2>Escolher cliente e plano de TV</h2></div></div>
        <form id="assign-form" class="tv-form">
          <label>Buscar cliente<input type="search" id="client-search" placeholder="Nome, login, CPF/CNPJ ou ID"></label>
          <div id="client-results" class="tv-results"></div>
          <input type="hidden" id="selected-client-id">
          <label>Plano de TV<select id="assign-plan"></select></label>
          <label class="tv-check"><input type="checkbox" id="assign-sync" checked><span>Sincronizar ao vincular</span></label>
          <button type="submit"><i class="bi-person-plus"></i><span>Vincular plano de TV</span></button>
        </form>
      </article>

      <article class="tv-panel" id="planos">
        <div class="tv-panel-head"><div><p class="tv-kicker">Catalogo</p><h2>Plano adicional de TV</h2></div></div>
        <form id="plan-form" class="tv-form">
          <input type="hidden" id="plan-id">
          <label>Nome<input type="text" id="plan-name" placeholder="TV Basico, TV Plus"></label>
          <label>Valor mensal<input type="text" id="plan-value" placeholder="0,00"></label>
          <label>Package do painel<input type="number" id="plan-package" min="0" placeholder="ID do package"></label>
          <label>Bouquets<input type="text" id="plan-bouquets" placeholder="12,13,14"></label>
          <label>Telas/conexoes<input type="number" id="plan-connections" min="1" value="1"></label>
          <label>Outputs<input type="text" id="plan-outputs" value="1,2,3"></label>
          <label>Descricao<input type="text" id="plan-description" placeholder="Opcional"></label>
          <label class="tv-check"><input type="checkbox" id="plan-active" checked><span>Plano ativo</span></label>
          <button type="submit"><i class="bi-check2-circle"></i><span>Salvar plano</span></button>
        </form>
      </article>
    </section>

    <section class="tv-panel tv-panel-full" id="clientes">
      <div class="tv-panel-head">
        <div><p class="tv-kicker">Base TV</p><h2>Clientes vinculados</h2></div>
      </div>
      <div class="tv-table-wrap"><table class="tv-table" id="linked-table"></table></div>
    </section>

    <section class="tv-grid" id="config">
      <article class="tv-panel">
        <div class="tv-panel-head"><div><p class="tv-kicker">Integracao de canais</p><h2>Configuracao segura</h2></div></div>
        <form id="settings-form" class="tv-form">
          <label>Base URL<input type="text" id="set-xui-base-url" placeholder="https://painel.exemplo.com"></label>
          <label>Access code<input type="text" id="set-xui-access-code" placeholder="codigo da API"></label>
          <label>API key<input type="password" id="set-xui-api-key" placeholder="preencha para alterar"></label>
          <label>Package padrao<input type="number" id="set-package" min="1" value="1"></label>
          <label>Bouquets padrao<input type="text" id="set-bouquets" placeholder="12,13"></label>
          <label>Outputs padrao<input type="text" id="set-outputs" value="1,2,3"></label>
          <label>Expirar em dias<input type="number" id="set-expire-days" min="0" value="0"></label>
          <label>Servidor fixo<input type="number" id="set-force-server" min="0" value="0"></label>
          <label>Prefixo usuario<input type="text" id="set-prefix" placeholder="tv_"></label>
          <label>Senha
            <select id="set-password-mode">
              <option value="generated">Gerada pelo addon</option>
              <option value="mkauth">Usar senha do MK-Auth</option>
            </select>
          </label>
          <label class="tv-check"><input type="checkbox" id="set-block-mkauth" checked><span>Bloquear TV quando cliente bloquear no MK-Auth</span></label>
          <label class="tv-check"><input type="checkbox" id="set-block-overdue" checked><span>Bloquear TV quando houver titulo vencido</span></label>
          <button type="submit"><i class="bi-shield-check"></i><span>Salvar configuracao</span></button>
          <button type="button" id="test-xui"><i class="bi-wifi"></i><span>Testar integracao</span></button>
        </form>
      </article>

      <article class="tv-panel" id="logs">
        <div class="tv-panel-head"><div><p class="tv-kicker">Operacao</p><h2>Ultimos logs</h2></div></div>
        <div class="tv-table-wrap"><table class="tv-table tv-table-compact" id="logs-table"></table></div>
      </article>
    </section>

    <section class="tv-panel tv-panel-full" id="licenca">
      <div class="tv-panel-head">
        <div><p class="tv-kicker">Licenciamento</p><h2>Licenca do addon</h2></div>
        <span class="tv-license-pill is-ok"><i class="bi-shield-check"></i><span>Ativa</span></span>
      </div>
      <div class="tv-license-grid tv-license-grid-split">
        <div><strong>Status</strong><code id="license-status-text"><?php echo htmlspecialchars($LicenseStatus['message'], ENT_QUOTES, 'UTF-8'); ?></code></div>
        <div><strong>Cliente</strong><code id="license-customer"><?php echo htmlspecialchars($LicenseStatus['customer'], ENT_QUOTES, 'UTF-8'); ?></code></div>
        <div><strong>Validade</strong><code id="license-expires"><?php echo htmlspecialchars($LicenseStatus['expires_at'], ENT_QUOTES, 'UTF-8'); ?></code></div>
        <div><strong>Machine ID</strong><code id="license-machine-id"><?php echo htmlspecialchars($LicenseStatus['machine_id'], ENT_QUOTES, 'UTF-8'); ?></code></div>
      </div>
      <form id="license-form" class="tv-form tv-license-update-form">
        <label>Nova licenca JSON<textarea id="license-json" rows="10" placeholder="Cole aqui o JSON para renovar ou trocar a licenca"></textarea></label>
        <button type="submit"><i class="bi-shield-check"></i><span>Atualizar licenca</span></button>
      </form>
    </section>
  </section>
</main>

<?php include('../../baixo.php'); ?>
<script src="assets/tv-xui.js?v=<?php echo htmlspecialchars($Manifest->version, ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="../../menu.js.hhvm"></script>
</body>
</html>
