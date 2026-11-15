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
| NativePHP/Electron | `php-app/nativephp/electron/` | Instalado; bundle Electron JavaScript compilado; empacotamento final pendente |
| Ícone desktop de avaliação Linux/KDE | `php-app/desktop-preview/` | Janela Electron local e atalhos instalados; usa backend PHP existente, **não** é instalador autônomo |
| Rotina PHP de avaliação | `automatic-kimai:sync`, `SyncEngine` | Leitura e relatório, publicação autônoma bloqueada |

### Comparação com o Python

O identificador de sessão, hashes de evidência, horário de fim de dia e digest de declaração manual são comparados com vetores produzidos pelo Python original. Uma comparação com cache real **já verificou 19 sessões dos dias 11 a 13/11** com os mesmos identificadores, limites e hashes. O dia em andamento pode mudar enquanto a coleta Python atualiza o cache; só comparar snapshots congelados.

A API do Kimai foi consultada via PHP com a credencial existente em um contêiner efêmero, somente leitura, sem gravar a credencial no repositório PHP.

## Usabilidade e conexão Nextcloud (14/11/2026)

A interface Laravel foi repartida em **11 áreas de finalidade única**, com menu lateral e menu móvel: visão geral; revisão de períodos; trabalho presencial; registro manual; Kimai; fontes; conexões; Nextcloud; Telegram; GitHub; migração/segurança. Os formulários de registro e de credenciais não são mais apresentados juntos na mesma tela. A preferência de aparência continua seguindo o sistema até a primeira escolha manual por ícone de sol/lua.

**Autorização Nextcloud recomendada — Login Flow v2:**

1. O usuário informa somente a URL HTTPS do Nextcloud (ou escolhe uma sugestão do arquivo de configuração do cliente desktop, quando disponível no ambiente nativo). **Nenhum segredo do cliente desktop é importado.**
2. O PHP solicita ao servidor /index.php/login/v2 um link de autorização e token de consulta temporário. O usuário abre o link **no navegador padrão**, que pode reutilizar a sessão web já autenticada, e aprova o acesso na própria página Nextcloud, com 2FA quando exigido.
3. O PHP consulta apenas a URL de polling autorizada pelo mesmo servidor, aceita HTTP 404 enquanto aguarda, valida a identidade pela API OCS e armazena a senha de aplicativo individual criptografada, separando o nome de login do UID CalDAV. O token de polling fica somente em arquivo local 0600 com prazo de 20 minutos; não é enviado ao browser.
4. Agenda e Talk começam **desativados por padrão** até consentimento separado. O Nextcloud pode revogar a senha por dispositivo na página Segurança.

O fallback avançado de senha de aplicativo é validado pela API antes do armazenamento. TLS verificado, sem redirecionamentos HTTP, endpoints da negociação limitados à origem informada e ao caminho esperado. O CalDAV foi corrigido para instalações em subdiretórios.

Documentação oficial consultada:
- https://docs.nextcloud.com/server/stable/developer_manual/client_apis/LoginFlow/index.html
- https://docs.nextcloud.com/server/stable/admin_manual/configuration_user/authentication.html
- https://docs.nextcloud.com/server/stable/user_manual/pt_BR/session_management.html

**Escopo comprovado:** testes PHP com servidor simulado, testes de fronteiras e validação da interface em navegador headless nas larguras 375/768/1280/1920 px. **Ainda pendente:** autorização real feita pelo usuário, 2FA real, credencial individual na instância de produção e testes CalDAV/Talk com essa identidade. A conexão não acontece silenciosamente sem clique e autorização do usuário.

**Atenção à paridade:** nova navegação e novo login não significam que a versão PHP substitui o Python. O SyncEngine PHP ainda bloqueia publicação automática, e as rotinas Python de áudio, histórico Telegram, revisão/aprovação, telemetria e publicador precisam de equivalência e provas de migração em ambiente real. O aviso de migração foi movido para área discreta, mas a restrição continua ativa.

## Correção de falha de monitoramento e validação de paridade (15/11/2026)

