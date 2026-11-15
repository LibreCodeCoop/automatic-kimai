<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<meta name="color-scheme" content="light dark"/>
<title>Automatic Kimai — {{ $pageTitles[$view] }}</title>
<meta name="theme-color" content="#17365e"/>
<script>
/* Set an explicit preference before painting to avoid a light-theme flash. */
try {
  const theme = localStorage.getItem('automatic-kimai:theme');
  if (theme === 'light' || theme === 'dark') {
    document.documentElement.dataset.theme = theme;
  }
} catch (_error) { /* Browser storage is optional. */ }
</script>
<link rel="stylesheet" href="{{ asset('css/automatic-kimai.css') }}?v=15">
</head>
<body>
<header class="app-header">
<div class="header-inner">
<a class="brand" href="{{ route('kimai.home',['view'=>'overview']) }}">
<span class="brand-mark">@include('automatic-kimai.components.icon',['name'=>'clock-3'])</span>
<span><strong>Automatic Kimai</strong><small>Seu tempo de trabalho</small></span>
</a>
<button type="button" id="theme-switch" class="theme-switch" aria-label="Alternar entre tema claro e escuro" title="Alternar tema" aria-pressed="false">
@include('automatic-kimai.components.icon',['name'=>'moon','class'=>'theme-icon moon-icon'])
@include('automatic-kimai.components.icon',['name'=>'sun','class'=>'theme-icon sun-icon'])
</button>
</div>
</header>
<div class="workspace-shell" id="workspace-shell">
<aside id="app-sidebar" class="sidebar">
@include('automatic-kimai.sections.navigation')
</aside>
<main id="main-content" class="main-content">
@if(session('ok'))<div class="notice" role="status">{{session('ok')}}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Não foi possível concluir a operação.</strong><ul>@foreach($errors->all() as $message)<li>{{$message}}</li>@endforeach</ul></div>@endif
<div class="page-intro"><div>
<p class="eyebrow">Seu espaço de trabalho</p>
<h1>{{ $pageTitles[$view] }}</h1>
<p class="muted">{{ $pageHints[$view] }}</p>
</div>
@if(!$profile)<a class="button" href="{{ route('kimai.home',['view'=>'kimai']) }}">Conectar minha conta</a>@endif
</div>
@if($view==='overview')
@include('automatic-kimai.sections.overview')
@endif
@if($view==='review')
@include('automatic-kimai.sections.review')
@endif
@if($view==='attendance')
@include('automatic-kimai.sections.attendance')
@endif
@if($view==='manual')
@include('automatic-kimai.sections.manual')
@endif
@if($view==='kimai')
@include('automatic-kimai.sections.kimai')
@endif
@if($view==='sources')
@include('automatic-kimai.sections.sources')
@endif
@if($view==='integrations')
@include('automatic-kimai.sections.integrations')
@endif
@if($view==='nextcloud')
@include('automatic-kimai.sections.nextcloud')
@endif
@if($view==='telegram')
@include('automatic-kimai.sections.telegram')
@endif
@if($view==='github')
@include('automatic-kimai.sections.github')
@endif
<footer class="muted">Automatic Kimai · dados locais e privados · informações não equivalem a aprovação financeira.</footer>
</main>
</div>
<script>
@include('automatic-kimai.scripts.theme')
@include('automatic-kimai.scripts.sidebar')
@if($view==='kimai')
@include('automatic-kimai.scripts.catalog')
@endif
@if($view==='review')
@include('automatic-kimai.scripts.review')
@endif
@if($view==='telegram')
@include('automatic-kimai.scripts.telegram')
@endif
@if($view==='nextcloud')
@include('automatic-kimai.scripts.nextcloud')
@endif
@if($view==='github')
@include('automatic-kimai.scripts.github')
@endif
</script>
</body>
</html>
