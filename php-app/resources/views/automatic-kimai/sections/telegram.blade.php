<section id="telegram-setup" aria-labelledby="telegram-heading">
<h2 id="telegram-heading">Conectar Telegram</h2>
<p class="muted">Conecte sua conta no celular usando o QR Code. Depois escolha os grupos de trabalho que o aplicativo poderá consultar.</p>
<div id="tg-connected-banner" class="connection-summary" hidden>
<div class="connected-icon">@include('automatic-kimai.components.icon',['name'=>'circle-check'])</div>
<div><strong>Telegram conectado</strong><p class="muted">Escolha abaixo os grupos profissionais. Nenhuma conversa privada é selecionada automaticamente.</p></div>
</div>

<div id="tg-login-step">
<div class="onboarding-step">
<div class="step-heading"><span class="step-number">1</span><div><h3>Conectar sua conta</h3><p class="muted">No celular, abra Telegram → Configurações → Dispositivos → Conectar dispositivo.</p></div></div>
<p id="tg-status" role="status" aria-live="polite" class="muted">Verificando a conexão…</p>
<div id="tg-controls">
<button type="button" id="tg-start">Conectar pelo QR Code</button>
<button type="button" id="tg-refresh" class="secondary">Verificar conexão</button>
</div>
<div id="tg-qr-area" hidden>
<p><strong>Escaneie o QR Code com o Telegram no celular.</strong> O código expira e pode ser renovado.</p>
<img id="tg-qr-img" width="320" height="320" alt="QR Code temporário para conectar a conta Telegram">
<p id="tg-qr-expiry" class="muted"></p>
</div>
<form id="tg-2fa-form" hidden autocomplete="off">
<label for="tg-2fa-password">Senha de verificação em duas etapas</label>
<input id="tg-2fa-password" type="password" maxlength="1024" autocomplete="off">
<button type="submit">Concluir autenticação</button>
<p class="muted">A senha é enviada apenas ao processo local de login e não é armazenada como configuração permanente.</p>
</form>
</div>
<details id="tg-advanced-settings" class="advanced-settings">
<summary>Configuração de acesso à API do Telegram (quando necessária)</summary>
<p class="muted">O Telegram pode exigir um ID e um hash de aplicativo para criar o QR Code. Eles podem ser obtidos em <a href="https://my.telegram.org/apps" target="_blank" rel="noopener noreferrer">my.telegram.org/apps</a>. Não informe a senha principal do Telegram aqui.</p>
<p class="muted">Essas credenciais identificam um aplicativo, não autorizam sua conta sozinhas. O Automatic Kimai as armazena criptografadas somente neste dispositivo, sem incluí-las no código ou compartilhar com outras instalações. Trate o hash como confidencial.</p>
<form method="post" action="{{route('kimai.telegram.configure')}}">@csrf
<div class="setup-fields">
<div><label for="tg-api-id">ID da API</label><input id="tg-api-id" name="telegram_api_id" inputmode="numeric" pattern="[0-9]{4,13}" autocomplete="off" placeholder="{{ !empty($creds['TELEGRAM_API_ID']) ? 'Já configurado; deixe vazio para manter' : 'Obtido em my.telegram.org' }}"></div>
<div><label for="tg-api-hash">Hash da API</label><input id="tg-api-hash" name="telegram_api_hash" type="password" maxlength="32" autocomplete="off" placeholder="{{ !empty($creds['TELEGRAM_API_HASH']) ? 'Já configurado; deixe vazio para manter' : 'Hash de 32 caracteres' }}"></div>
</div>
<button type="submit" class="secondary">Salvar credenciais para conectar</button>
</form>
</details>
</div>