- **Falha real diagnosticada:** o perfil de avaliação PHP tinha as fontes Agenda/Talk habilitadas, mas não tinha credencial de aplicativo Nextcloud no armazenamento PHP separado. O ciclo de 15/11 à 00h falhou com RuntimeException enquanto o Python continuou independente. Não se deve copiar automaticamente credenciais privadas do piloto Python.
- **Correção:** fontes opcionais sem credenciais passam a needs_connection, erros transitórios a unavailable; as sugestões e os registros não reutilizam evidências antigas de Calendar/Talk/GitHub/Telegram quando a fonte não é autorizada e verificada no ciclo. A página inicial oferece link explícito para conectar Nextcloud. Testes de regressão simulam ausência de credenciais, conteúdos de caches antigos e falha de autorização.
- **Verificação de runtime:** um ciclo PHP real, ainda **somente leitura**, encerrou PHP_CYCLE success remote_writes=0 reviews=10; o automatic-kimai-php-shadow.service foi executado novamente via systemd com Result=success, ExecMainStatus=0; timer preservado.
- **Paridade calculada em dados reais:** evidências sanitizadas e referências Python para os dias 11, 12 e 13/11 produziram 7+7+5 = **19 sessões** no PHP com zero divergências nas projeções comparadas (id, início, fim, durações de trabalho/contexto desconhecido, lacunas e hash de evidência). Comparação foi realizada com snapshot temporário restrito, removido após teste. Não equivale a validar escrita, aprovação, histórico de mídias nem todos os serviços.
- **Navegador padrão no Electron:** o processo principal agora abre links externos HTTPS usando shell.openExternal e impede que navegações externas substituam a janela local; isso permite reutilizar a sessão web do navegador padrão durante Login Flow v2. APP_PATH apontando para php-app e npm run build compilou a ponte Electron; não foi homologado o instalador final.
- **Status de migração:** o Python segue ativo para publicação legítima; o PHP permanece shadow. Não habilitar POST/PATCH, não desligar timers Python, não mesclar PR como versão final. A homologação de todas as fontes, aprovação/ledger, migração de pendências, retomada e distribuição nativa é exigida para paridade integral.

## Hierarquia da interface e visibilidade por fonte (15/11/2026)

- Menu recolhível com três níveis de indentação: Trabalho; Fontes de dados → Conexões habilitadas → serviços; Preferências. No desktop fica compacto com ícones e títulos acessíveis, com preferência local persistida; no celular abre/fecha por botão único.
- A seleção de Fontes de dados determina a exibição das conexões. **Nextcloud** se Agenda, Talk, Activity ou Mail estiver ativo, ou enquanto existir uma conta vinculada, **GitHub** só se ativo, **Telegram** só com pedido explícito de configuração ou scanner autorizado. URLs de configuração desabilitadas retornam à tela de fontes; endpoints de autenticação Nextcloud e Telegram bloqueiam chamadas quando a fonte não está selecionada.
- O flag telegram_setup separa **desejo de configurar** do estado operacional telegram (leitura autenticada após QR/2FA). Desabilitar telegram_setup cancela coleta e oculta sua conexão; as sessões antigas permanecem guardadas localmente. Perfis legados com Telegram ativo continuam encontrando a configuração.
- O botão Conectar só aparece depois que o usuário salva uma fonte selecionada. Credenciais individuais permanecem criptografadas, mas não são reutilizadas por fontes desabilitadas, nem revogadas sem ação do usuário.
- Substituídos os símbolos de marca, conexão, setas e tema desenhados manualmente por 17 ícones **Lucide Static 1.72.0** em SVG local, com licença ISC. Nenhuma dependência de rede ou fonte proprietária.
- Correção adicional encontrada na verificação visual: status do monitoramento antes exibia tokens do template após Atualizado; corrigidos o estado e a data/hora para formato brasileiro (dd/mm/aaaa HH:mm). Links visitados receberam cor consistente com tema claro/escuro.
- Testes de regressão cobrem estados de fonte isolados, ausência de conexão, proteções contra autenticação de fonte desligada, URLs diretas, legado Telegram, licenciamento dos ícones e status legível. Navegador headless verificou menu compacto, persistência, hierarquia e responsividade em quatro larguras.
- **A edição/aprovação de registros e o publicador PHP seguem pendentes; runtime PHP permanece shadow read-only.** A melhoria de interface não elimina bloqueios de migração.

