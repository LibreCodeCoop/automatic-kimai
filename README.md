# Automatic Kimai

**PHP 8.4 + Laravel 12 + NativePHP/Electron.** Software local para analisar
evidências de atividade, identificar lacunas e revisar horas que poderão ser
registradas em uma conta individual do [Kimai](https://www.kimai.org/).

**Estado:** migração em desenvolvimento; PR em draft. O código PHP funciona
localmente em modo de revisão. O publicador PHP de sessões aprovadas está
implementado com testes simulados, mas sua ativação está bloqueada até
homologação completa e corte único de escritor. Não distribuir como instalador
final nem desligar uma instalação Python ainda ativa.

O produto não presume uma empresa, domínio, cliente, projeto ou servidor
específico. Cada usuário conecta sua própria instância Kimai e escolhe
explicitamente projeto, atividade e fontes de dados.

## Funcionalidades disponíveis no PHP

- Coleta local sanitizada do ActivityWatch; vigilância AFK e exclusão de contexto privado.
- Leitura Kimai com verificação da conta, catálogo e intervalos já registrados.
- Fontes opt-in de calendário, Talk, Activity e Mail do Nextcloud, GitHub e Telegram MTProto.
- Revisão manual das lacunas, contexto de trabalho presencial e avisos sobre evidências incompletas.
- Fila de aprovação com decisão humana e hash da evidência congelado.
- Outbox SQLite que impede POST duplicado e reconciliação por GET para resultados incertos.
- Importação idempotente do histórico legado, sem sobrescrever propostas do algoritmo PHP.
- Metadados de áudio PipeWire e telemetria de agentes separados de horas humanas.
- Interface Laravel local e prévia Electron em Linux; instaladores independentes
  Windows, macOS e Linux ainda não foram homologados.

**Limite essencial:** janela aberta, chamada presumida, mensagem enviada,
agenda ou agente de IA executando não comprovam trabalho humano nem autorizam
faturamento. O modo padrão é somente leitura.

## Desenvolvimento

Requisitos: PHP 8.4+, Composer 2, Node 22+ para Electron, extensões PHP
cURL, DOM, fileinfo, mbstring, OpenSSL, PDO SQLite, SQLite3, XML e ZIP.
Opcionalmente, ActivityWatch local em http://127.0.0.1:5600.

~~~sh
cd php-app
composer install --no-interaction
cp .env.example .env
chmod 600 .env
php artisan key:generate
php artisan package:discover
php vendor/bin/phpunit
php artisan serve --host=127.0.0.1 --port=6817
~~~

Abra http://127.0.0.1:6817. Não exponha o painel na rede.
A configuração inicial requer um token Kimai individual, URL HTTPS e
timezone compatível. O perfil privado e as credenciais nunca entram no Git.

A prévia de janela Electron em Linux utiliza:

~~~sh
cd php-app
./desktop-preview/launch.sh --check
./desktop-preview/install-desktop.sh
~~~

Essa prévia depende do backend PHP instalado localmente; não é um instalador
autocontido. Consulte [php-app/README.md](php-app/README.md) para o
NativePHP, os segredos na distribuição e as limitações de empacotamento.

## Comandos de análise, histórico e aprovação

~~~sh
cd php-app
php artisan automatic-kimai:sync
php artisan automatic-kimai:sync --status
php artisan automatic-kimai:migration-audit --legacy-cache=/caminho/privado/.kimai_cache
php artisan automatic-kimai:import-legacy --legacy-cache=/caminho/privado/.kimai_cache
php artisan automatic-kimai:publish-approved
php artisan automatic-kimai:publish-approved --reconcile-unknown
php artisan automatic-kimai:audio-sample --status
~~~

A importação exige um snapshot consistente e a opção **--apply-import**
para realmente transferir histórico ao armazenamento PHP. A migração não
modifica bancos Python nem causa gravação remota no Kimai.

O publicador faz **dry-run** por padrão. Seu modo de escrita
**--post-approved** exige cutover supervisionado, consentimento e verificação
recente de conta; esses controles não estão autorizados na avaliação.
A origem antiga não pode permanecer publicando simultaneamente.
O comando de áudio só coleta metadados se Telegram estiver explicitamente
ativado; habilitar apenas a tela de configuração não constitui consentimento.

## Desenvolvimento e privacidade

~~~sh
cd php-app
php vendor/bin/phpunit
php scripts/check-publication.php
node --test desktop-preview/*.test.cjs
~~~

Não versione bancos, tokens, sessões Telegram, histórico de navegação,
títulos de janelas, mensagens ou domínios confidenciais. O verificador
de publicação falha se detectar arquivos Python legados, credenciais,
arquivos privados ou termos definidos pelo desenvolvedor em
**.publication-denylist** (arquivo local, modo 0600, ignorado pelo Git).

O projeto propõe licença GPL-3.0-or-later, sujeita à verificação da
titularidade e dos avisos de dependências antes da primeira release.
Consulte [CONTRIBUTING.md](CONTRIBUTING.md), [PRIVACY.md](PRIVACY.md),
[SECURITY.md](SECURITY.md) e [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).

## Condições para substituir o piloto Python

A branch PHP pode remover o código Python versionado **sem** interromper
o processo antigo que opera em outro diretório do computador. Isso não
representa aprovação operacional para o corte.

Antes de desativar serviços antigos ou mesclar como migração concluída:
homologar a fonte Telegram autorizada; testar publicação real em Kimai
descartável com falhas e limpeza; concluir agendadores de áudio/agentes;
comparar evidências em replay; validar instaladores multiplataforma;
congelar a origem, repetir a transferência do histórico e comprovar
um só escritor e rollback. A situação e os resultados estão em
[PHP_MIGRATION.md](PHP_MIGRATION.md).

O Automatic Kimai é independente dos projetos Kimai, Nextcloud, GitHub,
Telegram, OpenAI e Anthropic; nomes são usados apenas para descrever
integrações opcionais.
