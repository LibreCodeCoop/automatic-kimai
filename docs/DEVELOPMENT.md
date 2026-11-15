# Desenvolvimento — Automatic Kimai

A raiz do repositório é **a raiz Laravel**. O diretório `app/` concentra
o núcleo de negócio em PHP; `routes/`, `resources/`, `config/`, `database/`,
`bootstrap/`, `public/` e `storage/` seguem a organização Laravel.
`nativephp/electron/` contém a ponte e a camada de janela desktop;
`desktop-preview/` é apenas uma prévia local da janela.

O projeto possui uma única raiz de aplicação, compartilhada pelo Laravel e pelas ferramentas de build.

## Instalação para desenvolvimento

Requisitos: PHP 8.4+, Composer 2, SQLite, cURL, DOM, mbstring, OpenSSL,
fileinfo, XML e ZIP; Node 22+ para Electron. ActivityWatch pode ser habilitado
depois de iniciar o aplicativo.

Na raiz:

```sh
composer install --no-interaction
cp .env.example .env
chmod 600 .env
php artisan key:generate
php artisan package:discover
php vendor/bin/phpunit
php artisan serve --host=127.0.0.1 --port=6817
```

Configure sua conta individual no painel local. O `.env.example` não contém
`APP_KEY` e mantém debug e logging de mensagens desativados. Gere uma chave
única para cada instalação; nunca distribua `.env` de outra pessoa.

## Desenvolvimento Electron/NativePHP

```sh
php artisan native:install --force --publish
php artisan native:run
```

A prévia `desktop-preview/launch.sh` abre a interface local com o backend
PHP já disponível. Não é um instalador independente. O empacotamento final
exige runtime PHP com extensões adequadas, gestão de chave por sistema
operacional, revisão de dependências e testes reais de Linux/Windows/macOS.
Telegram MTProto pode depender de GMP para desempenho adequado.

## Análise e publicação

```sh
php artisan automatic-kimai:sync --status
php artisan automatic-kimai:sync
php artisan automatic-kimai:publish-approved
php artisan automatic-kimai:publish-approved --reconcile-unknown
```

Sem corte supervisionado, consentimento e verificação de identidade, esses
comandos não fazem publicações remotas. ActivityWatch, calendário, GitHub,
Telegram e outros contextos não medem por si só horas humanas trabalhadas.
O motor cruza hipóteses com lançamentos existentes, mantém observações
incertas e não cria aprovações fictícias.

A migração de uma instalação anterior exige parar **e desabilitar** o escritor
antigo, guardar backup privado, auditar o estado final e somente então ativar
um escritor PHP único. Consulte [Checklist de release](RELEASE_CHECKLIST.md).

## Testes

```sh
php vendor/bin/phpunit --colors=never
php scripts/check-publication.php
node --test desktop-preview/*.test.cjs
cd nativephp/electron
npm ci --ignore-scripts --no-audit --no-fund
npm run plugin:build
npm run build
```

Na CI, Composer e PHPUnit são executados a partir da raiz; Electron usa
`nativephp/electron/` e `APP_PATH` aponta para a raiz Laravel.

Nunca use credenciais de produção, repositórios privados de terceiros nem
bancos reais de um usuário em testes. A suíte utiliza perfis sintéticos,
SQLite efêmero e transportes HTTP simulados. Ao debugar, não exponha dados
de navegação, chats ou tokens em logs e comentários de PR.

Mais informações: [Guia de início](GETTING_STARTED.md),
[Homologação](HOMOLOGATION.md), [Privacidade](../PRIVACY.md),
[Segurança](../SECURITY.md) e [Contribuição](../CONTRIBUTING.md).
