@if(!empty($creds['KIMAI_API_TOKEN']) && $profile)
<section class="connection-summary">
<div class="connected-icon" aria-hidden="true">@include('automatic-kimai.components.icon',['name'=>'circle-check'])</div>
<div><h2>Kimai configurado</h2><p class="muted">Sua conta já está conectada a {{ parse_url($creds['KIMAI_BASE_URL']??'', PHP_URL_HOST) ?: 'seu servidor Kimai' }}. Você não precisa configurar novamente para usar o monitoramento.</p></div>
</section>
<details class="settings-collapsible">
<summary>Alterar minha conexão Kimai</summary>
@endif
<section>
<h2>@if(empty($creds['KIMAI_API_TOKEN'])) Conectar ao Kimai @else Detalhes da conexão @endif</h2>
<form method="post" action="{{route('kimai.setup')}}">@csrf
<p class="muted">Conecte sua conta e escolha onde suas horas serão organizadas. São três passos; você não precisa descobrir códigos de cliente ou projeto.</p>
<div class="setup-fields">
<div><label for="kimai-url">Endereço do Kimai</label><input id="kimai-url" name="kimai_url" type="url" placeholder="https://kimai.exemplo.org" value="{{old('kimai_url',$creds['KIMAI_BASE_URL'] ?? '')}}" required></div>
<div><label for="kimai-token">Chave de acesso pessoal</label><input id="kimai-token" type="password" name="kimai_token" autocomplete="off" placeholder="{{empty($creds['KIMAI_API_TOKEN'])?'Cole aqui o token da sua conta':'Já conectada; deixe vazio para manter'}}"></div>
</div>
<details class="advanced-settings">
<summary>Opções avançadas de identificação e fuso horário</summary>
<div class="grid">
<div><label for="profile-id">Identificação local</label><input id="profile-id" name="profile_id" value="{{old('profile_id',$profile?->get('profile_id') ?? 'user')}}" required></div>
<div><label for="timezone">Fuso horário da conta</label><input id="timezone" name="timezone" value="{{old('timezone',$profile?->get('timezone') ?? date_default_timezone_get())}}" required></div>
<div><label for="work-domains">Sites profissionais autorizados</label><input id="work-domains" name="work_domains" value="{{old('work_domains',implode(', ',$profile?->get('work_domains') ?? []))}}" placeholder="projetos.exemplo.org"></div>
</div>
</details>
<input type="hidden" id="customer-id" name="customer_id" value="{{old('customer_id',$profile?->get('customer_id') ?? '')}}">
<input type="hidden" id="project-id" name="project_id" value="{{old('project_id',$profile?->get('project_id') ?? '')}}">
<input type="hidden" id="activity-id" name="activity_id" value="{{old('activity_id',$profile?->get('activity_id') ?? '')}}">
<div class="catalog-step">
<button type="button" id="catalog-load" class="secondary">Buscar meus projetos</button>
<p id="catalog-message" class="muted" role="status">Informe o endereço e a chave acima. Depois selecione onde registrar suas horas.</p>
<div class="grid">
<div><label for="catalog-customers">Cliente</label><select id="catalog-customers"><option>Carregue o catálogo primeiro</option></select></div>
<div><label for="catalog-projects">Projeto</label><select id="catalog-projects"><option>Carregue o catálogo primeiro</option></select></div>
<div><label for="catalog-activities">Tipo de atividade</label><select id="catalog-activities"><option>Carregue o catálogo primeiro</option></select></div>
</div>
</div>
<button type="submit">Salvar conexão</button>
</form>
<p class="warn">Use apenas a chave de acesso da sua própria conta. Os dados são guardados localmente.</p>
</section>
@if(!empty($creds['KIMAI_API_TOKEN']) && $profile)
</details>
@endif
