<section>
<h2>GitHub</h2>
<p class="muted">Esta integração ajuda a relacionar ações no GitHub ao trabalho. Ela não contabiliza automaticamente todos os minutos entre eventos.</p>
@if(!empty($creds['GITHUB_TOKEN']))<div class="connection-summary compact"><div class="connected-icon">@include('automatic-kimai.components.icon',['name'=>'circle-check'])</div><div><strong>Chave do GitHub configurada</strong><p class="muted">Não precisa informar novamente. Para substituí-la, cole uma nova chave abaixo.</p></div></div>@endif
<form method="post" action="{{route('kimai.connectors')}}">@csrf
<label for="github-token">Chave pessoal de acesso GitHub</label>
<input id="github-token" type="password" name="github_token" autocomplete="off" placeholder="Cole uma chave somente se quiser conectar ou alterar">
<button type="submit">Salvar conexão</button>
</form>
<p class="muted">Você pode criar uma chave de acesso com as permissões mínimas necessárias nas configurações do GitHub.</p>
</section>
