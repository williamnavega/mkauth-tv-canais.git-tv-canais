#!/usr/bin/env bash
set -Eeuo pipefail

APP_NAME="tv-xui"
DB_USER="mkxui_app"
APP_DB="mkauth_xui"
MK_DB="mkradius"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_DIR="/opt/mk-auth/admin/addons/${APP_NAME}"
ADDONS_DIR="/opt/mk-auth/admin/addons"
ADDON_JS="${ADDONS_DIR}/addon.js"
CONFIG_DIR="/etc/mkauth-xui-addon"
CONFIG_FILE="${CONFIG_DIR}/config.php"
STATE_DIR="/var/lib/mkauth-xui-addon"
OLD_LICENSE_FILE="${CONFIG_DIR}/license.json"
LICENSE_FILE="${STATE_DIR}/license.json"
LICENSE_PUBLIC_FILE="${CONFIG_DIR}/license-public.pem"
BACKUP_ROOT="/opt/mk-auth/bckp"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_DIR="${BACKUP_ROOT}/tv-xui-${STAMP}"
LOG_DIR="/var/log/mkauth-xui-addon"
LOG_FILE="${LOG_DIR}/install.log"

mkdir -p "${LOG_DIR}"
touch "${LOG_FILE}"
exec > >(tee -a "${LOG_FILE}") 2>&1

echo "== TV Canais install ${STAMP} =="

if [ "$(id -u)" -ne 0 ]; then
  echo "Execute como root."
  exit 1
fi

for required in php find install ln cp grep sed date tee; do
  if ! command -v "${required}" >/dev/null 2>&1; then
    echo "Dependencia ausente: ${required}"
    exit 1
  fi
done

if [ ! -d "/opt/mk-auth/admin" ] || [ ! -f "/opt/mk-auth/include/addons.inc.hhvm" ]; then
  echo "MK-Auth nao encontrado em /opt/mk-auth."
  exit 1
fi

mkdir -p "${BACKUP_DIR}"
if [ -d "${TARGET_DIR}" ]; then
  cp -a "${TARGET_DIR}" "${BACKUP_DIR}/tv-xui"
fi
if [ -f "${ADDON_JS}" ]; then
  cp -a "${ADDON_JS}" "${BACKUP_DIR}/addon.js"
fi
if [ -f "${CONFIG_FILE}" ]; then
  cp -a "${CONFIG_FILE}" "${BACKUP_DIR}/config.php"
fi
if [ -f "${LICENSE_FILE}" ]; then
  cp -a "${LICENSE_FILE}" "${BACKUP_DIR}/license.json"
fi
if [ -f "${OLD_LICENSE_FILE}" ]; then
  cp -a "${OLD_LICENSE_FILE}" "${BACKUP_DIR}/license-etc.json"
fi
if [ -f "${LICENSE_PUBLIC_FILE}" ]; then
  cp -a "${LICENSE_PUBLIC_FILE}" "${BACKUP_DIR}/license-public.pem"
fi

install -d -m 0755 "${TARGET_DIR}/assets"
install -d -m 0750 -o root -g www-data "${CONFIG_DIR}"
install -d -m 0770 -o root -g www-data "${STATE_DIR}"

cp -a "${SRC_DIR}/manifest.json" "${TARGET_DIR}/manifest.json"
cp -a "${SRC_DIR}/lib.php" "${TARGET_DIR}/lib.php"
cp -a "${SRC_DIR}/index.php" "${TARGET_DIR}/index.php"
cp -a "${SRC_DIR}/api.php" "${TARGET_DIR}/api.php"
cp -a "${SRC_DIR}/health.php" "${TARGET_DIR}/health.php"
cp -a "${SRC_DIR}/assets/tv-xui.css" "${TARGET_DIR}/assets/tv-xui.css"
cp -a "${SRC_DIR}/assets/tv-xui.js" "${TARGET_DIR}/assets/tv-xui.js"
if [ -f "${SRC_DIR}/license-public.pem" ]; then
  install -m 0644 -o root -g www-data "${SRC_DIR}/license-public.pem" "${LICENSE_PUBLIC_FILE}"
