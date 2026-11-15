<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<meta name="color-scheme" content="light dark"/>
<title>Automatic Kimai — Revisão local de horas</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color-scheme:light;--bg:#f3f5f8;--fg:#24314a;--card:#fff;--muted:#627187;--line:#d7dfeb;--blue:#2451a6}
*{box-sizing:border-box}body{background:var(--bg);color:var(--fg);margin:0}
header{padding:24px 5vw;background:#173b6e;color:#fff}header h1{font-size:25px;margin:0 0 6px}
header p{margin:0;color:#c7defd}main{max-width:1200px;margin:24px auto;padding:0 18px}
section{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:20px;margin:15px 0;box-shadow:0 2px 8px #20334e0a}
h2{margin:0 0 14px;font-size:20px}h3{font-size:16px}.muted,small{color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(225px,1fr));gap:14px}
label{display:block;font-weight:600;font-size:13px;margin:7px 0 4px}
input,select,textarea{width:100%;border:1px solid #aabbcd;border-radius:7px;padding:10px;background:#fff;color:#20334e;font:inherit}
input[type=checkbox]{width:16px;height:16px;vertical-align:middle}
.button,button{background:var(--blue);border:0;color:#fff;padding:11px 15px;border-radius:8px;cursor:pointer;font-size:14px;font-weight:600;margin-top:10px}
.button.secondary,button.secondary{background:#e7eefb;color:#274b85}
.notice{padding:12px 16px;border-radius:9px;background:#e7eefb;margin:10px 0}.notice.error{background:#fee7e7;color:#84272b}
.warn{font-size:12px;color:#85510b;border-left:3px solid #e4ad45;padding-left:10px}
table{border-collapse:collapse;width:100%;font-size:13px}td,th{padding:9px;border-bottom:1px solid var(--line);text-align:left}
.tablewrap{overflow-x:auto}.metrics{font-size:30px;font-weight:700}.source{display:flex;align-items:center;gap:12px;padding:8px;border-bottom:1px solid var(--line)}
.source label{display:inline;font-weight:500;margin:0}code{font-size:12px}details{margin:6px 0}
@media(prefers-color-scheme:dark){:root{color-scheme:dark;--bg:#111823;--fg:#ebf0f7;--card:#1a2636;--muted:#afbdd0;--line:#3a4d64;--blue:#436ac8}input,select,textarea{background:#182536;color:#eee;border-color:#5f7288}.button.secondary,button.secondary{background:#23395a;color:#dae6ff}}
</style>
</head>
<body>
<header><h1>Automatic Kimai</h1><p>Horas sob controle, com privacidade e confirmação humana.</p></header>
<main>
@if (session('ok'))<div class="notice" role="status">{{session('ok')}}</div>@endif
@if ($errors->any())<div class="notice error" role="alert"><strong>Não foi possível concluir a operação.</strong>
<ul>@foreach($errors->all() as $message)<li>{{$message}}</li>@endforeach</ul></div>@endif
<div class="notice">
<strong>Versão PHP de avaliação — somente leitura.</strong>
O lançamento automático e o registro manual no Kimai permanecem bloqueados durante a migração.
O piloto Python continua ativo para impedir perda ou duplicação de horas.
</div>
<section>
<h2>Estado do monitoramento</h2>
<div class="grid">
<div><div class="muted">Situação</div><div class="metrics" style="font-size:20px">{{ $status['result'] ?? 'never_ran' }}</div></div>
<div><div class="muted">Lacunas observadas</div><div class="metrics">{{ $status['coverage_gap_count'] ?? 0 }}</div></div>
<div><div class="muted">Intervalos a revisar</div><div class="metrics">{{ $status['manual_review_interval_count'] ?? 0 }}</div></div>
<div><div class="muted">Agenda sem cobertura</div><div class="metrics">{{ $status['calendar_gap_count'] ?? 0 }}</div></div>
</div>
<p class="muted">Última análise: {{ $status['at_local'] ?? 'ainda não executada' }}. Horários de agenda e navegador não comprovam trabalho humano.</p>
@if ($profile)
<form method="post" action="{{route('kimai.analyze')}}">@csrf
<button type="submit">Atualizar análise em PHP (somente leitura)</button>
<button class="secondary" name="capture_only" value="1" type="submit">Coletar somente ActivityWatch</button>
</form>
@endif
</section>
<section>
<h2>1. Conectar sua conta Kimai</h2>
<form method="post" action="{{route('kimai.setup')}}">@csrf
<div class="grid">
<div><label>Identificação local</label><input name="profile_id" value="{{old('profile_id',$profile?->get('profile_id') ?? 'user')}}" required></div>
<div><label>Endereço HTTPS do Kimai</label><input id="kimai-url" name="kimai_url" placeholder="https://kimai.exemplo.org" value="{{old('kimai_url',$creds['KIMAI_BASE_URL'] ?? '')}}" required></div>
<div><label>Token pessoal de API</label><input id="kimai-token" type="password" name="kimai_token" autocomplete="off" placeholder="{{empty($creds['KIMAI_API_TOKEN'])?'Informe um token individual':'Deixe vazio para manter o existente'}}"></div>
<div><label>Fuso horário (igual ao Kimai)</label><input name="timezone" value="{{old('timezone',$profile?->get('timezone') ?? date_default_timezone_get())}}" required></div>
<div><label>Cliente (ID)</label><input id="customer-id" type="number" min="1" name="customer_id" value="{{old('customer_id',$profile?->get('customer_id') ?? '')}}" required></div>
<div><label>Projeto (ID)</label><input id="project-id" type="number" min="1" name="project_id" value="{{old('project_id',$profile?->get('project_id') ?? '')}}" required></div>
<div><label>Atividade (ID)</label><input id="activity-id" type="number" min="1" name="activity_id" value="{{old('activity_id',$profile?->get('activity_id') ?? '')}}" required></div>
<div><label>Domínios profissionais explicitamente autorizados (separados por vírgula)</label><input name="work_domains" value="{{old('work_domains',implode(', ',$profile?->get('work_domains') ?? []))}}" placeholder="projetos.exemplo.org"></div>
</div>
<div class="notice">
<button type="button" id="catalog-load" class="secondary">1. Listar clientes, projetos e atividades pelo nome</button>
<p id="catalog-message" class="muted" role="status">Digite o endereço e o token do Kimai para procurar suas opções.</p>
<div class="grid">
<div><label>Cliente</label><select id="catalog-customers"><option>Carregue o catálogo primeiro</option></select></div>
<div><label>Projeto</label><select id="catalog-projects"><option>Carregue o catálogo primeiro</option></select></div>
<div><label>Atividade</label><select id="catalog-activities"><option>Carregue o catálogo primeiro</option></select></div>
</div>
</div>
<button type="submit">2. Testar conexão e salvar perfil em modo revisão</button>
</form>
<p class="warn">Não use o token de outra pessoa. A conexão valida os IDs informados e o fuso horário. O token não aparece nas telas nem é enviado a terceiros.</p>
</section>
<section><h2>2. Fontes de informação</h2>
<form method="post" action="{{route('kimai.sources')}}">@csrf
@foreach($sources as $name=>$enabled)
<div class="source"><input type="checkbox" name="source_{{$name}}" value="1" id="source-{{$name}}" @checked($enabled) @disabled($name==='activitywatch' || $name==='telegram')><label for="source-{{$name}}">{{[
'activitywatch'=>'ActivityWatch — aplicativos e inatividade (local)',
'github'=>'GitHub — metadados de eventos',
'nextcloud_talk'=>'Nextcloud Talk — ações próprias',
'nextcloud_calendar'=>'Nextcloud Calendar — compromissos previstos',
'telegram'=>'Telegram — ainda indisponível na versão PHP',
'chatgpt_browser'=>'ChatGPT — categoria da aba, não histórico privado',
'claude_browser'=>'Claude — categoria da aba, não histórico privado'
][$name]??$name}}</label></div>
@endforeach
<button type="submit">Salvar consentimentos</button>
</form>
<p class="muted">Ative somente as fontes que deseja utilizar. Se ativar calendário ou Talk, informe as credenciais Nextcloud na seção seguinte. Uma atividade agendada não comprova participação.</p>
</section>
<section><h2>2.1. Credenciais das integrações opcionais</h2>
<p class="muted">Todas as credenciais são armazenadas localmente, criptografadas e nunca incluídas nos relatórios. Deixe a senha vazia para manter a anterior.</p>
<form method="post" action="{{route('kimai.connectors')}}">@csrf
<div class="grid">
<div><label>Servidor Nextcloud (HTTPS)</label><input name="nextcloud_url" value="{{ $creds['NEXTCLOUD_URL']??'' }}" placeholder="https://nuvem.exemplo.org"></div>
<div><label>Usuário Nextcloud</label><input name="nextcloud_user" value="{{ $creds['NEXTCLOUD_USERNAME']??'' }}"></div>
<div><label>Senha de aplicativo Nextcloud</label><input name="nextcloud_password" type="password" autocomplete="off" placeholder="Deixe em branco para manter"></div>
<div><label>Token pessoal GitHub</label><input name="github_token" type="password" autocomplete="off" placeholder="Deixe em branco para manter"></div>
</div>
<button type="submit">Salvar conexões opcionais</button>
</form>
</section>
<section>
<h2>3. Revisar intervalos e lacunas</h2>
@forelse(($report['days'] ?? []) as $day)
<details @if($loop->last) open @endif>
<summary><strong>{{ $day['day'] }}</strong> — {{count($day['sessions']??[])}} sugestões;
{{count($day['review_intervals']??[])}} intervalos a revisar;
{{count($day['calendar_gap_alerts']??[])}} alertas de agenda
@if($day['warning']) · {{$day['warning']}}@endif
</summary>
<div class="tablewrap"><table><thead><tr><th>Início</th><th>Fim</th><th>Decisão</th><th>Razão</th><th>Fontes profissionais</th><th>Conversa IA sem confirmação</th></tr></thead><tbody>
@foreach(($day['sessions']??[]) as $s)
<tr><td>{{substr($s['begin']??'',11,5)}}</td><td>{{substr($s['end']??'',11,5)}}</td>
<td>{{$s['action']??'review'}}</td><td>{{$s['reason']??''}}</td><td>{{round(($s['work_seconds']??0)/60)}} min</td>
<td>{{round(($s['chat_unknown_seconds']??0)/60)}} min</td></tr>
@endforeach
</tbody></table></div>
@foreach(($day['review_intervals']??[]) as $entry)
<p><strong>Revisar:</strong> {{substr($entry['start'],11,5)}}–{{substr($entry['end'],11,5)}} · {{$entry['observed_nonafk_minutes']??0}} minutos observados, não comprovados como trabalho.</p>
@endforeach
@foreach(($day['calendar_gap_alerts']??[]) as $entry)
<p><strong>Agenda:</strong> {{substr($entry['start'],11,5)}}–{{substr($entry['end'],11,5)}} · {{$entry['uncovered_schedule_minutes']??0}} minutos agendados sem cobertura.</p>
@endforeach
</details>
@empty
<p class="muted">Não há relatório. Salve um perfil válido e execute uma análise. O aplicativo não cria horas a partir da ausência de dados.</p>
@endforelse
</section>
<section><h2>4. Prévia de trabalho presencial (sem lançamento)</h2>
<p>Informe a participação real e as pausas. O aplicativo mostra os intervalos descontados antes de qualquer integração com o Kimai.</p>
<form method="post" action="{{route('kimai.attendance-preview')}}">@csrf
<div class="grid">
<div><label>Dia</label><input type="date" name="day" required></div>
<div><label>Atividade</label><input name="label" maxlength="64" required placeholder="Reunião presencial"></div>
<div><label>Início da jornada</label><input type="time" name="start" value="09:00" required></div>
<div><label>Fim da jornada</label><input type="time" name="end" value="17:00" required></div>
<div><label>Pausas (HH:MM-HH:MM, separadas por vírgula)</label><input name="breaks" placeholder="12:00-13:00 (deixe vazio se não houve pausa)"></div>
<div><label>Origem dos horários</label><input name="schedule_source" placeholder="Horários efetivos declarados" required></div>
</div>
<label><input type="checkbox" name="attended" value="1" required> Confirmo que participei e realizei trabalho humano neste compromisso.</label>
<button type="submit" class="secondary">Conferir períodos (não grava no Kimai)</button>
</form>
</section>
<section><h2>5. Declaração manual de horas</h2>
<p class="warn">O formulário está preparado, mas a publicação no Kimai está bloqueada até a troca controlada do runtime. Seus horários no Python continuam sendo registrados pela instalação anterior.</p>
<form method="post" action="{{route('kimai.manual')}}">@csrf
<div class="grid">
<div><label>Data (AAAA-MM-DD)</label><input type="date" name="day" required></div>
<div><label>Início real</label><input type="time" name="start" required></div>
<div><label>Fim real</label><input type="time" name="end" required></div>
<div><label>Descrição curta da atividade</label><input name="task" maxlength="64" placeholder="Revisão de documentos" required></div>
</div>
<label><input type="checkbox" name="confirmed" value="1"> Confirmo que realizei pessoalmente o trabalho, excluindo pausas e execução autônoma da IA.</label>
<button type="submit" disabled title="Disponível somente após migração segura de ledger e serviços">Registrar (aguardando homologação)</button>
</form>
</section>
<footer class="muted" style="margin:30px 0">Automatic Kimai · aplicativo local e privado · relatórios não aprovam remuneração nem faturamento.</footer>
</main>
<script>
(() => {
  const button = document.getElementById('catalog-load');
  if (!button) return;
  const $ = (id) => document.getElementById(id);
  let data = null;
  const select = (target, rows, prompt) => {
    const element = $(target);
    element.replaceChildren();
    const first = document.createElement('option');
    first.value = '';
    first.textContent = prompt;
    element.appendChild(first);
    for (const row of rows) {
      const option = document.createElement('option');
      option.value = String(row.id);
      option.textContent = row.name || String(row.id);
      element.appendChild(option);
    }
  };
  const updateProjects = () => {
    const customer = Number($('catalog-customers').value);
    const available = data.projects.filter(item => item.customer_id === customer);
    select('catalog-projects', available, 'Escolha um projeto');
    select('catalog-activities', [], 'Escolha o projeto primeiro');
    $('customer-id').value = customer || '';
  };
  const updateActivities = () => {
    const project = Number($('catalog-projects').value);
    const available = data.activities.filter(item => item.project_id === null || item.project_id === project);
    select('catalog-activities', available, 'Escolha uma atividade');
    $('project-id').value = project || '';
  };
  $('catalog-customers').addEventListener('change', updateProjects);
  $('catalog-projects').addEventListener('change', updateActivities);
  $('catalog-activities').addEventListener('change', () => {
    $('activity-id').value = $('catalog-activities').value || '';
  });
  button.addEventListener('click', async () => {
    const message = $('catalog-message');
    message.textContent = 'Consultando sua instância Kimai...';
    button.disabled = true;
    try {
      const response = await fetch(@json(route('kimai.catalog-preview')), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': @json(csrf_token()),
        },
        body: new URLSearchParams({
          kimai_url: $('kimai-url').value,
          kimai_token: $('kimai-token').value,
        }),
      });
      const answer = await response.json();
      if (!response.ok || !Array.isArray(answer.customers) ||
          !Array.isArray(answer.projects) || !Array.isArray(answer.activities)) {
        throw new Error('Não foi possível consultar os projetos. Verifique o endereço e o token.');
      }
      data = answer;
      select('catalog-customers', answer.customers, 'Escolha um cliente');
      select('catalog-projects', [], 'Escolha um cliente primeiro');
      select('catalog-activities', [], 'Escolha um projeto primeiro');
      if (answer.timezone) {
        const timeField = document.querySelector('input[name="timezone"]');
        timeField.value = answer.timezone;
      }
      message.textContent = 'Catálogo carregado. Escolha cliente, projeto e atividade pelo nome.';
    } catch (error) {
      message.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });
})();
</script>
</body>
</html>
