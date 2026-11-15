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

## Abrir como aplicativo desktop Electron no Linux/KDE

A interface também pode ser aberta como **aplicativo desktop independente do navegador**, com ícone no menu do KDE e atalho na área de trabalho. A janela é fornecida pelo Electron e mantém a aplicação Laravel PHP por trás, sem incluir segredos no binário.

No computador de avaliação já configurado:

```sh
./desktop-preview/launch.sh --check
./desktop-preview/install-desktop.sh
```

Depois, abra **Automatic Kimai** pelo menu de aplicativos ou pelo ícone da área de trabalho. Não é necessário abrir o navegador. Se o backend PHP local estiver parado, o lançador tenta reiniciar somente seu contêiner de avaliação; o piloto Python não é afetado. Consulte [desktop-preview/README.md](desktop-preview/README.md) para detalhes e limitações.

**Não confundir com uma release instalável:** este é um inicializador Electron para o backend local PHP já instalado. Um instalador independente NativePHP/Electron, com PHP embutido e chaves individuais, ainda exige a auditoria de empacotamento. Não distribuir como release a outros cooperados.

## Configurar Telegram pelo navegador (ambiente de avaliação)

1. Abra **http://127.0.0.1:6817/#telegram-setup** **no mesmo computador** onde o aplicativo está rodando.
2. Na seção **Telegram — autenticação MTProto**, verifique se as credenciais pessoais da API já estão configuradas. Se estiverem, não precisa informá-las de novo. Caso contrário, obtenha o API ID e o hash em `my.telegram.org` (sua própria conta).
3. Informe os nomes exatos dos grupos/conversas profissionais que você autoriza consultar, separados por vírgula. Salve a configuração.
4. Clique em **Gerar QR Code**. No Telegram do celular, abra **Configurações → Dispositivos → Conectar dispositivo** e escaneie o QR Code. Se expirar, gere outro.
5. Se o Telegram solicitar a senha da verificação em duas etapas, digite-a **na página local**. Não envie essa senha a outra pessoa nem a este chat.
6. Quando aparecer **Telegram autenticado**, clique em **Ativar coleta Telegram em modo de revisão**. Apenas então o conector poderá consultar as conversas autorizadas. Não há gravação de horas no Kimai por esta etapa.

Este fluxo usa um **processo PHP separado** por até cinco minutos, mantém tokens de QR Code em arquivos privados (`0600`) e transmite a senha de duas etapas para esse processo usando armazenamento local criptografado temporário; o arquivo é eliminado assim que o comando é consumido. O mesmo login é conferido por uma segunda abertura da sessão antes de ser considerado válido.

**Validação realizada:** QR Code produzido por requisição HTTP real, reconsulta da sessão e testes de autenticação não iniciada/rotas externas. **Pendente:** conclusão real do login com 2FA pelo titular e verificação da leitura MTProto autenticada. A autenticação do Python anterior não foi invalidada. O modo de avaliação PHP permanece somente leitura e a publicação de horas continua no sistema existente.

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

Algoritmos de segmentação, lacunas, GET Kimai, proteção de escrita, ActivityWatch, Nextcloud Calendar/Talk, GitHub, Ollama e frontend Laravel têm implementação e testes. Telegram MTProto já oferece QR/2FA pela interface local mas depende da autenticação final pelo usuário; o publicador aprovado, o histórico de revisão e componentes do piloto precisam de paridade completa e testes de produção **somente leitura** antes de qualquer substituição do runtime antigo.

Este código é um **candidato de implementação** — não uma release e não uma autorização para remover os serviços Python ainda ativos.
