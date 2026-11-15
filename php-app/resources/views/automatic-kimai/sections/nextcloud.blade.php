@if(!empty($creds['NEXTCLOUD_URL']) && !empty($creds['NEXTCLOUD_USERNAME']) && !empty($creds['NEXTCLOUD_APP_PASSWORD']))
<section class="connection-summary">
<div class="connected-icon" aria-hidden="true">@include('automatic-kimai.components.icon',['name'=>'circle-check'])</div>
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
<section>
<h2>Fontes de dados habilitadas</h2>
<p class="muted">A configuração das fontes é feita em um único lugar. Essas opções não são alteradas ao reconectar uma conta.</p>
<div class="source-state"><span>Agenda</span><strong>{{ $sources['nextcloud_calendar'] ? 'Habilitada' : 'Desativada' }}</strong></div>
<div class="source-state"><span>Talk</span><strong>{{ $sources['nextcloud_talk'] ? 'Habilitado' : 'Desativado' }}</strong></div>
<a class="secondary-link" href="{{ route('kimai.home',['view'=>'sources']) }}">@include('automatic-kimai.components.icon',['name'=>'settings-2']) Alterar fontes de dados</a>
</section>
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
