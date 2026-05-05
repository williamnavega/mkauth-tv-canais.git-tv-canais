<?php
if (PHP_SAPI === 'cli') {
    if (!defined('ADMIN2URL')) {
        define('ADMIN2URL', 'http://localhost/admin/');
    }
    $Manifest = json_decode((string) @file_get_contents(__DIR__ . '/manifest.json'));
} else {
    include('addons.class.php');
}

require_once __DIR__ . '/lib.php';

$action = tv_get('action', tv_post('action', 'dashboard'));

try {
    if ($action === 'health') {
        $app = tv_app_db();
        $mk = tv_mk_db();
        $license = tv_public_license_status(tv_license_status());
        $healthy = (bool) ($app && $mk && $license['valid']);
        tv_json($healthy, array(
            'app_db' => $app ? 'ok' : 'fail',
            'mk_db' => $mk ? 'ok' : 'fail',
            'configured' => tv_configured(tv_settings()) ? 'yes' : 'no',
            'license' => $license,
        ), $healthy ? 200 : 503);
    }

    if ($action === 'license_status') {
        $license = tv_public_license_status(tv_license_status());
        tv_json((bool) $license['valid'], array('license' => $license), $license['valid'] ? 200 : 403);
    }

    if ($action === 'install_license') {
        $license = tv_install_license(tv_post('license_json'));
        tv_json(true, array('message' => 'Licenca ativada com sucesso.', 'license' => $license));
    }

    tv_require_license(true);

    if ($action === 'dashboard') {
        tv_json(true, tv_dashboard());
    }

    if ($action === 'search_clients') {
        tv_json(true, array('clients' => tv_search_clients(tv_get('q'), (int) tv_get('limit', '25'))));
    }

    if ($action === 'save_settings') {
        $current = tv_settings();
        $apiKey = tv_post('xui_api_key', '');
        $values = array(
            'xui_base_url' => rtrim(tv_post('xui_base_url'), '/'),
            'xui_access_code' => tv_post('xui_access_code'),
            'xui_default_package_id' => (string) max(1, (int) tv_post('xui_default_package_id', '1')),
            'xui_default_bouquets' => tv_post('xui_default_bouquets'),
            'xui_default_outputs' => tv_post('xui_default_outputs', '1,2,3'),
            'xui_default_connections' => (string) max(1, (int) tv_post('xui_default_connections', '1')),
            'xui_default_expire_days' => (string) max(0, (int) tv_post('xui_default_expire_days', '0')),
            'xui_force_server_id' => (string) max(0, (int) tv_post('xui_force_server_id', '0')),
            'block_on_mkauth_blocked' => tv_post('block_on_mkauth_blocked') === '1' ? '1' : '0',
            'block_on_overdue' => tv_post('block_on_overdue') === '1' ? '1' : '0',
            'password_mode' => tv_post('password_mode') === 'mkauth' ? 'mkauth' : 'generated',
            'username_prefix' => tv_post('username_prefix'),
        );
        $values['xui_api_key'] = $apiKey !== '' ? $apiKey : (isset($current['xui_api_key']) ? $current['xui_api_key'] : '');
        tv_save_settings($values);
        tv_json(true, array('message' => 'Configuracao salva.'));
    }

    if ($action === 'save_plan') {
        $db = tv_app_db();
        if (!$db) {
            throw new RuntimeException('Banco do addon indisponivel.');
        }
        $id = (int) tv_post('id', '0');
        $name = tv_post('name');
        if ($name === '') {
            throw new RuntimeException('Nome do plano de TV e obrigatorio.');
        }
        $value = str_replace(',', '.', tv_post('monthly_value', '0'));
        $params = array(
            $name,
            tv_post('description'),
            (float) $value,
            max(0, (int) tv_post('xui_package_id', '0')),
            tv_post('xui_bouquets'),
            max(1, (int) tv_post('max_connections', '1')),
            tv_post('access_outputs', '1,2,3'),
            tv_post('active') === '1' ? 1 : 0,
        );
        if ($id > 0) {
            $params[] = $id;
            tv_exec($db, 'UPDATE tv_plans SET name = ?, description = ?, monthly_value = ?, xui_package_id = ?, xui_bouquets = ?, max_connections = ?, access_outputs = ?, active = ? WHERE id = ?', 'ssdisisii', $params);
        } else {
            tv_exec($db, 'INSERT INTO tv_plans (name, description, monthly_value, xui_package_id, xui_bouquets, max_connections, access_outputs, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', 'ssdisisi', $params);
            $id = (int) $db->insert_id;
        }
        tv_json(true, array('message' => 'Plano salvo.', 'id' => $id));
    }

    if ($action === 'assign_client') {
        $db = tv_app_db();
        if (!$db) {
            throw new RuntimeException('Banco do addon indisponivel.');
        }
        $clientId = (int) tv_post('client_id', '0');
        $planId = (int) tv_post('plan_id', '0');
        $client = tv_client_by_id($clientId);
        $plan = tv_plan($planId);
        if (!$client) {
            throw new RuntimeException('Cliente MK-Auth nao encontrado.');
        }
        if (!$plan) {
            throw new RuntimeException('Plano de TV nao encontrado.');
        }
        $mkLogin = (string) $client['login'];
        $syncEnabled = tv_post('sync_enabled', '1') === '1' ? 1 : 0;
        $params = array($clientId, $mkLogin, $planId, $syncEnabled);
        tv_exec($db, 'INSERT INTO client_tv_plans (mk_client_id, mk_login, tv_plan_id, sync_enabled)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE mk_login = VALUES(mk_login), tv_plan_id = VALUES(tv_plan_id), sync_enabled = VALUES(sync_enabled), updated_at = NOW()', 'isii', $params);
        $link = tv_link_by_client($clientId);
        tv_log((int) $link['id'], $clientId, 'assign', 'saved', 'Plano de TV vinculado ao cliente.');
        $sync = null;
        if ($syncEnabled) {
            $sync = tv_sync_client((int) $link['id']);
        }
        tv_json(true, array('message' => 'Plano de TV vinculado.', 'sync' => $sync));
    }

    if ($action === 'sync_client') {
        $linkId = (int) tv_post('link_id', tv_get('link_id', '0'));
        tv_json(true, tv_sync_client($linkId));
    }

    if ($action === 'sync_all') {
        $db = tv_app_db();
        if (!$db) {
            throw new RuntimeException('Banco do addon indisponivel.');
        }
        $links = tv_rows($db, 'SELECT id FROM client_tv_plans WHERE sync_enabled = 1 ORDER BY updated_at DESC LIMIT 200');
        $result = array('processed' => 0, 'failed' => 0);
        foreach ($links as $link) {
            try {
                tv_sync_client((int) $link['id']);
                $result['processed'] += 1;
            } catch (Throwable $error) {
                $result['failed'] += 1;
                tv_log((int) $link['id'], 0, 'sync_all', 'failed', $error->getMessage());
            }
        }
        tv_json(true, $result);
    }

    if ($action === 'test_xui') {
        $packages = tv_xui_packages();
        tv_json(true, array('message' => 'Integracao respondeu.', 'packages' => count($packages)));
    }

    tv_json(false, array('message' => 'Acao invalida.'), 400);
} catch (Throwable $error) {
    tv_json(false, array('message' => $error->getMessage()), 500);
}