fi
if [ -f "${SRC_DIR}/license.json" ]; then
  install -m 0660 -o root -g www-data "${SRC_DIR}/license.json" "${LICENSE_FILE}"
elif [ ! -f "${LICENSE_FILE}" ] && [ -f "${OLD_LICENSE_FILE}" ]; then
  install -m 0660 -o root -g www-data "${OLD_LICENSE_FILE}" "${LICENSE_FILE}"
fi

ln -sfn /opt/mk-auth/include/addons.inc.hhvm "${TARGET_DIR}/addons.class.php"

chown -R root:www-data "${TARGET_DIR}"
find "${TARGET_DIR}" -type d -exec chmod 0755 {} +
find "${TARGET_DIR}" -type f -exec chmod 0644 {} +

php -l "${TARGET_DIR}/lib.php"
php -l "${TARGET_DIR}/index.php"
php -l "${TARGET_DIR}/api.php"
php -l "${TARGET_DIR}/health.php"
php -r 'if (!function_exists("openssl_verify")) { fwrite(STDERR, "Extensao PHP OpenSSL ausente.\n"); exit(1); }'

php <<'PHP'
<?php
$configFile = '/etc/mkauth-xui-addon/config.php';
$dbUser = 'mkxui_app';
$dbHosts = array('localhost', '127.0.0.1');
$appDb = 'mkauth_xui';
$mkDb = 'mkradius';

require '/opt/mk-auth/include/conexao.php';
if (!isset($LOADMYSQL) || !($LOADMYSQL instanceof mysqli) || $LOADMYSQL->connect_errno) {
    fwrite(STDERR, "Falha ao conectar no banco principal do MK-Auth.\n");
    exit(1);
}

$password = null;
if (is_readable($configFile)) {
    $current = include $configFile;
    if (is_array($current) && isset($current['app_db']) && is_array($current['app_db']) && !empty($current['app_db']['password'])) {
        $password = (string) $current['app_db']['password'];
    }
}
if ($password === null) {
    $password = bin2hex(random_bytes(24));
}

if (!$LOADMYSQL->query("CREATE DATABASE IF NOT EXISTS `{$appDb}` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci")) {
    fwrite(STDERR, "Falha ao criar banco do addon TV XUI.\n");
    exit(1);
}

$user = $LOADMYSQL->real_escape_string($dbUser);
$pass = $LOADMYSQL->real_escape_string($password);
foreach ($dbHosts as $dbHost) {
    $host = $LOADMYSQL->real_escape_string($dbHost);
    $exists = false;
    $res = $LOADMYSQL->query("SELECT COUNT(*) FROM mysql.user WHERE User = '{$user}' AND Host = '{$host}'");
    if ($res) {
        $row = $res->fetch_row();
        $exists = ((int) $row[0]) > 0;
    }

    if ($exists) {
        if (!$LOADMYSQL->query("SET PASSWORD FOR '{$user}'@'{$host}' = PASSWORD('{$pass}')")) {
            fwrite(STDERR, "Falha ao atualizar senha do usuario MySQL TV XUI.\n");
            exit(1);
        }
    } else {
        if (!$LOADMYSQL->query("CREATE USER '{$user}'@'{$host}' IDENTIFIED BY '{$pass}'")) {
            fwrite(STDERR, "Falha ao criar usuario MySQL TV XUI.\n");
            exit(1);
        }
    }

    if (!$LOADMYSQL->query("GRANT SELECT ON `{$mkDb}`.`sis_cliente` TO '{$user}'@'{$host}'")) {
        fwrite(STDERR, "Falha ao conceder SELECT em sis_cliente.\n");
        exit(1);
    }
    if (!$LOADMYSQL->query("GRANT ALL PRIVILEGES ON `{$appDb}`.* TO '{$user}'@'{$host}'")) {
        fwrite(STDERR, "Falha ao conceder permissoes no banco do addon.\n");
        exit(1);
    }
}
$LOADMYSQL->query('FLUSH PRIVILEGES');

