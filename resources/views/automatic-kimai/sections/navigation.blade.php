<div class="sidebar-toolbar">
<strong class="sidebar-title">Navegação</strong>
<button id="sidebar-toggle" type="button" class="sidebar-toggle" aria-controls="sidebar-navigation" aria-expanded="false" aria-label="Alternar menu" title="Recolher menu">
<span class="toggle-collapse">@include('automatic-kimai.components.icon',['name'=>'panel-left-close'])</span>
<span class="toggle-expand">@include('automatic-kimai.components.icon',['name'=>'panel-left-open'])</span>
</button>
</div>
<div id="sidebar-navigation" class="sidebar-navigation">
<nav class="sidebar-nav" aria-label="Navegação do aplicativo">
<div class="nav-group">
<p class="nav-caption">Meu trabalho</p>
<a href="{{route('kimai.home',['view'=>'overview'])}}" aria-label="Visão geral" title="Visão geral" class="nav-item nav-root @if($view==='overview') is-current @endif" @if($view==='overview') aria-current="page" @endif>
@include('automatic-kimai.components.icon',['name'=>'layout-dashboard'])<span class="nav-label">Visão geral</span>
</a>
<div class="nav-children">
<a href="{{route('kimai.home',['view'=>'review'])}}" aria-label="Conciliação de horas" title="Conciliação de horas" class="nav-item @if($view==='review') is-current @endif" @if($view==='review') aria-current="page" @endif>@include('automatic-kimai.components.icon',['name'=>'clipboard-check'])<span class="nav-label">Conciliação de horas</span></a>
<a href="{{route('kimai.home',['view'=>'attendance'])}}" aria-label="Trabalho presencial" title="Trabalho presencial" class="nav-item @if($view==='attendance') is-current @endif" @if($view==='attendance') aria-current="page" @endif>@include('automatic-kimai.components.icon',['name'=>'calendar-check-2'])<span class="nav-label">Trabalho presencial</span></a>
<a href="{{route('kimai.home',['view'=>'manual'])}}" aria-label="Informar horas" title="Informar horas" class="nav-item @if($view==='manual') is-current @endif" @if($view==='manual') aria-current="page" @endif>@include('automatic-kimai.components.icon',['name'=>'pencil-line'])<span class="nav-label">Informar horas</span></a>
</div>
</div>
<div class="nav-group">
<p class="nav-caption">Dados e conexões</p>
<a href="{{route('kimai.home',['view'=>'sources'])}}" aria-label="Fontes de dados" title="Fontes de dados" class="nav-item nav-root @if($view==='sources') is-current @endif" @if($view==='sources') aria-current="page" @endif>@include('automatic-kimai.components.icon',['name'=>'database'])<span class="nav-label">Fontes de dados</span></a>
@if(in_array(true,$connectionVisibility,true))
<div class="nav-children">
<a href="{{route('kimai.home',['view'=>'integrations'])}}" aria-label="Conexões habilitadas" title="Conexões habilitadas" class="nav-item nav-branch @if($view==='integrations') is-current @endif" @if($view==='integrations') aria-current="page" @endif>@include('automatic-kimai.components.icon',['name'=>'plug-zap'])<span class="nav-label">Conexões habilitadas</span></a>
<div class="nav-grandchildren">
@if($connectionVisibility['nextcloud'])
<a href="{{route('kimai.home',['view'=>'nextcloud'])}}" aria-label="Nextcloud" title="Nextcloud" class="nav-item @if($view==='nextcloud') is-current @endif" @if($view==='nextcloud') aria-current="page" @endif>@include('automatic-kimai.components.brand-icon',['name'=>'nextcloud'])<span class="nav-label">Nextcloud</span></a>
@endif
@if($connectionVisibility['telegram'])
<a href="{{route('kimai.home',['view'=>'telegram'])}}" aria-label="Telegram" title="Telegram" class="nav-item @if($view==='telegram') is-current @endif" @if($view==='telegram') aria-current="page" @endif>@include('automatic-kimai.components.brand-icon',['name'=>'telegram'])<span class="nav-label">Telegram</span></a>
@endif
@if($connectionVisibility['github'])
<a href="{{route('kimai.home',['view'=>'github'])}}" aria-label="GitHub" title="GitHub" class="nav-item @if($view==='github') is-current @endif" @if($view==='github') aria-current="page" @endif>@include('automatic-kimai.components.brand-icon',['name'=>'github'])<span class="nav-label">GitHub</span></a>
@endif
</div>
</div>
@endif
</div>
<div class="nav-group">
<p class="nav-caption">Preferências</p>
<a href="{{route('kimai.home',['view'=>'settings_sync'])}}" aria-label="Backup e sincronização" title="Backup e sincronização" class="nav-item nav-root @if($view==='settings_sync') is-current @endif" @if($view==='settings_sync') aria-current="page" @endif>@include('automatic-kimai.components.icon',['name'=>'database'])<span class="nav-label">Backup e sincronização</span></a>
<a href="{{route('kimai.home',['view'=>'kimai'])}}" aria-label="Conta Kimai" title="Conta Kimai" class="nav-item nav-root @if($view==='kimai') is-current @endif" @if($view==='kimai') aria-current="page" @endif>@include('automatic-kimai.components.icon',['name'=>'settings-2'])<span class="nav-label">Conta Kimai</span></a>
</div>
</nav>
</div>