## Identidade visual e onboarding do Telegram (15/11/2026)

- Substituídos símbolos genéricos para os produtos Nextcloud, Telegram e GitHub por logotipos vetoriais correspondentes do **Simple Icons 16.37.0** (licença e ressalvas de marcas incluídas); Lucide permanece apenas para a interface genérica.
- Telegram agora começa pela **autenticação QR/2FA**. Os campos de API só são expandidos quando necessários. O seletor de grupos fica invisível até que a sessão Telegram seja verificada.
- Após o login, a lista mostra somente grupos/supergrupos, não mensagens, contatos privados nem canais; o usuário pode buscar e selecionar até 40 grupos. Uma API local com validação de sessão fornece a lista e recebe as escolhas.
- A configuração de escopo grava IDs exatos, nunca apenas correspondência aproximada de títulos, evitando que dois grupos homônimos sejam confundidos. Gravação de novas seleções suspende a coleta até que o usuário a reative explicitamente. Perfis legados por nome mantêm compatibilidade até que o usuário selecione grupos.
- O QR Code, as credenciais de API e a lista de grupos são **alterações/leituras locais**, mesmo enquanto o PHP é somente leitura para **publicação de horas no Kimai**. A interface explica essa distinção.
- Testes com clientes Telegram simulados garantem filtragem de grupos, seleção por ID, rejeição de IDs desconhecidos ou repetidos, ausência de consulta a mensagens durante o catálogo, bloqueio de endpoints antes do login e não ativação implícita.
- **Pendente:** homologação real do QR/2FA e da listagem de grupos com conta autorizada e cenário distribuído Windows/macOS/Linux, sem habilitar publicação de horas.

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

## Recuperação da inicialização local do desktop (15/11/2026)

O atalho Automatic-Kimai.desktop parou de abrir porque o servidor PHP
respondia HTTP 500. A análise de storage/logs/laravel.log identificou falha
de leitura de storage/app/private/automatic-kimai/sources.json, com modo
0600 e proprietário root:root. O teste de renderização dos ícones invocava
LocalStore sem injetar o diretório de teste, escrevendo esse arquivo no
armazenamento real quando PHPUnit era executado como root em Docker.

Ação local: a propriedade do único arquivo afetado foi restaurada ao
UID/GID do usuário, preservando o modo 0600 e sem tocar nas credenciais,
sessões Telegram, bancos SQLite ou serviços Python. O endpoint web voltou a
HTTP 200 e o launcher passou --check (Electron e backend loopback prontos).

Correção permanente no código: Tests\TestCase injeta LocalStore e cache
de views Blade temporários em todos os testes de interface; a regressão
de ícones verifica que não está usando a pasta real. As views compiladas
que estavam sob propriedade root também foram limpas com o UID normal
da aplicação e regeneradas em leitura HTTP, sem afetar dados privados.
A suíte de 99 testes passa sem alterar hash, UID/GID ou permissões do
sources.json do desktop.
Limite da validação: a inicialização da interface gráfica pelo duplo
clique deve ser confirmada pelo usuário, pois a checagem remota foi realizada
sem abrir janelas ou assumir controle de mouse/teclado.

## Auditoria de credenciais Telegram e hardening (15/11/2026)

- Inspecionados os objetos e caminhos do histórico Git local, as credenciais
  reais descriptografadas exclusivamente no próprio computador e os logs
  disponíveis. Nenhuma correspondência exata do hash de API foi encontrada
  em arquivos versionados, em logs locais ou ativos públicos do Laravel.
- O ID/hash identificam a aplicação Telegram, mas não substituem o login
  individual. As sessões MadelineProto são materiais de autenticação
  sensíveis e precisam ficar fora do Git, de qualquer instalador e dos
  uploads de suporte.
- Os valores são mantidos no arquivo secrets.enc criptografado pelo
  Laravel com chave privada da instalação; a sessão MTProto é armazenada
  separadamente em diretório com permissão 0700, arquivos 0600.
