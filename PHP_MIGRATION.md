# Migração Python → PHP — Automatic Kimai

**Data da auditoria:** 14/11/2026
**Branch:** `feat/php-migration`
**Situação:** implementação PHP **em avaliação**, sem substituição do piloto em uso.
**Proteção de dados:** a versão Python continua registrando horas; o PHP está em execução local, **somente leitura**, na porta `127.0.0.1:6817` do servidor de desenvolvimento.

## Arquitetura pretendida

- PHP 8.4+, Laravel 12 e NativePHP Desktop 2 para interface, empacotamento Electron, permissões e menus.
- Núcleo PHP independente do frontend: `php-app/app/AutomaticKimai/`, sem invocação de Python.
- ActivityWatch local HTTP apenas em loopback, com AFK e sanitização de URLs/títulos.
- Integração HTTPS com Kimai, leitura por identidade, controle de sobreposição, SQLite de intents e reconciliação somente depois de GET.
- Nextcloud CalDAV/Talk, GitHub e classificações locais Ollama por ativação explícita do usuário.
- Fontes de terceiros e modelos nunca fornecem, isoladamente, duração humana remunerável.

## Código que já funciona em PHP

| Subsistema | Implementação PHP | Situação |
| --- | --- | --- |
| Perfil multicliente e fuso IANA | `Profile`, `TimeMath` | Testado com dias reais |
| Classificação, AFK, trabalho atravessando meia-noite | `ActivityWatchClient`, `Segmenter` | API real consultada, testes e paridade de 3 dias |
| Lacunas de trabalho, agenda e períodos para revisão | `Coverage` | Testes com agenda e timesheets sintéticos |
| REST Kimai e dados pessoais | `KimaiClient` | GET real validado, escrita somente por testes falsos |
| Snapshot, POST/PATCH, outbox idempotente | `Reconciler`, `Ledger` | Testes locais; **não homologado para POST em produção** |
| Declaração de tempo/presença | `ManualTime` | Testes; formulário read-only (prévia) |
| Nextcloud Calendar/Talk | `NextcloudSource` | Implementado; validação em servidor real pendente |
| Telegram MTProto | `TelegramSource`, `MadelineTelegramAdapter`, `TelegramLoginWorker`, `TelegramWebLogin` | QR real gerado e persistido entre chamadas HTTP; **2FA ainda exige conclusão pelo usuário e nova verificação** |
| Fila de revisão | `ReviewCandidateBuilder`, `ReviewQueue` | 15 candidatos de três dias comparados com Python; mesma identidade e limites. Não são aprovações |
| Estados SQLite do piloto | `StateMigrator` | Cópias consistentes de quatro bancos verificadas, **sem ativação como novo ledger** |
| Metadados de áudio Telegram | `TelegramAudio` | Testes de parsing/privacidade; serviço contínuo PHP não homologado |
| GitHub contextual | `GitHubSource` | Sem leitura de corpo de mensagem |
| Classificação via Ollama local | `LocalOllama` | Testes, não determina faturamento |
| Telemetria explícita de agente | `AgentRuns` | Testes, sem emissor automático integrado |
| Interface Laravel (HTML/CSS/Blade) | `AutomaticKimaiController`, `routes/web.php` | HTTP local 200, CSRF, testada |
| NativePHP/Electron | `php-app/nativephp/electron/` | Instalado; bundle Electron JavaScript compilado |
| Rotina PHP de avaliação | `automatic-kimai:sync`, `SyncEngine` | Leitura e relatório, publicação autônoma bloqueada |

### Comparação com o Python

O identificador de sessão, hashes de evidência, horário de fim de dia e digest de declaração manual são comparados com vetores produzidos pelo Python original. Uma comparação com cache real **já verificou 19 sessões dos dias 11 a 13/11** com os mesmos identificadores, limites e hashes. O dia em andamento pode mudar enquanto a coleta Python atualiza o cache; só comparar snapshots congelados.

A API do Kimai foi consultada via PHP com a credencial existente em um contêiner efêmero, somente leitura, sem gravar a credencial no repositório PHP.

## Bloqueio de segurança para empacotamento (detectado em 14/11)

Um teste local de `native:build` identificou que o NativePHP, por padrão, copia também `storage/app/private` e a chave `APP_KEY` do ambiente de desenvolvimento para o instalador. Essa cópia incluiu o perfil de avaliação e suas credenciais criptografadas. **Os instaladores de teste não foram publicados**; os arquivos compilados e o diretório de estágio foram imediatamente apagados, preservando o armazenamento local original.