<div id="tg-groups-step" hidden>
<div class="onboarding-step">
<div class="step-heading"><span class="step-number">2</span><div><h3>Escolher grupos profissionais</h3><p class="muted">Apenas grupos e supergrupos são exibidos. Contatos individuais e canais de transmissão não entram na lista.</p></div></div>
<p class="muted">Marque os grupos que podem contribuir para a análise das horas. Os nomes são exibidos aqui somente após sua autenticação; selecionar grupos não consulta mensagens nem publica horas.</p>
<form id="tg-groups-form">
<div id="tg-selected-area" class="telegram-selected-area" hidden>
<div class="telegram-selected-heading">
<strong>Grupos selecionados <span id="tg-selected-count">0</span></strong>
<button type="button" id="tg-clear-selected" class="secondary">Desmarcar todos</button>
</div>
<div id="tg-selected-list" class="telegram-selected-list" role="group" aria-label="Grupos selecionados"></div>
<p class="muted">Os grupos selecionados permanecem nesta área, mesmo ao alternar entre arquivados e não arquivados.</p>
<p id="tg-suggestion-note" class="muted" hidden>LibreCode e LibreSign foram pré-selecionados como sugestões. Confira os grupos antes de salvar; nenhuma nova coleta começa automaticamente.</p>
</div>
<div class="group-selection-toolbar">
<label for="tg-search">Buscar entre os grupos carregados</label>
<input type="search" id="tg-search" placeholder="Nome do grupo" autocomplete="off">
<div class="telegram-folder-switch" role="group" aria-label="Exibir conversas Telegram">
<button type="button" id="tg-folder-active" class="secondary" aria-pressed="true">Não arquivados</button>
<button type="button" id="tg-folder-archived" class="secondary" aria-pressed="false">Arquivados</button>
</div>
<div class="telegram-sort">
<label for="tg-sort">Ordenar grupos carregados</label>
<select id="tg-sort">
<option value="recent">Atividade mais recente</option>
<option value="name">Nome (A–Z)</option>
</select>
</div>
<button type="button" class="secondary" id="tg-reload-groups">Atualizar lista</button>
</div>
<div id="tg-group-list" class="telegram-group-list" role="group" aria-label="Grupos disponíveis para autorização">
<p class="muted">Carregando a primeira página de grupos não arquivados…</p>
</div>
<div class="group-pagination">
<button type="button" id="tg-load-more" class="secondary" hidden>Carregar mais grupos</button>
<span id="tg-page-status" class="muted" role="status" aria-live="polite"></span>
</div>
<p class="muted">Inicialmente aparecem apenas conversas não arquivadas. A busca e a ordenação abrangem os grupos já carregados; as demais páginas são buscadas sob demanda.</p>
<p id="tg-group-count" class="muted">Nenhum grupo selecionado.</p>
<button id="tg-save-groups" type="submit" disabled>Salvar seleção de grupos</button>
<p id="tg-groups-status" role="status" aria-live="polite" class="muted"></p>
</form>
</div>
<div id="tg-authorized-area" class="activate-step" hidden>
<h3>3. Ativar análise dos grupos selecionados</h3>
<p>Somente depois de ativar, o aplicativo poderá consultar o histórico recente dos grupos escolhidos. Ele extrai metadados e pistas de trabalho sem armazenar o texto das mensagens; não envia mensagens nem publica horas no Kimai.</p>
<button type="button" id="tg-activate">Ativar análise dos grupos</button>
</div>
<div id="tg-enabled-area" class="notice" hidden>
<strong>Coleta de metadados habilitada.</strong> Somente os grupos autorizados serão analisados. Salvar uma nova seleção interrompe a coleta até sua próxima confirmação.
</div>
</div>

<details class="advanced-settings">
<summary>Privacidade e controle da conexão</summary>
<p class="muted">A autenticação cria uma sessão Telegram local. Quando autorizada, a análise consulta mensagens recentes apenas dos grupos selecionados e processa o conteúdo transitoriamente para extrair metadados, sem guardar textos ou nomes de contatos pessoais no histórico do coletor. O modo somente leitura refere-se à publicação de horas no Kimai: a configuração e a sessão local continuam funcionando.</p>
<form method="post" action="{{route('kimai.telegram.disable')}}">@csrf
<button type="submit" class="secondary">Desativar análise do Telegram (preservar login)</button>
</form>
</details>
</section>