- Reproduzido e corrigido vazamento potencial do campo telegram_api_hash
  para a sessão de formulário quando uma validação HTTP falhava; definido
  dontFlash para os campos de tokens e senhas de integrações.
- Adicionada permissão 0600 aos arquivos de log Laravel (single e daily);
  o log local existente também foi restringido.
- O filtro de pacotes do NativePHP já removia TELEGRAM_*, APP_KEY e
  storage/app/private. Um guard independente agora interrompe a etapa
  Electron beforePack se segredos, sessões ou logs persistentes ainda
  estiverem presentes; testes Node com valores fictícios integram a CI.
- O código continua exigindo que cada instalação forneça credenciais
  de aplicação próprias. Nenhuma credencial pessoal do usuário foi gravada
  no código e nenhuma chave real foi transmitida para os testes.
- Os testes validam dados temporários do Laravel, exclusões NativePHP,
  cabeçalhos de endpoints sensíveis e proteção dos artefatos.
- Escopo não verificado: integridade de instaladores antigos já distribuídos
  e varredura de backups/arquivos externos não montados. Requer auditoria
  específica caso existam instaladores publicados anteriormente.

## Otimização da lista de grupos Telegram (15/11/2026)

- Troca de getFullDialogs() por messages.getDialogs com limite de 30 diálogos
  brutos por página e metadados de grupos já presentes na resposta.
- Paginação incremental pela rolagem ou clique, busca local sobre páginas
  carregadas e seleção persistente ao percorrer páginas.
- Cursores criptografados, específicos da conta e expiração de 20 minutos;
  uma resposta que não avança o cursor resulta em erro controlado.
- Comunidades @LibreCodeCoop e @LibreSign verificadas pontualmente como
  supergrupos dos quais a conta participa, sugeridas como itens editáveis,
  mas sem ativar coleta ou publicar horas.
- Seleção final validada por ID de diálogo, sem reenumerar todos os grupos.
- Testes de 30/60 itens, catálogo simulado grande, busca, persistência de
  marcações, cursor inválido e sugestões com username verificado passaram.
- Pendente: testes de latência com conta real e comportamento do Telegram
  sob volume, sem consultar conteúdo de chats sem aprovação explícita.


## Recuperação do catálogo Telegram e fidelidade funcional (15/11/2026)

- Corrigida falha da primeira página: Telegram devolvia 32 diálogos para um
  limite de 30 devido a conversas fixadas e a validação PHP rejeitava a
  resposta. O parser agora permite fixados extras com limite defensivo.
- MadelineProto devolve os identificadores de peer e de chat normalizados
  como inteiros (negativos para grupos), enquanto os testes anteriores só
  simulavam os construtores TL em array. O parser aceita ambos e preserva o
  cursor pelo último diálogo não fixado.
- O navegador mostra erros HTTP e opção de nova tentativa, encerrando a espera
  com falha visível após 15 segundos se não houver resposta.
- Testes reais restritos ao catálogo Telegram autorizado: HTTP 200, cerca de
  0,47 s, 28 grupos na primeira página e 16 grupos distintos na segunda.
  Nenhum corpo de mensagem foi inspecionado; o scanner e a publicação
  continuam desativados.
- Interpretação de requisitos: a meta de homologação é **100% das
  funcionalidades e comportamentos operacionais** existentes no piloto
  Python, não igualdade byte a byte de toda saída intermediária. Mudanças que
  corrijam defeitos de performance, cobertura ou segurança são desejadas
  quando preservam o resultado funcional necessário e têm testes.

## UX dos grupos Telegram e pastas nativas (15/11/2026)

- Seleção de grupos no topo, com movimentação imediata ao marcar/desmarcar,
  busca local, ordenação por atividade recente ou nome e filtro entre pastas
  Telegram não arquivadas (folder_id=0, padrão) e arquivadas (folder_id=1).
- Seleção em memória preservada entre páginas e filtros, sem tocar nas
  autorizações já salvas até que o usuário confirme explicitamente.