A proteção agora faz o comando `native:build` recusar qualquer diretório que contenha perfis privados ou uma `APP_KEY` preenchida, e a configuração de empacotamento exclui `storage/app/private`, logs e dados de sessão. Testes de regressão confirmam o bloqueio. **Não distribuir binários** antes de implementar geração de chave **individual por instalação**, armazenamento em chaveiro do sistema e auditoria do pacote final. O build JavaScript da ponte Electron continua possível sem dados pessoais.

## Componentes do piloto ainda não substituídos

**Esta lista bloqueia a desativação do Python e a mesclagem como versão definitiva.**

| Serviço Python em uso | Responsabilidade ainda a migrar/homologar |
| --- | --- |
| `automatic-kimai-collect` | Comparação integral da coleta histórica e retomada após inatividade |
| `automatic-kimai-github-activity` | Equivalência com todas as ações da conta e metadados históricos |
| `automatic-kimai-nextcloud-talk` | Testes CalDAV/Talk reais, inclusão de exceções e ações próprias |
| `automatic-kimai-calendar` | Regras completas de classificação e recorrência |
| `automatic-kimai-telegram-scan` | Conector PHP e login visual QR/2FA implementados; autenticação persistente do novo dispositivo e equivalência de histórico ainda precisam ser validadas. Telethon permanece ativo |
| `automatic-kimai-chat-ai` | Classificação de mensagens selecionadas sem preservar texto; Ollama existe mas depende do conector MTProto |
| `automatic-kimai-enrich` | Verificação ponta a ponta de PR/issue no GitHub e cache de inferências |
| `automatic-kimai-sessions` | Candidatos e fila em PHP, com paridade de três dias; migração das 101 sessões históricas e dos estados de aprovação ainda não foi aplicada definitivamente |
| `automatic-kimai-approved-publisher` | Migração do publicador aprovado, outbox e atos de confirmação; **nunca executar dois publicadores simultâneos** |
| `automatic-kimai-refresh` | Atualização periódica e reconciliação de referências de clientes/projetos |
| `automatic-kimai-daily-agent` | Agendamento nativo do substituto com mesmo escopo de trabalho, sem duplicação |
| `automatic-kimai-telegram-audio` | Algoritmo PHP de observação e agrupamento testado; agendamento contínuo no PHP ainda não ativado |

Não remover timers, não excluir o ambiente anterior e não reutilizar credenciais pessoais em builds de distribuição antes de homologar cada serviço. A API pode conter entradas feitas manualmente por outros clientes, que nunca devem ser sobrescritas.

## Procedimento necessário para cutover

1. Concluir e testar os componentes de cima, com integrações reais autorizadas, **sempre em modo leitura**.
2. Congelar uma cópia privada das evidências e do SQLite do piloto; comparar candidatos PHP/Python, aprovações e estados pendentes.
3. Obter zero intents remotas desconhecidas no ledger Python e verificar que cada entrada aprovada tenha mesmo ID, usuário, cliente, projeto, atividade e duração no PHP.
4. Instalar o runtime PHP e seus agendadores nativos, com segredos protegidos e acesso somente local; validar reinício, hibernação, falhas de rede, retentativas e segurança.
5. Parar e **desabilitar timers Python somente depois** de comprovar paridade. Não deixar dois publicadores concorrentes.
6. Executar no PHP a primeira coleta e reconciliação com GET. Verificar idempotência e logs antes de permitir POST/PATCH.
7. Só então remover os arquivos Python da branch definitiva, substituir CI/README e preparar PR de merge para `main`, com rollback documentado.

**Estado atual:** ainda não atende aos critérios 1–7; a mudança de linguagem não justifica perda de funcionalidade ou apontamentos duplicados.

## Desenvolvimento e testes

```sh
cd php-app
composer install --no-interaction
cp .env.example .env
php artisan key:generate
php vendor/bin/phpunit
php artisan route:list
php artisan automatic-kimai:sync --status
php artisan native:install --publish
php artisan native:run
```

**No ambiente local de avaliação, não há PHP instalado diretamente no sistema.** Os testes rodam em contêiner PHP 8.4 e a interface local em `127.0.0.1:6817` num contêiner separado, sem afetar os serviços Python. Para construir a ponte Electron é necessário Node 22+. Os binários Windows/macOS não foram homologados.

A configuração de local e credenciais deve ser individual; nunca colocar tokens, arquivos `.env`, caches, sessões Telegram, arquivos de observação ou bancos SQLite no Git. O projeto utiliza GPL-3.0-or-later **como proposta de licença**, sujeita à autorização dos titulares antes de publicação pública. **Revisão jurídica de licença pendente:** MadelineProto 8.21.2 declara AGPL-3.0; confirmar obrigações do trabalho combinado e distribuição antes de disponibilizar o Automatic Kimai publicamente.
