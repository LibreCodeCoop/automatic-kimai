<section>
<h2>GitHub</h2>
<p class="muted">A integração relaciona ações da sua conta GitHub ao trabalho. Eventos não comprovam duração humana.</p>
@if(!empty($creds['GITHUB_TOKEN']))
<div class="nextcloud-account-card" id="github-connected">
<div class="nextcloud-account-header">
<div class="nextcloud-avatar-wrap">
<img id="github-avatar" class="nextcloud-avatar" width="68" height="68" src="{{ route('kimai.github.avatar') }}" alt="Avatar da conta GitHub" referrerpolicy="no-referrer">
<div id="github-avatar-fallback" class="nextcloud-avatar-fallback" hidden aria-hidden="true">G</div>
</div>
<div class="nextcloud-account-identity">
<p class="eyebrow">GitHub vinculado</p>
<h2 id="github-display-name">Verificando conta…</h2>
<p id="github-username" class="muted">Identificando a conta que autorizou a chave.</p>
</div>
<div id="github-health" class="nextcloud-account-status">Chave configurada</div>
</div>
<div class="nextcloud-account-actions">
<button type="button" id="github-disconnect" class="secondary">Desconectar deste computador</button>
</div>
<p id="github-disconnect-status" role="status" aria-live="polite" class="muted"></p>
</div>
<details class="settings-collapsible">
<summary>Trocar chave de acesso do GitHub</summary>
@else
<div class="notice">
<strong>Nenhuma conta GitHub conectada.</strong> Informe uma chave pessoal individual para vincular sua identidade.
</div>
@endif
<form method="post" action="{{route('kimai.connectors')}}">@csrf
<label for="github-token">Chave pessoal de acesso GitHub</label>
<input id="github-token" type="password" name="github_token" autocomplete="off" placeholder="Cole a chave de acesso pessoal">
<button type="submit">Salvar conexão</button>
</form>
@if(!empty($creds['GITHUB_TOKEN']))
</details>
@endif
<p class="muted">Use permissões mínimas. Desconectar apaga a chave local, mas não a revoga no GitHub; a revogação remota é feita nas configurações da sua conta.</p>
</section>
