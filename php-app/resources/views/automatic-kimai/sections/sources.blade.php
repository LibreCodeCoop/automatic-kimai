<section><h2>Fontes de informação</h2>
<form method="post" action="{{route('kimai.sources')}}">@csrf
@foreach($sources as $name=>$enabled)
@if ($name==='telegram') @continue @endif
<div class="source"><input type="checkbox" name="source_{{$name}}" value="1" id="source-{{$name}}" @checked($enabled) @disabled($name==='activitywatch')><label for="source-{{$name}}">{{[
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
<p class="muted">Escolha somente as fontes que deseja usar. Conecte as contas na aba Integrações. Compromissos de agenda não comprovam trabalho.</p>
</section>