$config = "<?php\nreturn array(\n"
    . "    'app_db' => array(\n"
    . "        'host' => '127.0.0.1',\n"
    . "        'user' => '" . addslashes($dbUser) . "',\n"
    . "        'password' => '" . addslashes($password) . "',\n"
    . "        'database' => '" . addslashes($appDb) . "',\n"
    . "    ),\n"
    . "    'mk_db' => array(\n"
    . "        'host' => '127.0.0.1',\n"
    . "        'user' => '" . addslashes($dbUser) . "',\n"
    . "        'password' => '" . addslashes($password) . "',\n"
    . "        'database' => '" . addslashes($mkDb) . "',\n"
    . "    ),\n"
    . "    'license' => array(\n"
    . "        'file' => '/var/lib/mkauth-xui-addon/license.json',\n"
    . "        'public_key' => '/etc/mkauth-xui-addon/license-public.pem',\n"
    . "        'product' => 'tv-canais',\n"
    . "    ),\n"
    . ");\n";
if (file_put_contents($configFile, $config) === false) {
    fwrite(STDERR, "Falha ao gravar configuracao segura TV XUI.\n");
    exit(1);
}
echo "Usuario MySQL dedicado: {$dbUser}@localhost e {$dbUser}@127.0.0.1.\n";
echo "Banco do addon: {$appDb}.\n";
PHP

chown root:www-data "${CONFIG_FILE}"
chmod 0640 "${CONFIG_FILE}"
if [ -f "${LICENSE_PUBLIC_FILE}" ]; then
  chown root:www-data "${LICENSE_PUBLIC_FILE}"
  chmod 0644 "${LICENSE_PUBLIC_FILE}"
fi
if [ -f "${LICENSE_FILE}" ]; then
  chown root:www-data "${LICENSE_FILE}"
  chmod 0660 "${LICENSE_FILE}"
fi

php -r 'require "/opt/mk-auth/admin/addons/tv-xui/lib.php"; $db = tv_app_db(); if (!$db) { fwrite(STDERR, "Falha ao migrar banco do addon.\n"); exit(1); } echo "Tabelas do addon verificadas.\n";'

if [ ! -f "${ADDON_JS}" ]; then
  install -m 0644 /dev/null "${ADDON_JS}"
fi

sed -i '/\/\* MKAUTH-XUI-ADDON-BEGIN \*\//,/\/\* MKAUTH-XUI-ADDON-END \*\//d' "${ADDON_JS}"

cat >> "${ADDON_JS}" <<'JS'

/* MKAUTH-XUI-ADDON-BEGIN */
const tvxui_url = window.location.protocol + "//" + window.location.hostname + (window.location.port ? ':' + window.location.port : '') + "/admin/";
if (typeof add_menu !== "undefined") {
  if (add_menu.menu_titulo) {
    add_menu.menu_titulo("provedor", "TV Canais");
  }
  if (add_menu.provedor) {
    add_menu.provedor('{"plink": "' + tvxui_url + 'addons/tv-xui/", "ptext": "Planos de TV", "picone": "bi-tv"}');
  }
}
/* MKAUTH-XUI-ADDON-END */
JS

chown www-data:www-data "${ADDON_JS}"
chmod 0755 "${ADDON_JS}"

echo "Backup: ${BACKUP_DIR}"
echo "Config: ${CONFIG_FILE}"
echo "Licenca: ${LICENSE_FILE}"
echo "Chave publica: ${LICENSE_PUBLIC_FILE}"
echo "Log: ${LOG_FILE}"
echo "URL: /admin/addons/tv-xui/"
echo "Health: /admin/addons/tv-xui/health.php"
echo "Instalacao concluida."