- LibreCode e LibreSign sugeridos por identidade pública e participação
  MTProto verificadas; aparecem pré-marcados inclusive em perfis antigos com
  outros grupos já aprovados. A preferência de sugestões revisadas impede
  reautorização silenciosa após salvar ou desmarcar. Não ativa coleta nem
  publicação de horas.
- Cache privado dos nomes por ID exato de grupo para compor a área superior
  sem percorrer todos os diálogos a cada abertura. Migração compatível com
  perfis antigos e opção de salvar seleção vazia para desabilitar coleta.
- Cursores criptografados agora vinculados também à pasta; regressões de
  grupos ativos/arquivados, retorno de itens fixados e IDs preexistentes.
- Teste de interface isolado confirmou seleção, ordenação, busca, troca de
  pasta, lazy loading, descarte de uma sugestão e salvamento fictício sem
  iniciar coleta. Teste read-only em sessão Telegram real confirmou duas
  comunidades oficiais marcadas e pastas separadas.

## Autorização Nextcloud no desktop Electron (15/11/2026)

- Corrigido o botão Abrir Nextcloud para autorizar na prévia desktop:
  Electron negava todos os eventos target=_blank, inclusive a URL de
  LoginFlow v2 validada e devolvida pelo backend PHP.
- O processo principal continua impedindo janelas externas e navegação
  fora do localhost, mas pode chamar o navegador padrão do sistema após
  verificar o URL HTTPS exato contra a autorização pendente no PHP local.
- A consulta de confirmação possui timeout, não abre URLs arbitrárias e
  não registra tokens de LoginFlow. Nenhuma credencial foi reutilizada ou
  concedida automaticamente durante a investigação.
- Testes Node simulam a verificação e o abridor do SO (incluindo URLs
  não autorizadas, erro de rede e múltiplos cliques), sem mexer no mouse
  ou abrir abas reais do usuário.

## Conta Nextcloud e coletores Activity/Mail (15/11/2026)

- A tela da conexão mostra a conta e o servidor vinculados. O nome visível
  vem da API OCS do **próprio usuário autenticado**, com validação de UID, e
  o avatar é obtido por proxy local restrito a PNG/JPEG/WebP para impedir
  que o Electron carregue páginas ou SVG de uma origem externa. Sem rede,
  permanece o nome de login e a inicial do usuário como alternativa.
- **Entrar com outra conta** apenas revela o fluxo de autorização.
  **Desconectar deste computador** exige confirmação e elimina as credenciais
  Nextcloud criptografadas e a autorização pendente no armazenamento local,
  preservando Kimai, GitHub, Telegram e as preferências das fontes.
  Isso **não revoga automaticamente** a senha de aplicativo no servidor:
  o usuário deve invalidá-la em Nextcloud → Segurança → Dispositivos e sessões.
- Agenda, Talk, Activity e Mail possuem **switches independentes** na
  própria página Nextcloud, salvos por requisição local autenticada com CSRF.
  Cada switch altera somente sua fonte; não muda preferências de outro serviço.
  Activity e Mail ficam **desativados por padrão** até consentimento explícito.
- Activity lê o endpoint OCS v2 documentado com filtro `actor` do usuário
  autenticado, interpreta `datetime` (o campo atual da resposta OCS)
  em páginas limitadas de até 100 eventos (máximo 5 páginas), preservando
  apenas horários e tipos de evento. Não grava assunto, caminho ou nome
  de arquivo. Metadados do Activity podem incluir operações automáticas
  (sincronização de arquivos); não equivalem a trabalho humano comprovado.
- Mail consulta **somente leitura** as rotas internas do aplicativo Mail
  para identificar pastas Enviados das contas **não delegadas** e suas
  mensagens recentes. Persistem apenas timestamps de envio, nunca assunto,
  corpo, prévia, remetentes ou destinatários. Limite de 10 contas e
  100 mensagens por pasta; resultados incompletos ou ausência de conta são
  sinalizados e não são convertidos em horas automaticamente.
