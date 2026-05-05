<?php
require_once __DIR__ . '/lib.php';

$app = tv_app_db();
$mk = tv_mk_db();
$settings = tv_settings();
$license = tv_public_license_status(tv_license_status());
$checks = array(
    'app_db' => $app ? 'ok' : 'fail',
    'mk_db' => $mk ? 'ok' : 'fail',
    'config' => is_readable('/etc/mkauth-xui-addon/config.php') ? 'ok' : 'fail',
    'license' => $license['valid'] ? 'ok' : 'fail',
    'assets' => (is_readable(__DIR__ . '/assets/tv-xui.js') && is_readable(__DIR__ . '/assets/tv-xui.css')) ? 'ok' : 'fail',
    'xui_configured' => tv_configured($settings) ? 'ok' : 'pending',
);

$ok = $checks['app_db'] === 'ok' && $checks['mk_db'] === 'ok' && $checks['config'] === 'ok' && $checks['license'] === 'ok' && $checks['assets'] === 'ok';
http_response_code($ok ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode(array('ok' => $ok, 'generated_at' => date('c'), 'checks' => $checks, 'license' => $license), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
