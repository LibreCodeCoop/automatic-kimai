# Automatic Kimai — PHP desktop

Código PHP 8.4 + Laravel 12 + NativePHP 2/Electron. Este diretório é o núcleo da migração na branch `feat/php-migration`; a implantação definitiva depende da conclusão dos critérios de [PHP_MIGRATION.md](../PHP_MIGRATION.md).

## Rodar localmente em desenvolvimento

Requisitos: PHP 8.4+ com cURL, DOM, mbstring, PDO SQLite, SQLite3, ZIP e OpenSSL; Composer 2; Node 22+; ActivityWatch opcional no primeiro acesso. O Electron e os scripts de empacotamento precisam do Node, mas toda a lógica de negócio do novo aplicativo está em PHP.

```sh
composer install --no-interaction
cp .env.example .env
chmod 600 .env
php artisan key:generate
php artisan package:discover
php vendor/bin/phpunit
php artisan serve --host=127.0.0.1 --port=6817
```

Abra `http://127.0.0.1:6817`. Não exponha a porta na rede. Configure o seu próprio Kimai e selecione os projetos pelo nome; o modo de revisão é o padrão. A coleta ActivityWatch usa `http://127.0.0.1:5600` e um banco local de evidências. Não coloque dados particulares em fixtures de testes.

Para desenvolvimento do aplicativo desktop, após preparar o PHP:
```sh
php artisan native:install --force --publish
php artisan native:run
```

A estrutura Electron publicada está em `nativephp/electron` e é um **runtime de interface**, não o núcleo de negócio. Os executáveis devem incluir um PHP próprio; não exigir instalação manual de PHP para o usuário final. A assinatura, atualização e binários multiplataforma ainda precisam de testes reais.

**Empacotamento bloqueado por segurança:** `native:build` é recusado se houver perfil, sessão ou chave `APP_KEY` de uma instalação em uso. O empacotador NativePHP não respeita automaticamente o `.gitignore`, por isso credenciais criptografadas e a chave de descriptografia poderiam acabar dentro do instalador. Os binários de prova gerados durante a avaliação foram eliminados; o código PHP continua intacto. Antes de qualquer release, implementar chave única no primeiro acesso e validar o isolamento dos segredos no instalador.

## Comandos CLI

```sh
php artisan automatic-kimai:sync --status
php artisan automatic-kimai:sync --capture-only
php artisan automatic-kimai:sync
```

Esses comandos usam o perfil local, fazem apenas leitura e mantêm caches sanitizados. `--apply` está bloqueado enquanto não existir paridade de telemetria/agentes e migração segura do ledger. Os agendadores antigos Python continuam operando na instalação já existente.

## Privacidade

A página PHP aceita conexões somente do loopback, usa CSRF nos formulários e não mostra tokens existentes. Os segredos são criptografados com a chave local da instalação e arquivos privados são restringidos a seu proprietário. O segredo de criptografia **não deve ser reutilizado entre instaladores distribuídos**; é necessário validá-lo em chaveiro por sistema operacional antes da publicação de aplicativos assinados.

Lembre-se: um agendamento ou um processo de IA rodando sozinho não equivale a horas humanas trabalhadas. A plataforma não aprova remuneração nem faturas automaticamente.

## Status da migração

Algoritmos de segmentação, lacunas, GET Kimai, proteção de escrita, ActivityWatch, Nextcloud Calendar/Talk, GitHub, Ollama e frontend Laravel têm implementação e testes. Telegram MTProto, o publicador aprovado, a fila histórica e componentes do piloto precisam de paridade completa e testes de produção **somente leitura** antes de qualquer substituição do runtime antigo.

Este código é um **candidato de implementação** — não uma release e não uma autorização para remover os serviços Python ainda ativos.
