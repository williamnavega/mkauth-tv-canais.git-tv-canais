<?php

function tv_config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $file = '/etc/mkauth-xui-addon/config.php';
    if (!is_readable($file)) {
        $config = array();
        return $config;
    }
    $loaded = include $file;
    $config = is_array($loaded) ? $loaded : array();
    return $config;
}

function tv_license_paths()
{
    $config = tv_config();
    $license = isset($config['license']) && is_array($config['license']) ? $config['license'] : array();
    return array(
        'file' => isset($license['file']) && $license['file'] !== '' ? (string) $license['file'] : '/var/lib/mkauth-xui-addon/license.json',
        'public_key' => isset($license['public_key']) && $license['public_key'] !== '' ? (string) $license['public_key'] : '/etc/mkauth-xui-addon/license-public.pem',
        'product' => isset($license['product']) && $license['product'] !== '' ? (string) $license['product'] : 'tv-canais',
    );
}

function tv_array_is_list(array $value)
{
    $expected = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

function tv_canonical_json($value)
{
    if (is_array($value)) {
        if (tv_array_is_list($value)) {
            $items = array();
            foreach ($value as $item) {
                $items[] = tv_canonical_json($item);
            }
            return '[' . implode(',', $items) . ']';
        }
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $items = array();
        foreach ($keys as $key) {
            $items[] = json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ':' . tv_canonical_json($value[$key]);
        }
        return '{' . implode(',', $items) . '}';
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function tv_default_source_ip()
{
    $route = trim((string) @shell_exec('ip route get 1.1.1.1 2>/dev/null'));
    if ($route !== '' && preg_match('/\bsrc\s+([0-9A-Fa-f:\.]+)/', $route, $match)) {
        return trim($match[1]);
    }
    return '';
}

function tv_license_machine_id()
{
    $hostname = trim((string) gethostname());
    $machine = trim((string) @file_get_contents('/etc/machine-id'));
    $sourceIp = tv_default_source_ip();
    return hash('sha256', $hostname . '|' . $machine . '|' . $sourceIp);
}

function tv_public_license_status(array $status)
{
    unset($status['signature']);
    unset($status['license_raw']);
    return $status;
}

function tv_license_status($licenseFile = '')
{
    $paths = tv_license_paths();
    if ($licenseFile !== '') {
        $paths['file'] = $licenseFile;
    }
    $machineId = tv_license_machine_id();
    $status = array(
        'valid' => false,
        'message' => 'Licenca nao instalada.',
        'machine_id' => $machineId,
        'license_file' => $paths['file'],
        'public_key_file' => $paths['public_key'],
        'customer' => '',
        'expires_at' => '',
        'product' => $paths['product'],
        'modules' => array(),
        'limits' => array(),
    );

    if (!function_exists('openssl_verify')) {
        $status['message'] = 'Extensao OpenSSL indisponivel no PHP.';
        return $status;
    }
    if (!is_readable($paths['public_key'])) {
        $status['message'] = 'Chave publica da licenca nao encontrada.';
        return $status;
    }
    if (!is_readable($paths['file'])) {
        $status['message'] = 'Arquivo de licenca nao encontrado.';
        return $status;
    }

    $license = json_decode((string) @file_get_contents($paths['file']), true);
    if (!is_array($license)) {
        $status['message'] = 'Arquivo de licenca invalido.';
        return $status;
    }

    $status['customer'] = isset($license['customer']) ? (string) $license['customer'] : '';
    $status['expires_at'] = isset($license['expires_at']) ? (string) $license['expires_at'] : '';
    $status['product'] = isset($license['product']) ? (string) $license['product'] : '';
    $status['modules'] = isset($license['modules']) && is_array($license['modules']) ? $license['modules'] : array();
    $status['limits'] = isset($license['limits']) && is_array($license['limits']) ? $license['limits'] : array();

    $signature = isset($license['signature']) ? (string) $license['signature'] : '';
    $signatureBin = base64_decode($signature, true);
    if ($signature === '' || $signatureBin === false) {
        $status['message'] = 'Assinatura da licenca ausente ou invalida.';
        return $status;
    }

    $signed = $license;
    unset($signed['signature']);
    $publicKey = (string) @file_get_contents($paths['public_key']);
    $verified = @openssl_verify(tv_canonical_json($signed), $signatureBin, $publicKey, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        $status['message'] = 'Assinatura da licenca nao confere.';
        return $status;
    }

    if ((string) $status['product'] !== (string) $paths['product']) {
        $status['message'] = 'Licenca emitida para outro produto.';
        return $status;
    }
    if (!isset($license['machine_id']) || (string) $license['machine_id'] !== $machineId) {
        $status['message'] = 'Licenca emitida para outro servidor.';
        return $status;
    }
    if ($status['expires_at'] === '' || strtotime($status['expires_at'] . ' 23:59:59') < time()) {
        $status['message'] = 'Licenca expirada.';
        return $status;
    }

    $status['valid'] = true;
    $status['message'] = 'Licenca valida.';
    return $status;
}

function tv_install_license($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        throw new RuntimeException('Cole o JSON da licenca antes de ativar.');
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('JSON da licenca invalido.');
    }

    $paths = tv_license_paths();
    $dir = dirname($paths['file']);
    if (!is_dir($dir) && !@mkdir($dir, 0770, true)) {
        throw new RuntimeException('Nao foi possivel criar o diretorio da licenca.');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Diretorio da licenca sem permissao de escrita para o painel.');
    }

    $normalized = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = $paths['file'] . '.tmp.' . getmypid() . '.' . mt_rand(1000, 9999);
    if (@file_put_contents($tmp, $normalized . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Nao foi possivel gravar a licenca.');
    }
    @chgrp($tmp, 'www-data');
    @chmod($tmp, 0660);

    $status = tv_license_status($tmp);
    if (empty($status['valid'])) {
        @unlink($tmp);
        throw new RuntimeException($status['message']);
    }

    if (!@rename($tmp, $paths['file'])) {
        @unlink($tmp);
        throw new RuntimeException('Nao foi possivel ativar a licenca.');
    }
    @chown($paths['file'], 'root');
    @chgrp($paths['file'], 'www-data');
    @chmod($paths['file'], 0660);

    return tv_public_license_status(tv_license_status());
}

function tv_require_license($json = false)
{
    $license = tv_license_status();
    if ($license['valid']) {
        return $license;
    }
    if ($json) {
        tv_json(false, array('message' => 'Licenca invalida: ' . $license['message'], 'license' => tv_public_license_status($license)), 403);
    }
    throw new RuntimeException('Licenca invalida: ' . $license['message']);
}

function tv_db_config($key)
{
    $config = tv_config();
    return isset($config[$key]) && is_array($config[$key]) ? $config[$key] : array();
}

function tv_connect(array $cfg)
{
    $host = isset($cfg['host']) ? $cfg['host'] : '127.0.0.1';
    $user = isset($cfg['user']) ? $cfg['user'] : '';
    $pass = isset($cfg['password']) ? $cfg['password'] : '';
    $database = isset($cfg['database']) ? $cfg['database'] : '';
    if ($user === '' || $database === '') {
        return null;
    }
    $db = @new mysqli($host, $user, $pass, $database);
    if ($db->connect_errno) {
        return null;
    }
    $db->set_charset('utf8');
    $db->autocommit(true);
    return $db;
}

function tv_app_db()
{
    static $db = null;
    if ($db instanceof mysqli && !$db->connect_errno) {
        return $db;
    }
    $db = tv_connect(tv_db_config('app_db'));
    if ($db) {
        tv_migrate($db);
    }
    return $db;
}

function tv_mk_db()
{
    static $db = null;
    if ($db instanceof mysqli && !$db->connect_errno) {
        return $db;
    }
    $db = tv_connect(tv_db_config('mk_db'));
    return $db;
}

function tv_migrate(mysqli $db)
{
    $db->query("CREATE TABLE IF NOT EXISTS tv_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(255) NOT NULL DEFAULT '',
        monthly_value DECIMAL(12,2) NOT NULL DEFAULT 0,
        xui_package_id INT NOT NULL DEFAULT 0,
        xui_bouquets VARCHAR(255) NOT NULL DEFAULT '',
        max_connections INT NOT NULL DEFAULT 1,
        access_outputs VARCHAR(255) NOT NULL DEFAULT '1,2,3',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_tv_plans_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $db->query("CREATE TABLE IF NOT EXISTS client_tv_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mk_client_id INT NOT NULL,
        mk_login VARCHAR(80) NOT NULL,
        tv_plan_id INT NOT NULL,
        xui_line_id VARCHAR(80) NOT NULL DEFAULT '',
        xui_username VARCHAR(80) NOT NULL DEFAULT '',
        xui_password VARCHAR(80) NOT NULL DEFAULT '',
        sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        last_message VARCHAR(255) NOT NULL DEFAULT '',
        last_sync_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client_tv (mk_client_id),
        KEY idx_client_tv_plan (tv_plan_id),
        KEY idx_client_tv_login (mk_login),
        KEY idx_client_tv_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $db->query("CREATE TABLE IF NOT EXISTS settings (
        name VARCHAR(80) PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    $db->query("CREATE TABLE IF NOT EXISTS sync_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_tv_plan_id INT NULL,
        mk_client_id INT NULL,
        action VARCHAR(40) NOT NULL,
        result VARCHAR(40) NOT NULL,
        message VARCHAR(255) NOT NULL,
        raw_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_logs_created (created_at),
        KEY idx_logs_client (client_tv_plan_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
}

function tv_json($ok, $payload, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode(array(
        'ok' => $ok,
        'generated_at' => date('c'),
        'data' => $payload,
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function tv_post($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : $default;
}

function tv_get($key, $default = '')
{
    return isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
}

function tv_bind(mysqli_stmt $stmt, $types, array &$params)
{
    if ($types === '') {
        return true;
    }
    $bind = array($types);
    foreach ($params as $key => &$value) {
        $bind[] = &$value;
    }
    return call_user_func_array(array($stmt, 'bind_param'), $bind);
}

function tv_rows(mysqli $db, $sql, $types = '', array $params = array())
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar consulta.');
    }
    if (!tv_bind($stmt, $types, $params)) {
        throw new RuntimeException('Falha ao vincular parametros.');
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao executar consulta.');
    }
    $res = $stmt->get_result();
    $rows = array();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function tv_one(mysqli $db, $sql, $types = '', array $params = array())
{
    $rows = tv_rows($db, $sql, $types, $params);
    return count($rows) ? $rows[0] : null;
}

function tv_exec(mysqli $db, $sql, $types = '', array $params = array())
{
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar gravacao.');
    }
    if (!tv_bind($stmt, $types, $params)) {
        throw new RuntimeException('Falha ao vincular gravacao.');
    }
    if (!$stmt->execute()) {
        throw new RuntimeException('Falha ao executar gravacao.');
    }
    return $stmt;
}

function tv_settings()
{
    $db = tv_app_db();
    if (!$db) {
        return tv_default_settings();
    }
    $rows = tv_rows($db, 'SELECT name, value FROM settings');
    $settings = tv_default_settings();
    foreach ($rows as $row) {
        $settings[$row['name']] = (string) $row['value'];
    }
    return $settings;
}

function tv_default_settings()
{
    return array(
        'xui_base_url' => '',
        'xui_access_code' => '',
        'xui_api_key' => '',
        'xui_default_package_id' => '1',
        'xui_default_bouquets' => '',
        'xui_default_outputs' => '1,2,3',
        'xui_default_connections' => '1',
        'xui_default_expire_days' => '0',
        'xui_force_server_id' => '0',
        'block_on_mkauth_blocked' => '1',
        'block_on_overdue' => '1',
        'password_mode' => 'generated',
        'username_prefix' => '',
    );
}

function tv_save_settings(array $values)
{
    $db = tv_app_db();
    if (!$db) {
        throw new RuntimeException('Banco do addon indisponivel.');
    }
    foreach ($values as $key => $value) {
        $cleanKey = trim((string) $key);
        if ($cleanKey === '') {
            continue;
        }
        $params = array($cleanKey, (string) $value);
        tv_exec($db, 'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', 'ss', $params);
    }
}

function tv_mask_settings(array $settings)
{
    $masked = $settings;
    if (!empty($masked['xui_api_key'])) {
        $key = (string) $masked['xui_api_key'];
        $masked['xui_api_key_masked'] = strlen($key) <= 6 ? str_repeat('*', strlen($key)) : substr($key, 0, 4) . str_repeat('*', 8);
    } else {
        $masked['xui_api_key_masked'] = '';
    }
    unset($masked['xui_api_key']);
    return $masked;
}

function tv_configured(array $settings = null)
{
    $settings = $settings ?: tv_settings();
    return trim((string) $settings['xui_base_url']) !== ''
        && trim((string) $settings['xui_access_code']) !== ''
        && trim((string) $settings['xui_api_key']) !== '';
}

function tv_csv_ints($value)
{
    if (is_array($value)) {
        $items = $value;
    } else {
        $items = preg_split('/[,\|;]+/', (string) $value);
    }
    $ids = array();
    foreach ($items as $item) {
        $text = trim((string) $item);
        if ($text !== '' && ctype_digit($text)) {
            $ids[] = (int) $text;
        }
    }
    return $ids;
}

function tv_client_status(array $client, array $settings)
{
    $blocked = strtolower((string) $client['bloqueado']) === 'sim';
    $activeFlag = strtolower((string) $client['cli_ativado']);
    $active = in_array($activeFlag, array('s', 'sim', '1', 'yes', 'ativo'), true);
    $overdue = ((int) $client['tit_vencidos']) > 0;
    $blockOnOverdue = (string) $settings['block_on_overdue'] === '1';
    $blockOnBlocked = (string) $settings['block_on_mkauth_blocked'] === '1';

    if (($blockOnBlocked && $blocked) || !$active) {
        return array('active' => false, 'status' => 'blocked', 'label' => 'Bloqueado no MK-Auth');
    }
    if ($blockOnOverdue && $overdue) {
        return array('active' => false, 'status' => 'overdue', 'label' => 'Bloqueado por vencido');
    }
    return array('active' => true, 'status' => 'active', 'label' => 'Ativo');
}

function tv_client_by_id($clientId)
{
    $db = tv_mk_db();
    if (!$db) {
        throw new RuntimeException('Banco MK-Auth indisponivel.');
    }
    $id = (int) $clientId;
    $params = array($id);
    return tv_one($db, "SELECT id, uuid_cliente, nome, login, senha, plano, bloqueado, cli_ativado, tit_vencidos, cpf_cnpj, celular, fone
        FROM sis_cliente WHERE id = ? LIMIT 1", 'i', $params);
}

function tv_search_clients($q, $limit = 25)
{
    $db = tv_mk_db();
    if (!$db) {
        throw new RuntimeException('Banco MK-Auth indisponivel.');
    }
    $limit = max(1, min((int) $limit, 50));
    $q = trim((string) $q);
    if ($q === '') {
        return tv_rows($db, "SELECT id, uuid_cliente, nome, login, plano, bloqueado, cli_ativado, tit_vencidos
            FROM sis_cliente ORDER BY nome ASC LIMIT {$limit}");
    }
    $like = '%' . $q . '%';
    $params = array($like, $like, $like, $like);
    return tv_rows($db, "SELECT id, uuid_cliente, nome, login, plano, bloqueado, cli_ativado, tit_vencidos
        FROM sis_cliente
        WHERE COALESCE(nome, '') LIKE ? OR COALESCE(login, '') LIKE ? OR COALESCE(cpf_cnpj, '') LIKE ? OR CAST(id AS CHAR) LIKE ?
        ORDER BY CASE WHEN COALESCE(login, '') = ? THEN 0 ELSE 1 END, nome ASC
        LIMIT {$limit}", 'sssss', array($like, $like, $like, $like, $q));
}

function tv_list_plans($activeOnly = false)
{
    $db = tv_app_db();
    if (!$db) {
        return array();
    }
    $sql = 'SELECT * FROM tv_plans';
    if ($activeOnly) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY active DESC, name ASC';
    return tv_rows($db, $sql);
}

function tv_plan($planId)
{
    $db = tv_app_db();
    $id = (int) $planId;
    $params = array($id);
    return $db ? tv_one($db, 'SELECT * FROM tv_plans WHERE id = ? LIMIT 1', 'i', $params) : null;
}

function tv_link_by_client($clientId)
{
    $db = tv_app_db();
    if (!$db) {
        return null;
    }
    $id = (int) $clientId;
    $params = array($id);
    return tv_one($db, 'SELECT * FROM client_tv_plans WHERE mk_client_id = ? LIMIT 1', 'i', $params);
}

function tv_link($linkId)
{
    $db = tv_app_db();
    if (!$db) {
        return null;
    }
    $id = (int) $linkId;
    $params = array($id);
    return tv_one($db, 'SELECT * FROM client_tv_plans WHERE id = ? LIMIT 1', 'i', $params);
}

function tv_dashboard()
{
    $app = tv_app_db();
    $mk = tv_mk_db();
    if (!$app || !$mk) {
        throw new RuntimeException('Banco indisponivel.');
    }
    $settings = tv_settings();
    $plans = tv_list_plans(false);
    $kpis = tv_one($app, "SELECT
        COUNT(*) linked,
        COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) active,
        COALESCE(SUM(CASE WHEN status IN ('blocked', 'overdue') THEN 1 ELSE 0 END), 0) blocked,
        COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) failed
        FROM client_tv_plans");
    if (!$kpis) {
        $kpis = array('linked' => 0, 'active' => 0, 'blocked' => 0, 'failed' => 0);
    }
    $linked = tv_rows($app, "SELECT ctp.*, p.name plan_name, p.max_connections
        FROM client_tv_plans ctp
        JOIN tv_plans p ON p.id = ctp.tv_plan_id
        ORDER BY ctp.updated_at DESC
        LIMIT 80");
    $clientIds = array();
    foreach ($linked as $row) {
        $clientIds[] = (int) $row['mk_client_id'];
    }
    $clients = tv_clients_map($clientIds);
    foreach ($linked as $idx => $row) {
        $client = isset($clients[(int) $row['mk_client_id']]) ? $clients[(int) $row['mk_client_id']] : array();
        $linked[$idx]['client_name'] = isset($client['nome']) ? $client['nome'] : '';
        $linked[$idx]['client_plan'] = isset($client['plano']) ? $client['plano'] : '';
        $linked[$idx]['mkauth_status'] = $client ? tv_client_status($client, $settings) : array('label' => 'Cliente ausente');
    }
    $logs = tv_rows($app, 'SELECT id, action, result, message, created_at FROM sync_logs ORDER BY id DESC LIMIT 30');
    return array(
        'configured' => tv_configured($settings),
        'license' => tv_public_license_status(tv_license_status()),
        'settings' => tv_mask_settings($settings),
        'plans' => $plans,
        'linked' => $linked,
        'logs' => $logs,
        'kpis' => array(
            'plans' => count($plans),
            'linked' => (int) $kpis['linked'],
            'active' => (int) $kpis['active'],
            'blocked' => (int) $kpis['blocked'],
            'failed' => (int) $kpis['failed'],
        ),
    );
}

function tv_clients_map(array $ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) {
        return array();
    }
    $db = tv_mk_db();
    if (!$db) {
        return array();
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $rows = tv_rows($db, "SELECT id, nome, login, plano, bloqueado, cli_ativado, tit_vencidos FROM sis_cliente WHERE id IN ({$placeholders})", $types, $ids);
    $map = array();
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row;
    }
    return $map;
}

function tv_log($linkId, $clientId, $action, $result, $message, $raw = array())
{
    $db = tv_app_db();
    if (!$db) {
        return;
    }
    $json = $raw ? json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    $linkValue = $linkId ? (int) $linkId : null;
    $clientValue = $clientId ? (int) $clientId : null;
    $params = array($linkValue, $clientValue, $action, $result, substr($message, 0, 255), $json);
    tv_exec($db, 'INSERT INTO sync_logs (client_tv_plan_id, mk_client_id, action, result, message, raw_json) VALUES (?, ?, ?, ?, ?, ?)', 'iissss', $params);
}

function tv_clean_login($value)
{
    $value = trim((string) $value);
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '', $value);
    return substr($value, 0, 70);
}

function tv_make_username(array $client, array $settings)
{
    $prefix = tv_clean_login(isset($settings['username_prefix']) ? $settings['username_prefix'] : '');
    $login = tv_clean_login(isset($client['login']) ? $client['login'] : '');
    if ($login === '') {
        $login = 'cliente' . (int) $client['id'];
    }
    return substr($prefix . $login, 0, 80);
}

function tv_make_password(array $client, array $settings)
{
    if ((string) $settings['password_mode'] === 'mkauth') {
        $password = tv_clean_login(isset($client['senha']) ? $client['senha'] : '');
        if ($password !== '') {
            return substr($password, 0, 32);
        }
    }
    return substr(bin2hex(random_bytes(6)), 0, 12);
}

function tv_xui_endpoint(array $settings)
{
    return rtrim((string) $settings['xui_base_url'], '/') . '/' . trim((string) $settings['xui_access_code'], '/') . '/';
}

function tv_xui_request($action, array $data = array(), $method = 'POST')
{
    $settings = tv_settings();
    if (!tv_configured($settings)) {
        throw new RuntimeException('Integracao de canais nao configurada.');
    }
    $endpoint = tv_xui_endpoint($settings);
    $query = http_build_query(array('api_key' => $settings['xui_api_key'], 'action' => $action));
    $url = $endpoint . '?' . $query;
    $method = strtoupper($method);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 35);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, tv_http_build($data));
        }
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 500) {
            throw new RuntimeException('Falha HTTP XUI: ' . ($error ?: 'codigo ' . $code));
        }
    } else {
        $opts = array('http' => array('method' => $method, 'timeout' => 35));
        if ($method === 'POST') {
            $opts['http']['header'] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['http']['content'] = tv_http_build($data);
        }
        $body = @file_get_contents($url, false, stream_context_create($opts));
        if ($body === false) {
            throw new RuntimeException('Falha HTTP XUI.');
        }
    }

    $decoded = json_decode((string) $body, true);
    if ($decoded === null && trim((string) $body) !== 'null') {
        throw new RuntimeException('Resposta XUI invalida.');
    }
    return $decoded;
}

function tv_http_build(array $data)
{
    $parts = array();
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $item) {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
            }
        } else {
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
    }
    return implode('&', $parts);
}

function tv_xui_lines()
{
    $response = tv_xui_request('get_lines', array(), 'POST');
    if (isset($response['data']) && is_array($response['data'])) {
        return $response['data'];
    }
    return is_array($response) ? $response : array();
}

function tv_xui_packages()
{
    $response = tv_xui_request('get_packages', array(), 'GET');
    if (isset($response['data']) && is_array($response['data'])) {
        return $response['data'];
    }
    return is_array($response) ? $response : array();
}

function tv_xui_find_line($username)
{
    $wanted = strtolower(trim((string) $username));
    if ($wanted === '') {
        return null;
    }
    foreach (tv_xui_lines() as $line) {
        if (!is_array($line)) {
            continue;
        }
        $current = strtolower(trim((string) (isset($line['username']) ? $line['username'] : '')));
        if ($current === $wanted) {
            return $line;
        }
    }
    return null;
}

function tv_xui_line_payload(array $client, array $plan, $username, $password, array $settings)
{
    $packageId = (int) $plan['xui_package_id'];
    if ($packageId <= 0) {
        $packageId = max(1, (int) $settings['xui_default_package_id']);
    }
    $bouquets = tv_csv_ints($plan['xui_bouquets']);
    if (!$bouquets) {
        $bouquets = tv_csv_ints($settings['xui_default_bouquets']);
    }
    $outputs = tv_csv_ints($plan['access_outputs']);
    if (!$outputs) {
        $outputs = tv_csv_ints($settings['xui_default_outputs']);
    }
    $payload = array(
        'username' => $username,
        'password' => $password,
        'package_id' => (string) $packageId,
        'max_connections' => (string) max(1, (int) $plan['max_connections']),
        'bouquets_selected' => json_encode(array_map('strval', $bouquets)),
        'admin_notes' => 'MK-Auth cliente #' . (int) $client['id'],
        'reseller_notes' => 'Criado pelo addon MK-Auth',
    );
    foreach ($outputs as $output) {
        $payload['access_output[]'][] = (string) $output;
    }
    $expireDays = max(0, (int) $settings['xui_default_expire_days']);
    if ($expireDays > 0) {
        $payload['exp_date'] = (string) (time() + ($expireDays * 86400));
    }
    $forceServer = max(0, (int) $settings['xui_force_server_id']);
    if ($forceServer > 0) {
        $payload['force_server_id'] = (string) $forceServer;
    }
    return $payload;
}

function tv_xui_response_id($response, $fallback = '')
{
    $id = tv_xui_find_id_value($response);
    return $id !== '' ? $id : (string) $fallback;
}

function tv_xui_find_id_value($value)
{
    if (!is_array($value)) {
        return '';
    }

    $keys = array('id', 'line_id', 'user_id', 'member_id', 'created_id', 'insert_id', 'stream_id');
    foreach ($keys as $key) {
        if (isset($value[$key])) {
            $candidate = trim((string) $value[$key]);
            if ($candidate !== '' && $candidate !== '0') {
                return $candidate;
            }
        }
    }

    foreach (array('data', 'result', 'response', 'line', 'user', 'created', 'record') as $key) {
        if (isset($value[$key]) && is_array($value[$key])) {
            $candidate = tv_xui_find_id_value($value[$key]);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    foreach ($value as $item) {
        if (is_array($item)) {
            $candidate = tv_xui_find_id_value($item);
            if ($candidate !== '') {
                return $candidate;
            }
        }
    }

    return '';
}

function tv_xui_response_summary($response)
{
    if (!is_array($response)) {
        return 'resposta vazia ou nao estruturada';
    }
    foreach (array('message', 'msg', 'error', 'status', 'result') as $key) {
        if (isset($response[$key]) && !is_array($response[$key])) {
            return substr($key . '=' . (string) $response[$key], 0, 180);
        }
    }
    return substr(json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 180);
}

function tv_mark_link_failed(mysqli $db, array $link, array $client, $action, $message, $raw = array())
{
    $message = substr((string) $message, 0, 255);
    $params = array('failed', $message, (int) $link['id']);
    tv_exec($db, 'UPDATE client_tv_plans SET status = ?, last_message = ?, updated_at = NOW() WHERE id = ?', 'ssi', $params);
    tv_log((int) $link['id'], (int) $client['id'], $action, 'failed', $message, is_array($raw) ? $raw : array('raw' => $raw));
}

function tv_sync_client($linkId)
{
    $app = tv_app_db();
    if (!$app) {
        throw new RuntimeException('Banco do addon indisponivel.');
    }
    $link = tv_link($linkId);
    if (!$link) {
        throw new RuntimeException('Vinculo nao encontrado.');
    }
    $client = tv_client_by_id((int) $link['mk_client_id']);
    if (!$client) {
        throw new RuntimeException('Cliente MK-Auth nao encontrado.');
    }
    $plan = tv_plan((int) $link['tv_plan_id']);
    if (!$plan) {
        throw new RuntimeException('Plano de TV nao encontrado.');
    }
    $settings = tv_settings();
    if (!tv_configured($settings)) {
        $params = array('pending_config', 'Integracao de canais ainda nao configurada', (int) $link['id']);
        tv_exec($app, 'UPDATE client_tv_plans SET status = ?, last_message = ?, updated_at = NOW() WHERE id = ?', 'ssi', $params);
        tv_log((int) $link['id'], (int) $client['id'], 'sync', 'pending_config', 'Integracao de canais ainda nao configurada.');
        return array('status' => 'pending_config', 'message' => 'Vinculo salvo. Configure a integracao de canais para sincronizar.');
    }

    $username = trim((string) $link['xui_username']);
    if ($username === '') {
        $username = tv_make_username($client, $settings);
    }
    $password = trim((string) $link['xui_password']);
    if ($password === '') {
        $password = tv_make_password($client, $settings);
    }
    $lineId = trim((string) $link['xui_line_id']);

    if ($lineId === '') {
        $found = tv_xui_find_line($username);
        if ($found && isset($found['id'])) {
            $lineId = (string) $found['id'];
        }
    }

    $payload = tv_xui_line_payload($client, $plan, $username, $password, $settings);
    if ($lineId !== '') {
        $payload['id'] = $lineId;
        $response = tv_xui_request('edit_line', $payload, 'POST');
        $lineId = tv_xui_response_id($response, $lineId);
    } else {
        $response = tv_xui_request('create_line', $payload, 'POST');
        $lineId = tv_xui_response_id($response, '');
        if ($lineId === '') {
            $found = tv_xui_find_line($username);
            if ($found) {
                $lineId = tv_xui_response_id($found, '');
            }
        }
        if ($lineId === '') {
            $summary = tv_xui_response_summary($response);
            $message = stripos($summary, 'Invalid API key') !== false
                ? 'API key invalida no painel de canais. Corrija em Configuracao.'
                : 'XUI nao retornou ID da linha criada. Resumo: ' . $summary;
            tv_mark_link_failed($app, $link, $client, 'create_line', $message, $response);
            throw new RuntimeException($message);
        }
    }

    $clientStatus = tv_client_status($client, $settings);
    if ($clientStatus['active']) {
        tv_xui_request('enable_line', array('id' => $lineId), 'POST');
        tv_xui_request('unban_line', array('id' => $lineId), 'POST');
        $status = 'active';
        $message = 'Linha ativa no XUI.';
    } else {
        tv_xui_request('disable_line', array('id' => $lineId), 'POST');
        tv_xui_request('ban_line', array('id' => $lineId), 'POST');
        $status = $clientStatus['status'];
        $message = $clientStatus['label'] . '; linha bloqueada no XUI.';
    }

    $params = array($lineId, $username, $password, $status, $message, (int) $link['id']);
    tv_exec($app, 'UPDATE client_tv_plans
        SET xui_line_id = ?, xui_username = ?, xui_password = ?, status = ?, last_message = ?, last_sync_at = NOW(), updated_at = NOW()
        WHERE id = ?', 'sssssi', $params);
    tv_log((int) $link['id'], (int) $client['id'], 'sync', $status, $message, array('line_id' => $lineId));
    return array('status' => $status, 'message' => $message, 'line_id' => $lineId, 'username' => $username);
}