- As rotas do Mail são internas ao aplicativo, sem contrato público estável
  comparável ao OCS Activity. Compatibilidade e retorno de dados em
  Nextcloud/Mail reais permanecem pendentes de teste explícito com consentimento.
  Falhas produzem status indisponível/parcial, sem reutilizar contexto antigo.
- Os eventos das novas fontes são **âncoras fracas para revisão**, nunca
  certificados de duração ou permissão para POST/PATCH no Kimai. O PHP continua
  em shadow read-only e o Python original permanece ativo.
- Testes automatizados exercitam isolamento por fonte, datas, permissão da
  própria conta, privacidade, falhas de rede e a desconexão local. A interface
  foi verificada em Chromium headless em quatro larguras; o perfil e o avatar
  da conta já vinculada responderam HTTP 200 no servidor autorizado, mas as
  novas coletas Activity/Mail não foram executadas contra dados reais.

## Identidade e busca Telegram, comunidades nativas e conta GitHub (15/11/2026)

- **Telegram:** a tela conectada apresenta nome, username e avatar (imagem
  pequena JPEG/PNG/WebP, sem foto ou telefone persistidos). Em sua ausência,
  exibe a inicial. O botão **Desconectar conta Telegram** requer confirmação,
  desativa imediatamente a coleta, executa a revogação MTProto e só após seu
  sucesso limpa a seleção de grupos e os metadados locais da conta. Mantém
  ID/hash do aplicativo (não identificam o usuário) e outras integrações.
  A revogação é uma ação do usuário: não é acionada no carregamento da tela.
- A busca usa um **catálogo temporário na memória da página**: ao pesquisar,
  exibe primeiro os grupos já conhecidos e continua consultando em segundo
  plano as pastas normais e arquivadas, alternadamente, a uma página MTProto
  de até 100 diálogos por requisição. Alterar o termo filtra os dados já
  consultados, sem reiniciar a paginação. Cursores criptografados são
  vinculados à conta e à pasta, não à pesquisa. A interface consulta até
  doze páginas por pasta automaticamente; em contas grandes informa o
  resultado parcial e permite continuar mediante ação explícita.
  As chamadas usam POST local com CSRF, sem nomes pesquisados na URL;
  não há leitura de histórico de mensagens nem persistência de títulos
  não autorizados fora da memória da página.
- **Comunidades Telegram nativas** são distintas de pastas de conversas
  e de grupos com tópicos. Com base no Telegram Desktop upstream e no TL
  da versão instalada do MadelineProto, a interface lê comunidades vinculadas
  e seus pares communityFull.linked_peers, com visualização expansível e seleção
  individual dos grupos elegíveis. **Selecionar os grupos da comunidade**
  seleciona somente chats/grupos a que a conta tem acesso, jamais bots,
  canais de transmissão, membros ausentes ou a comunidade como um histórico
  fictício. Salvar uma seleção ainda suspende a coleta até a ativação
  explícita, como antes.
- **GitHub:** com token salvo, a interface consulta o endpoint autenticado
  /user para exibir nome e login da conta. O avatar é servido pelo proxy
  local a partir de avatars.githubusercontent.com/u/ID, validado e sem
  enviar a chave ao CDN. Quando indisponível, exibe a inicial e marca
  identidade não verificada. **Desconectar deste computador** apaga apenas
  o token local do GitHub, preservando outras integrações; revogar o token
  no servidor exige ação na conta GitHub.
- As novas chamadas de descoberta e imagem são restritas ao localhost,
  com operações somente leitura (GET ou POST com CSRF) e limites de requisição,
  inclusive por quantidade
  de páginas, grupos e tamanho de imagem. Não há importação de credenciais
  pessoais para código ou builds de distribuição.
- Testes com fakes cobrem o catálogo paginado de uma RPC por requisição,
  grupos arquivados, escopo de cursores, comunidades elegíveis, identidade
  sanitizada, avatar e falha/revogação. Chromium headless validou seleção
  de comunidade e busca em dois tamanhos de tela com APIs simuladas.
  **Ainda pendente homologar com uma comunidade Telegram real.**
  Nenhuma sessão Telegram real foi desconectada durante o desenvolvimento.
  O PHP segue em modo shadow somente leitura, sem publicação de horas.

