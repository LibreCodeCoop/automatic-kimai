(() => {
  const status=document.getElementById('tg-status');
  if (!status) return;
  const $=id => document.getElementById(id);
  const urls={
    status:@json(route('kimai.telegram.status')),
    qr:@json(route('kimai.telegram.qr')),
    twoFactor:@json(route('kimai.telegram.2fa')),
    activate:@json(route('kimai.telegram.activate')),
    groups:@json(route('kimai.telegram.groups')),
    saveGroups:@json(route('kimai.telegram.groups.save')),
  };
  const csrf=@json(csrf_token());
  let poll=null;
  let fetchingGroups=false;
  let initialized=false;
  let activeFolder=0;
  let enabled=false;
  let currentState='unknown';
  let suggestionsAllowed=false;
  let savedSelection=new Set();
  let selectedSelection=new Set();
  const manuallyExcludedSuggestions=new Set();
  const loadedGroups=new Map();
  const pages={
    0:{cursor:null,loaded:false,scanned:0,seen:0,loading:false,error:false,errorMessage:''},
    1:{cursor:null,loaded:false,scanned:0,seen:0,loading:false,error:false,errorMessage:''},
  };
  const collator=new Intl.Collator('pt-BR',{sensitivity:'base',numeric:true});

  const message=value=>{status.textContent=value;};
  const stopPoll=()=>{if(poll!==null)clearInterval(poll);poll=null;};
  const post=async (url,payload={})=>{
    const response=await fetch(url,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
      body:JSON.stringify(payload),
    });
    const data=await response.json();
    if(!response.ok)throw new Error(data.message||'Operação não concluída. Tente novamente.');
    return data;
  };
  const dirty=()=>selectedSelection.size!==savedSelection.size ||
    [...selectedSelection].some(id=>!savedSelection.has(id));
  const renderList=()=>{
    const current=pages[activeFolder];
    const search=$('tg-search').value.trim().toLocaleLowerCase();
    const alphabetically=$('tg-sort').value==='name';
    const previousScroll=$('tg-group-list').scrollTop;
    const selected=[...loadedGroups.values()].filter(group=>selectedSelection.has(group.id));
    selected.sort((a,b)=>collator.compare(a.title,b.title));
    const pinned=document.createDocumentFragment();
    for(const group of selected){
      group.checkbox.checked=true;
      group.element.hidden=false;
      pinned.appendChild(group.element);
    }
    $('tg-selected-list').replaceChildren(pinned);
    $('tg-selected-area').hidden=selectedSelection.size===0;
    $('tg-selected-count').textContent=String(selectedSelection.size);

    const others=[...loadedGroups.values()].filter(group=>
      !selectedSelection.has(group.id) &&
      (group.folders.has(activeFolder) || group.recommended));
    others.sort((a,b)=>alphabetically?
      collator.compare(a.title,b.title):
      (a.orders[activeFolder]??Number.MAX_SAFE_INTEGER)-
      (b.orders[activeFolder]??Number.MAX_SAFE_INTEGER));
    const fragment=document.createDocumentFragment();
    let visible=0;
    for(const group of others){
      group.checkbox.checked=false;
      group.element.hidden=search!==''&&!group.title.toLocaleLowerCase().includes(search);
      if(!group.element.hidden)visible++;
      fragment.appendChild(group.element);
    }
    if(others.length===0){
      const note=document.createElement('p');
      note.className='muted';
      if(current.error)note.setAttribute('role','alert');
      note.textContent=current.error?
        current.errorMessage:
        current.loading?
          'Carregando a primeira página…':
        current.loaded?
          (current.cursor===null?'Nenhum outro grupo nesta categoria.':
            'Nenhum outro grupo nesta página. Carregue mais para continuar.'):
          'Escolha uma categoria para carregar seus grupos.';
      fragment.appendChild(note);
    }
    $('tg-group-list').replaceChildren(fragment);
    $('tg-group-list').scrollTop=previousScroll;
    $('tg-page-status').textContent=current.seen+' grupos carregados'+
      (search?' · '+visible+' correspondem à busca':'')+
      (current.cursor!==null?' · há mais páginas':current.loaded?' · fim da lista':'');
    $('tg-load-more').hidden=!(current.error || (current.loaded&&current.cursor!==null));
    $('tg-load-more').disabled=fetchingGroups;
    $('tg-reload-groups').disabled=fetchingGroups;
    $('tg-load-more').textContent=current.error?
      'Tentar carregar novamente':'Carregar mais grupos';
    $('tg-group-count').textContent=selectedSelection.size===0?
      'Nenhum grupo selecionado.':
      selectedSelection.size===1?'1 grupo selecionado.':
      selectedSelection.size+' grupos selecionados.';
    const hasChanges=dirty();
    $('tg-save-groups').disabled=!initialized||selectedSelection.size>40||!hasChanges;
    $('tg-authorized-area').hidden=enabled||savedSelection.size===0||hasChanges;
    $('tg-activate').disabled=hasChanges;
    $('tg-enabled-area').hidden=!enabled;
    $('tg-suggestion-note').hidden=!(hasChanges && suggestionsAllowed &&
      selected.some(group=>group.recommended));
  };
  const upsertGroup=(source,folder=null)=>{
    if(!source||!Number.isSafeInteger(source.id)||
       typeof source.title!=='string'||source.title.trim()==='')return;
    const id=String(source.id);
    let group=loadedGroups.get(id);
    if(!group){
      const label=document.createElement('label');
      label.className='telegram-group-option';
      const checkbox=document.createElement('input');
      checkbox.type='checkbox';
      checkbox.className='tg-group-checkbox';
      checkbox.name='groups';
      checkbox.value=id;
      const title=document.createElement('span');
      title.textContent=source.title;
      label.append(checkbox,title);
      group={
        id,title:source.title,labelTitle:title,
        element:label,checkbox,recommended:false,
        badge:null,folders:new Set(),orders:{},
      };
      checkbox.addEventListener('change',()=>{
        if(checkbox.checked){
          if(selectedSelection.size>=40){
            checkbox.checked=false;
            $('tg-groups-status').textContent='Selecione no máximo 40 grupos.';
            return;
          }
          selectedSelection.add(id);
        } else {
          selectedSelection.delete(id);
          if(group.recommended)manuallyExcludedSuggestions.add(id);
        }
        renderList();
      });
      loadedGroups.set(id,group);
    } else if(source.title!==group.title &&
              (folder!==null||source.recommended===true||
               group.title==='Grupo já autorizado')){
      // A live Telegram page is authoritative over older cached titles;
      // authorization always uses the unchanged peer ID.
      group.title=source.title;
      group.labelTitle.textContent=group.title;
    }
    if(folder!==null){
      group.folders.add(folder);
      if(group.orders[folder]===undefined)group.orders[folder]=pages[folder].seen++;
      if(folder===1 && group.archiveBadge===undefined){
        group.archiveBadge=document.createElement('span');
        group.archiveBadge.className='group-recommendation';
        group.archiveBadge.textContent='Arquivado';
        group.element.appendChild(group.archiveBadge);
      }
    }
    if(source.recommended===true){
      group.recommended=true;
      if(group.badge===null){
        group.badge=document.createElement('span');
        group.badge.className='group-recommendation';
        group.badge.textContent='Comunidade oficial';
        group.element.appendChild(group.badge);
      }
      // A suggestion is selected in the local form once; a user who
      // explicitly unchecked it is never silently overridden in this view.
      // Persisting the list still requires the separate Save action.
      if(suggestionsAllowed&&!manuallyExcludedSuggestions.has(id)&&
         selectedSelection.size<40)selectedSelection.add(id);
    }
  };
  const reloadCurrent=()=>{
    const folder=activeFolder;
    const page=pages[folder];
    page.cursor=null;page.loaded=false;page.scanned=0;
    page.seen=0;page.error=false;page.errorMessage='';
    for(const group of loadedGroups.values()){
      group.folders.delete(folder);
      delete group.orders[folder];
    }
    renderList();
    if(!fetchingGroups)loadGroups();
  };
  const loadGroups=async()=>{
    if(fetchingGroups)return;
    const folder=activeFolder;
    const page=pages[folder];
    if(page.loaded&&!page.error&&page.cursor===null)return;
    fetchingGroups=true;
    page.loading=true;page.error=false;page.errorMessage='';
    renderList();
    $('tg-groups-status').textContent=folder===0?
      'Consultando grupos não arquivados…':'Consultando grupos arquivados…';
    try {
      const url=new URL(urls.groups,location.origin);
      url.searchParams.set('folder',String(folder));
      if(page.cursor!==null)url.searchParams.set('cursor',page.cursor);
      const controller=new AbortController();
      const timeout=setTimeout(()=>controller.abort(),15000);
      let response;
      try{
        response=await fetch(url.toString(),{
          method:'GET',cache:'no-store',credentials:'same-origin',
          headers:{Accept:'application/json'},signal:controller.signal,
        });
      } finally {clearTimeout(timeout);}
      const data=await response.json();
      if(!response.ok)throw new Error(data.message||'Não foi possível carregar os grupos.');
      if(data.folder!==folder||!Array.isArray(data.groups)||
         data.groups.length>130||
         !Array.isArray(data.recommended_groups)||
         data.recommended_groups.length>2||
         !(data.next_cursor===null||typeof data.next_cursor==='string')){
        throw new Error('Resposta de paginação inválida. Atualize a lista.');
      }
      if(!initialized){
        savedSelection=new Set((data.selected_ids||[]).map(String));
        selectedSelection=new Set(savedSelection);
        suggestionsAllowed=data.suggestions_allowed===true;
        initialized=true;
        for(const group of (data.selected_groups||[]))upsertGroup(group);
      }
      for(const group of data.recommended_groups)upsertGroup(group);
      for(const group of data.groups)upsertGroup(group,folder);
      page.cursor=data.next_cursor;
      page.loaded=true;
      page.scanned+=Number.isSafeInteger(data.scanned_dialog_count)?
        data.scanned_dialog_count:0;
      $('tg-groups-status').textContent='Consulta por páginas: '+
        page.scanned+' diálogos verificados nesta categoria. '+
        (page.cursor===null?'Fim da lista.':'Há mais grupos disponíveis.')+
        ' Nenhum histórico de mensagens foi consultado.';
    }catch(error){
      page.error=true;
      page.errorMessage=error.name==='AbortError'?
        'A consulta ao Telegram ultrapassou 15 segundos. Tente novamente.':
        (error.message||'Não foi possível carregar os grupos.');
      $('tg-groups-status').textContent=page.errorMessage;
    }finally{
      page.loading=false;fetchingGroups=false;
      renderList();
      // If the user switched tabs while the old request was running,
      // start the requested folder without losing pending checkboxes.
      if(activeFolder!==folder&&!pages[activeFolder].loaded)loadGroups();
    }
  };
  const switchFolder=folder=>{
    if(activeFolder===folder)return;
    activeFolder=folder;
    $('tg-folder-active').setAttribute('aria-pressed',String(folder===0));
    $('tg-folder-archived').setAttribute('aria-pressed',String(folder===1));
    $('tg-group-list').scrollTop=0;
    renderList();
    if(!pages[folder].loaded&&!fetchingGroups)loadGroups();
  };

  const applyState=data=>{
    const state=data.status;
    const connected=state==='authorized';
    currentState=state;
    enabled=data.enabled===true;
    $('telegram-heading').textContent=connected?'Gerenciar Telegram':'Conectar Telegram';
    $('tg-start').textContent=state==='not_configured'?
      'Configurar conexão Telegram':'Conectar pelo QR Code';
    $('tg-login-step').hidden=connected;
    $('tg-connected-banner').hidden=!connected;
    $('tg-groups-step').hidden=!connected;
    $('tg-advanced-settings').hidden=connected;
    $('tg-controls').hidden=connected;
    $('tg-2fa-form').hidden=state!=='password_required';
    $('tg-qr-area').hidden=state!=='scan_qr';
    if(state==='scan_qr'&&typeof data.qr_svg_base64==='string'){
      $('tg-qr-img').src='data:image/svg+xml;base64,'+data.qr_svg_base64;
    } else $('tg-qr-img').removeAttribute('src');
    $('tg-start').hidden= !['ready_for_qr','scan_qr','expired','error','not_configured'].includes(state);
    const labels={
      not_configured:'Para gerar o QR Code, abra a configuração de acesso à API logo abaixo.',
      ready_for_qr:'Pronto para conectar. Clique no botão e escaneie o QR Code no Telegram.',
      scan_qr:'Escaneie o QR Code usando o Telegram no celular.',
      password_required:'Digite a senha de verificação em duas etapas.',
      authorized:'Telegram conectado. Escolha seus grupos profissionais.',
      pending:'Aguardando a confirmação do Telegram…',
      starting:'Iniciando conexão com o Telegram…',
      expired:'O QR Code expirou. Gere um novo.',
      error:'Não foi possível conectar. Verifique a rede e tente novamente.',
    };
    message(data.message&&state==='password_required'?data.message:(labels[state]||labels.error));
    // Keep advanced fields folded until the user requests configuration.
    // The main action will open them only when API credentials are missing.
    if(connected) {
      stopPoll();
      if(!pages[activeFolder].loaded&&!fetchingGroups)loadGroups();
      renderList();
    } else {
      $('tg-authorized-area').hidden=true;
      $('tg-enabled-area').hidden=true;
    }
  };

  const check=async()=>{
    try {
      const response=await fetch(urls.status,{
        method:'GET',credentials:'same-origin',cache:'no-store',
        headers:{Accept:'application/json'},
      });
      const data=await response.json();
      if(!response.ok)throw new Error(data.message||'Não foi possível verificar a conexão.');
      applyState(data);
      if(['scan_qr','starting','pending','password_required'].includes(data.status)) {
        if(poll===null)poll=setInterval(check,4000);
      } else stopPoll();
    } catch(error){
      message('Não foi possível verificar o Telegram. Tente novamente.');
      stopPoll();
    }
  };
  $('tg-refresh').addEventListener('click',check);
  $('tg-start').addEventListener('click',async()=>{
    if(currentState==='not_configured'){
      $('tg-advanced-settings').open=true;
      message('Preencha o ID e o hash da API uma vez para habilitar o QR Code.');
      $('tg-api-id').focus();
      return;
    }
    const button=$('tg-start');
    button.disabled=true;
    message('Preparando a conexão…');
    try {
      const data=await post(urls.qr);
      applyState(data);
      if(['scan_qr','starting','pending'].includes(data.status)){
        stopPoll();poll=setInterval(check,4000);
      }
    } catch(error){
      message(error.message);
      if(error.message.toLocaleLowerCase().includes('configur')){
        $('tg-advanced-settings').open=true;
      }
    } finally {button.disabled=false;}
  });
  $('tg-2fa-form').addEventListener('submit',async event=>{
    event.preventDefault();
    const input=$('tg-2fa-password');
    const password=input.value;
    input.value='';
    if(!password)return;
    const button=$('tg-2fa-form').querySelector('button');
    button.disabled=true;
    try {
      applyState(await post(urls.twoFactor,{password}));
      await check();
    } catch(error){message(error.message);}
    finally{button.disabled=false;}
  });
  $('tg-search').addEventListener('input',renderList);
  $('tg-sort').addEventListener('change',renderList);
  $('tg-folder-active').addEventListener('click',()=>switchFolder(0));
  $('tg-folder-archived').addEventListener('click',()=>switchFolder(1));
  $('tg-reload-groups').addEventListener('click',reloadCurrent);
  $('tg-load-more').addEventListener('click',loadGroups);
  $('tg-clear-selected').addEventListener('click',()=>{
    for(const id of selectedSelection){
      if(loadedGroups.get(id)?.recommended)manuallyExcludedSuggestions.add(id);
    }
    selectedSelection.clear();
    renderList();
  });
  // One more Telegram page is requested only following an actual scroll.
  $('tg-group-list').addEventListener('scroll',()=>{
    const list=$('tg-group-list');
    const page=pages[activeFolder];
    if(page.loaded&&!fetchingGroups&&page.cursor!==null&&
       list.scrollHeight>list.clientHeight &&
       list.scrollTop+list.clientHeight>=list.scrollHeight-85){
      loadGroups();
    }
  });
  $('tg-groups-form').addEventListener('submit',async event=>{
    event.preventDefault();
    if(!initialized||!dirty()||selectedSelection.size>40)return;
    const button=$('tg-save-groups');
    const values=Array.from(selectedSelection);
    button.disabled=true;
    try {
      const data=await post(urls.saveGroups,{groups:values});
      if(data.status!=='saved')throw new Error('A seleção não foi salva.');
      enabled=false;
      savedSelection=new Set(values);
      suggestionsAllowed=false;
      $('tg-groups-status').textContent=data.selected_count+
        ' grupo(s) autorizado(s) localmente. '+
        (values.length===0?'A análise continua desativada.':
          'Clique em ativar para iniciar a análise.');
    } catch(error){
      $('tg-groups-status').textContent=error.message;
    } finally {renderList();}
  });
  $('tg-activate').addEventListener('click',async()=>{
    const button=$('tg-activate');
    button.disabled=true;
    try {
      const data=await post(urls.activate);
      enabled=data.enabled===true;
      $('tg-groups-status').textContent=enabled?
        'Análise dos grupos autorizados habilitada. Nenhuma hora foi publicada.':
        'Não foi possível ativar a coleta.';
      renderList();
    } catch(error){ $('tg-groups-status').textContent=error.message;}
    finally{button.disabled=false;}
  });
  check();
})();
