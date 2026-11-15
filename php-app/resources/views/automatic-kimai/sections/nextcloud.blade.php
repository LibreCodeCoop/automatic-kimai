@if(!empty($creds['NEXTCLOUD_URL']) && !empty($creds['NEXTCLOUD_USERNAME']) && !empty($creds['NEXTCLOUD_APP_PASSWORD']))
<section class="connection-summary">
<div class="connected-icon" aria-hidden="true">✓</div>
<div>
<h2>Conta Nextcloud configurada</h2>
<p class="muted">{{ $creds['NEXTCLOUD_USERNAME'] }} · {{ parse_url($creds['NEXTCLOUD_URL'], PHP_URL_HOST) }}. Você pode reconectar para renovar a autorização do aplicativo.</p>
</div>
</section>
@endif
<section>
<h2>Conectar com Nextcloud</h2>
<p class="muted">Ao autorizar, o Nextcloud cria uma chave exclusiva para o Automatic Kimai. Se você já estiver conectado no navegador, poderá usar essa sessão para aprovar o acesso, sem digitar sua senha novamente.</p>
<div class="setup-fields">
<div>
<label for="nc-url">Endereço do seu Nextcloud</label>
<input id="nc-url" type="url" autocomplete="url" placeholder="https://cloud.exemplo.org" value="{{ $creds['NEXTCLOUD_URL'] ?? ($desktopNextcloudServers[0]??'') }}" list="nc-known" required>
<datalist id="nc-known">@foreach($desktopNextcloudServers as $server)<option value="{{ $server }}"></option>@endforeach</datalist>
@if(!empty($desktopNextcloudServers))<small class="muted">Endereço sugerido a partir do aplicativo Nextcloud do computador. Nenhuma senha foi consultada.</small>@endif
</div>
</div>
<button id="nc-start" type="button">Conectar pelo navegador</button>
<p id="nc-status" role="status" aria-live="polite" class="muted">Use o botão para iniciar uma autorização segura.</p>
<div id="nc-approval" class="approval-step" hidden>
<p><strong>Autorize a conexão no Nextcloud.</strong> A janela do navegador pode pedir sua confirmação ou autenticação de dois fatores.</p>
<a id="nc-login-link" class="button" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" href="#">Abrir Nextcloud para autorizar</a>
<button id="nc-check" type="button" class="secondary">Já autorizei, verificar</button>
</div>
</section>
@if(!empty($creds['NEXTCLOUD_APP_PASSWORD']))
<section>
<h2>Quais informações posso utilizar?</h2>
<p class="muted">Ative apenas o que fizer sentido. A agenda não comprova presença, e mensagens não comprovam uma jornada inteira.</p>
<form method="post" action="{{ route('kimai.nextcloud.sources') }}">@csrf
<div class="source"><input type="checkbox" id="nc-calendar" name="calendar" value="1" @checked($sources['nextcloud_calendar'])><label for="nc-calendar">Agenda — consultar horários de compromissos</label></div>
<div class="source"><input type="checkbox" id="nc-talk" name="talk" value="1" @checked($sources['nextcloud_talk'])><label for="nc-talk">Nextcloud Talk — consultar metadados de minhas ações</label></div>
<button type="submit">Salvar permissões</button>
</form>
</section>
@endif
<details class="settings-collapsible">
<summary>Configuração manual (opcional)</summary>
<p class="muted">Use somente se o Login Flow v2 não estiver disponível no servidor. Gere uma senha de aplicativo em Nextcloud → Configurações pessoais → Segurança → Dispositivos e sessões.</p>
<form method="post" action="{{ route('kimai.connectors') }}">@csrf
<div class="setup-fields">
<div><label for="nc-manual-url">Endereço HTTPS</label><input id="nc-manual-url" name="nextcloud_url" type="url" value="{{ $creds['NEXTCLOUD_URL']??'' }}" placeholder="https://nuvem.exemplo.org" required></div>
<div><label for="nc-manual-user">Usuário Nextcloud</label><input id="nc-manual-user" name="nextcloud_user" value="{{ $creds['NEXTCLOUD_LOGIN_NAME']??($creds['NEXTCLOUD_USERNAME']??'') }}" required></div>
<div><label for="nc-manual-password">Senha de aplicativo</label><input id="nc-manual-password" name="nextcloud_password" type="password" autocomplete="off" placeholder="Não use a senha principal"></div>
</div>
<button type="submit" class="secondary">Salvar dados manuais</button>
</form>
</details>
