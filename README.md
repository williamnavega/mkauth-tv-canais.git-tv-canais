# TV Canais para MK-Auth

Addon nativo para MK-Auth que permite ao agente escolher um cliente, adicionar um plano extra de TV e sincronizar a linha diretamente com o painel de TV.

## Fluxo

1. Cadastre os planos de TV no addon.
2. Informe package, bouquets, telas/conexoes e outputs do XUI.
3. Busque o cliente MK-Auth no painel.
4. Vincule o cliente ao plano adicional de TV.
5. O addon cria ou atualiza a linha no XUI.
6. Quando o cliente bloquear no MK-Auth, o addon bloqueia tambem no XUI.
7. Ajuste telas/conexoes e outputs por cliente quando precisar de uma regra diferente do plano.
8. Copie a lista gerada do cliente direto em **Clientes TV** quando a linha estiver sincronizada.

## Estrutura

- Addon: `/opt/mk-auth/admin/addons/tv-xui/`
- Configuracao DB: `/etc/mkauth-xui-addon/config.php`
- Licenca: `/var/lib/mkauth-xui-addon/license.json`
- Chave publica: `/etc/mkauth-xui-addon/license-public.pem`
- Banco proprio: `mkauth_xui`
- Logs de instalacao: `/var/log/mkauth-xui-addon/install.log`
- Backup: `/opt/mk-auth/bckp/tv-xui-YYYYmmdd-HHMMSS`

## Tabelas

- `tv_plans`: planos adicionais de TV.
- `client_tv_plans`: vinculo cliente MK-Auth -> plano TV -> linha XUI, com overrides de telas/conexoes e outputs.
- `settings`: credenciais e preferencias XUI.
- `sync_logs`: historico operacional.

## Seguranca

- Usuario MySQL dedicado `mkxui_app`.
- Permissao `SELECT` somente em `mkradius.sis_cliente`.
- Permissao completa apenas no banco proprio `mkauth_xui`.
- Credenciais XUI ficam no banco do addon e nao sao exibidas no painel.
- Licenca validada offline com RSA-SHA256, `machine_id`, produto e validade.
- Addon roda dentro da sessao autenticada do MK-Auth.

## Licenciamento

O addon bloqueia a tela e a API quando a licenca nao e valida. Para emitir:

1. Pegue o `machine_id` exibido na tela bloqueada ou rode o comando do painel de licencas.
2. Gere a licenca no painel central Docker.
3. Abra o addon, cole o JSON no campo **JSON da licenca** e clique em **Ativar licenca**.

Tambem e possivel instalar por SSH:

1. Instale o JSON em `/var/lib/mkauth-xui-addon/license.json`.
2. Instale a chave publica em `/etc/mkauth-xui-addon/license-public.pem`.
3. Ajuste permissoes:

```bash
chown root:www-data /var/lib/mkauth-xui-addon/license.json /etc/mkauth-xui-addon/license-public.pem
chmod 0660 /var/lib/mkauth-xui-addon/license.json
chmod 0644 /etc/mkauth-xui-addon/license-public.pem
```

## Instalacao

```bash
tar -xzf mkauth-xui-addon.tar.gz -C /tmp
cd /tmp/mkauth-xui-addon
bash install.sh
```

## Validacao

```bash
php -l /opt/mk-auth/admin/addons/tv-xui/lib.php
php -l /opt/mk-auth/admin/addons/tv-xui/api.php
curl -s http://127.0.0.1/admin/addons/tv-xui/health.php
apachectl configtest
```