## Correção do seletor de grupos Telegram (15/11/2026)

- Os checkboxes não são mais transferidos fisicamente entre painéis.
  Ao marcar um grupo, a opção desaparece do painel **Disponíveis** e
  aparece como item distinto no painel **Selecionados**, com botão Remover.
  A remoção devolve a opção ao painel de origem. Os dois painéis ficam
  lado a lado em desktop e empilhados em telas menores. A reconciliação
  incremental preserva os nós dos demais checkboxes durante a paginação.
- O limite de 40 grupos era silencioso: ao tentar o 41º, o checkbox voltava
  para desligado e o aviso surgia abaixo do catálogo. O limite agora é 100,
  centralizado na validação do perfil, serviço, endpoint e UI. Há contagem
  visível e aviso imediatamente ao lado da opção recusada; desmarcar um grupo
  permite selecionar outro. Nada é salvo automaticamente.
- Para não expandir indiscriminadamente a coleta de mensagens, o histórico
  por grupo mantém 600 itens para até 40 grupos e diminui proporcionalmente
  em listas maiores (máximo 24.000 itens por varredura, 240/grupo com 100).
  A análise continua como metadados somente leitura, sem publicar horas.
- A regressão foi reproduzida em Chromium com 45 itens: a primeira seleção
  movia fisicamente o checkbox para outro bloco e o 41º clique falhava sem
  retorno visível. Após a alteração, os checkboxes permaneceram no catálogo,
  100 grupos foram selecionados, o 101º recebeu aviso em linha e a remoção
  de um item permitiu selecionar novamente. Também houve teste de marcar e
  desmarcar um grupo com a sessão local autenticada, **sem POST ou alteração
  persistida** na seleção do usuário.

## Otimização de buscas Telegram e painéis de seleção (15/11/2026)

- **Problema de desempenho:** o antigo endpoint de busca abria a sessão
  MTProto para verificar a autorização e executava até cinco chamadas
  `messages.getDialogs` de 100 diálogos por requisição. O JavaScript
  repetia a varredura inteira de duas pastas a cada mudança de busca,
  chegando potencialmente a cem chamadas MTProto para um único termo.
- **Correção:** o endpoint local POST /telegram/catalog retorna uma única
  página MTProto de até 100 diálogos (mais os eventuais fixados). Não
  revalida a sessão por uma segunda conexão: o adaptador já valida
  `getSelf` ao acessar a conta. Cursores criptografados são vinculados
  à conta autenticada e à pasta. Nenhum histórico de mensagens é consultado.
- O catálogo fica **somente na memória da página**: os grupos da primeira
  página e as comunidades já conhecidas aparecem imediatamente; outras
  páginas são consultadas progressivamente, alternando entre arquivados
  e não arquivados. Digitar outro termo refiltra as mesmas entradas sem
  reiniciar a paginação. A consulta é interrompida se o campo ficar vazio,
  pode ser retomada pelo cursor e informa o progresso ou erro de forma clara.
  O orçamento automático é de 12 páginas por pasta; mais páginas exigem a
  opção **Continuar busca**, para limitar custo e risco de flood.
- **Seleção:** os painéis Disponíveis e Selecionados são visualmente
  independentes, lado a lado no desktop, empilhados no celular. Um clique
  retira o item da lista disponível e cria um item de seleção separado,
  com ação Remover. A remoção restitui o grupo à lista original. Os
  controles da lista não são movidos fisicamente entre painéis; isso
  evita o bug de seleção que ocorria ao trocar de posição durante o clique.
- **Evidência:** consulta única ao endpoint novo com uma sessão Telegram
  autorizada retornou HTTP 200 após 813 ms para 100 diálogos,
  47 deles grupos elegíveis. Não é promessa de latência total de indexação.
  Chromium com respostas simuladas confirmou a busca em ambas as pastas,
  filtragem instantânea após mudar o termo, nenhuma requisição duplicada
  por pesquisa, a interrupção no limite e a continuação manual. Também
  confirmou marcar/remover grupo com a sessão real: sem POST, sem alterar
  grupos previamente salvos nem publicar horas.
