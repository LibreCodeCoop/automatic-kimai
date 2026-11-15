# Automatic Kimai

Ferramenta desktop local, desenvolvida em **PHP 8.4+, Laravel 12 e NativePHP/Electron**,
para analisar atividades e conciliar períodos com lançamentos da conta pessoal
em [Kimai](https://www.kimai.org/).

A ferramenta não presume empresa, cliente, servidor ou projeto específico.
Cada pessoa conecta sua própria instância Kimai e escolhe quais fontes deseja
autorizar: ActivityWatch, Nextcloud, GitHub e Telegram.

> **Estado:** código-fonte público em desenvolvimento (alpha). A interface
> e a análise PHP estão disponíveis; a ativação da publicação automática,
> os instaladores multiplataforma e a substituição de instalações antigas
> requerem validação operacional independente. Não trate atividade de tela,
> mensagens ou eventos agendados como comprovação automática de horas.

## Funcionalidades

- Contexto de atividade local e períodos de inatividade (AFK), com exclusão de
  informações privadas e tratamento explícito de lacunas de observação.
- Integrações opcionais, individualmente autorizadas, para ampliar evidências.
- Conciliação com horários existentes no Kimai sem duplicar registros.
  Períodos sem duração de trabalho comprovada são mantidos como observações,
  sem criar horas fictícias ou exigir revisão manual de cada indício.
- Registros de trabalho presencial e histórico local protegido por SQLite.
- Outbox idempotente, verificação da identidade e controles para impedir dois
  publicadores concorrentes durante uma migração.

## Desenvolvimento local

Requisitos: PHP 8.4+, Composer 2, extensões cURL, DOM, fileinfo, mbstring,
OpenSSL, SQLite, XML e ZIP. Node 22+ é necessário para o frontend Electron.
O [ActivityWatch](https://activitywatch.net/) é opcional no primeiro acesso.

Execute **a partir da raiz do repositório**:

```sh
composer install --no-interaction
cp .env.example .env
chmod 600 .env
php artisan key:generate
php artisan package:discover
php vendor/bin/phpunit
php artisan serve --host=127.0.0.1 --port=6817
```

Acesse http://127.0.0.1:6817 **no próprio computador**. Não exponha o serviço
à rede pública. Configure um token individual da API Kimai no aplicativo.

Para prévia do desktop no Linux, com o backend PHP local preparado:

```sh
./desktop-preview/launch.sh --check
./desktop-preview/install-desktop.sh
```

Para desenvolver com NativePHP/Electron:

```sh
php artisan native:install --force --publish
php artisan native:run
```

Essa prévia não substitui a homologação de instaladores autônomos em Linux,
macOS e Windows.

## Qualidade e segurança

```sh
php vendor/bin/phpunit --colors=never
php scripts/check-publication.php
node --test desktop-preview/*.test.cjs
cd nativephp/electron
npm ci --ignore-scripts --no-audit --no-fund
npm run plugin:build
npm run build
```

Nunca versione `.env`, tokens, dados de atividade, sessões Telegram, bases
SQLite privadas ou relatórios contendo informações pessoais. Os testes usam
fontes sintéticas e armazenamento isolado. A escrita remota no Kimai permanece
protegida por consentimento explícito e validação de identidade e histórico.

## Documentação

- [Guia de início](docs/GETTING_STARTED.md) — conexão e uso da interface.
- [Desenvolvimento](docs/DEVELOPMENT.md) — arquitetura local, execução e testes.
- [Homologação](docs/HOMOLOGATION.md) — cenários de validação.
- [Checklist de release](docs/RELEASE_CHECKLIST.md) — requisitos de distribuição
  e da troca de uma instalação anterior.
- [Privacidade](PRIVACY.md), [Segurança](SECURITY.md),
  [Contribuição](CONTRIBUTING.md), [Código de conduta](CODE_OF_CONDUCT.md)
  e [Licença GPL-3.0](LICENSE).

Automatic Kimai é um projeto independente dos serviços integrados; as marcas
de terceiros identificam apenas integrações opcionais.
