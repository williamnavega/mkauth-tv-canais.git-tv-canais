#!/usr/bin/env bash
set -Eeuo pipefail

curl -fsS http://127.0.0.1/admin/addons/tv-xui/health.php
echo

php <<'PHP'
<?php
chdir('/opt/mk-auth/admin/addons/tv-xui');
require 'lib.php';
$dashboard = tv_dashboard();
echo 'DASH_OK plans=' . count($dashboard['plans'])
    . ' linked=' . count($dashboard['linked'])
    . ' logs=' . count($dashboard['logs'])
    . ' configured=' . ($dashboard['configured'] ? 'yes' : 'no')
    . PHP_EOL;
PHP

grep -n "MKAUTH-XUI-ADDON\|add_menu.provedor.*tv-xui" /opt/mk-auth/admin/addons/addon.js
apachectl configtest

php <<'PHP'
<?php
$cfg = include '/etc/mkauth-xui-addon/config.php';
$app = new mysqli($cfg['app_db']['host'], $cfg['app_db']['user'], $cfg['app_db']['password'], $cfg['app_db']['database']);
$mk = new mysqli($cfg['mk_db']['host'], $cfg['mk_db']['user'], $cfg['mk_db']['password'], $cfg['mk_db']['database']);
echo (!$app->connect_errno && $app->query('SELECT COUNT(*) FROM tv_plans')) ? "APP_DB_OK\n" : "APP_DB_FAIL\n";
echo (!$mk->connect_errno && $mk->query('SELECT COUNT(*) FROM sis_cliente')) ? "MK_READ_OK\n" : "MK_READ_FAIL\n";
$write = @$mk->query('UPDATE sis_cliente SET id=id WHERE 1=0');
echo $write ? "MK_WRITE_UNEXPECTED\n" : "MK_WRITE_BLOCKED\n";
PHP
