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
    catalog:@json(route('kimai.telegram.catalog')),
    communities:@json(route('kimai.telegram.communities')),
    avatar:@json(route('kimai.telegram.avatar')),
    disconnect:@json(route('kimai.telegram.disconnect')),
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
  const searchMatches=new Set();
  const nativeCommunities=[];
  // Volatile catalog: loaded once per page lifetime and reused across queries.
  // No chat list, group names or search strings enter persistent browser storage.
  const catalogFolders={
    0:{cursor:null,done:false,requests:0,scanned:0},
    1:{cursor:null,done:false,requests:0,scanned:0},
  };
  let searchTimer=null;
  let catalogRunning=false;
  let catalogController=null;
  let catalogError='';
  let catalogLimit=12;
  let communitiesLoaded=false;
  let avatarRequested=false;
  const pages={
    0:{cursor:null,loaded:false,scanned:0,seen:0,loading:false,error:false,errorMessage:''},
    1:{cursor:null,loaded:false,scanned:0,seen:0,loading:false,error:false,errorMessage:''},
  };
  const collator=new Intl.Collator('pt-BR',{sensitivity:'base',numeric:true});

  const message=value=>{status.textContent=value;};
  const stopPoll=()=>{if(poll!==null)clearInterval(poll);poll=null;};
  const post=async (url,payload={},signal=null)=>{
    const response=await fetch(url,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
      body:JSON.stringify(payload),signal,
    });
    const data=await response.json();
    if(!response.ok)throw new Error(data.message||'Operação não concluída. Tente novamente.');
    return data;
  };
  const dirty=()=>selectedSelection.size!==savedSelection.size ||
    [...selectedSelection].some(id=>!savedSelection.has(id));
  // Keep catalog rows and checkboxes in their original DOM positions.
  // Re-render only the lightweight selected summary after each click.
  const renderSelection=()=>{
    const selected=[...loadedGroups.values()].filter(group=>selectedSelection.has(group.id));
    selected.sort((a,b)=>collator.compare(a.title,b.title));
    const chips=document.createDocumentFragment();
    for(const group of selected){
      const chip=document.createElement('div');
      chip.className='telegram-selected-chip';
      const title=document.createElement('span');
      title.textContent=group.title;
      const remove=document.createElement('button');
      remove.type='button';
      remove.className='secondary';
      remove.textContent='Remover';
      remove.setAttribute('aria-label','Remover '+group.title+' da seleção');
      remove.addEventListener('click',()=>{
        selectedSelection.delete(group.id);
        if(group.recommended)manuallyExcludedSuggestions.add(group.id);
        renderList();
      });
      chip.append(title,remove);
      chips.append(chip);
    }
    $('tg-selected-list').replaceChildren(chips);
    $('tg-selected-empty').hidden=selectedSelection.size!==0;
    $('tg-selected-list').hidden=selectedSelection.size===0;
    $('tg-selected-count').textContent=String(selectedSelection.size);
    $('tg-selection-summary').textContent=selectedSelection.size+' grupos selecionados';
    $('tg-group-count').textContent=selectedSelection.size===0?
      'Nenhum grupo selecionado.':selectedSelection.size===1?
        '1 grupo selecionado.':selectedSelection.size+' grupos selecionados.';
    const hasChanges=dirty();
    $('tg-save-groups').disabled=!initialized||!hasChanges;
    $('tg-authorized-area').hidden=enabled||savedSelection.size===0||hasChanges;
    $('tg-activate').disabled=hasChanges;
    $('tg-enabled-area').hidden=!enabled;
    $('tg-suggestion-note').hidden=!(hasChanges && suggestionsAllowed &&
      selected.some(group=>group.recommended));
    for(const group of loadedGroups.values()){
      group.checkbox.checked=selectedSelection.has(group.id);
    }
    for(const control of $('tg-communities-list').querySelectorAll('[data-community-group]')){
      const selected=selectedSelection.has(control.dataset.communityGroup);
      control.checked=selected;
      control.closest('label').hidden=selected;
    }
  };
  const refreshSearchMatches=()=>{
    searchMatches.clear();
    const q=$('tg-search').value.trim().toLocaleLowerCase();
    if(q.length<2)return;
    for(const group of loadedGroups.values()){
      if(group.title.toLocaleLowerCase().includes(q))searchMatches.add(group.id);
    }
    for(const community of nativeCommunities){
      if(community.title.toLocaleLowerCase().includes(q)){
        for(const group of community.groups)searchMatches.add(String(group.id));
      }
    }
  };
  const renderList=()=>{
    refreshSearchMatches();
    const current=pages[activeFolder];
    const search=$('tg-search').value.trim().toLocaleLowerCase();
    const alphabetically=$('tg-sort').value==='name';
    const previousScroll=$('tg-group-list').scrollTop;
    const searching=search.length>=2;
    const others=[...loadedGroups.values()].filter(group=>
      !selectedSelection.has(group.id) &&
      (searching ? searchMatches.has(group.id) :
        (group.folders.has(activeFolder) || group.recommended)));
    others.sort((a,b)=>alphabetically?
      collator.compare(a.title,b.title):
      (a.orders[activeFolder]??Number.MAX_SAFE_INTEGER)-
      (b.orders[activeFolder]??Number.MAX_SAFE_INTEGER));
    const children=[];
    let visible=0;
    for(const group of others){
      group.checkbox.checked=selectedSelection.has(group.id);
      group.element.hidden=!searching&&search!==''&&!group.title.toLocaleLowerCase().includes(search);
      if(!group.element.hidden)visible++;
      children.push(group.element);
    }
    if(others.length===0){
      const note=document.createElement('p');
      note.className='muted';
      if(current.error)note.setAttribute('role','alert');
      note.textContent=searching ?
        (catalogRunning?'Procurando mais grupos no Telegram…':
          catalogFolders[0].done&&catalogFolders[1].done?
            'Nenhum outro grupo disponível com esse nome.':
            'Nenhum outro grupo encontrado até agora.') :
        current.error?current.errorMessage:
        current.loading?'Carregando a primeira página…':
        current.loaded?(current.cursor===null?'Nenhum outro grupo nesta categoria.':
          'Nenhum outro grupo nesta página. Carregue mais para continuar.'):
        'Escolha uma categoria para carregar seus grupos.';
      children.push(note);
    }
    const catalog=$('tg-group-list');
    // Incremental reconciliation: incoming search pages must not detach
    // existing checkboxes while the person is clicking through the list.
    for(let index=0;index<children.length;index++){
      const node=children[index];
      if(catalog.children[index]!==node){
        catalog.insertBefore(node,catalog.children[index]||null);
      }
    }
    while(catalog.children.length>children.length)catalog.lastElementChild.remove();
    catalog.scrollTop=previousScroll;
    $('tg-page-status').textContent=searching ?
      visible+' grupos disponíveis na busca'+(catalogRunning?' · procurando mais…':'') :
      current.seen+' grupos carregados'+
      (current.cursor!==null?' · há mais páginas':current.loaded?' · fim da lista':'');
    $('tg-load-more').hidden=searching||!(current.error || (current.loaded&&current.cursor!==null));
    $('tg-load-more').disabled=fetchingGroups;
    $('tg-reload-groups').disabled=fetchingGroups||catalogRunning;
    $('tg-load-more').textContent=current.error?
      'Tentar carregar novamente':'Carregar mais grupos';
    renderSelection();
    showSearchProgress();
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
    if(source.archived===true && group.archiveBadge===undefined){
      group.archiveBadge=document.createElement('span');
      group.archiveBadge.className='group-recommendation';
      group.archiveBadge.textContent='Arquivado';
      group.element.appendChild(group.archiveBadge);
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
      if(suggestionsAllowed&&!manuallyExcludedSuggestions.has(id))selectedSelection.add(id);
    }
  };
  const requestJson=async (url,signal) => {
    const response=await fetch(url,{
      credentials:'same-origin',cache:'no-store',
      headers:{Accept:'application/json'},signal,
    });
    const data=await response.json();
    if(!response.ok)throw new Error(data.message||'Consulta indisponível.');
    return data;
  };

  const loadCommunities=async()=>{
    if(communitiesLoaded)return;
    communitiesLoaded=true;
    $('tg-communities-status').textContent='Consultando comunidades vinculadas…';
    try{
      const controller=new AbortController();
      const timer=setTimeout(()=>controller.abort(),18000);
      let data;
      try{data=await requestJson(urls.communities,controller.signal);}
      finally{clearTimeout(timer);}
      if(data.status!=='ok'||!Array.isArray(data.communities)||data.communities.length>15) {
        throw new Error('Formato inesperado das comunidades.');
      }
      const fragment=document.createDocumentFragment();
      for(const item of data.communities) {
        if(!item||!Number.isSafeInteger(item.id)||
           typeof item.title!=='string'||!Array.isArray(item.groups))continue;
        const children=item.groups.filter(g=>g&&Number.isSafeInteger(g.id)&&
           typeof g.title==='string'&&g.title.trim()!=='');
        if(children.length>120)continue;
        const detail=document.createElement('details');
        detail.className='telegram-community';
        const summary=document.createElement('summary');
        const name=document.createElement('strong');
        name.textContent=item.title;
        const count=document.createElement('small');
        count.textContent=children.length+' grupo(s) vinculados';
        summary.append(name,count);
        detail.append(summary);
        const content=document.createElement('div');
        content.className='telegram-community-content';
        const info=document.createElement('p');
        info.className='muted';
        info.textContent='Somente grupos em que você participa; canais e bots ficam fora da análise.';
        content.append(info);
        if(children.length){
          const selectAll=document.createElement('button');
          selectAll.type='button';
          selectAll.className='secondary';
          selectAll.textContent='Selecionar grupos desta comunidade';
          selectAll.addEventListener('click',()=>{
            const ids=children.map(g=>String(g.id));
            const fresh=ids.filter(id=>!selectedSelection.has(id));
            for(const id of fresh)selectedSelection.add(id);
            renderList();
          });
          content.append(selectAll);
          const list=document.createElement('div');
          list.className='telegram-community-members';
          for(const child of children){
            upsertGroup(child);
            const label=document.createElement('label');
            label.className='telegram-group-option';
            const input=document.createElement('input');
            input.type='checkbox';
            input.dataset.communityGroup=String(child.id);
            input.addEventListener('change',()=>{
              const id=String(child.id);
              if(input.checked){
                selectedSelection.add(id);
              }else selectedSelection.delete(id);
              renderList();
            });
            const text=document.createElement('span');
            text.textContent=child.title;
            label.append(input,text);
            list.append(label);
          }
          content.append(list);
        } else {
          const empty=document.createElement('p');
          empty.className='muted';
          empty.textContent='Nenhum grupo desta comunidade está disponível para coleta nesta conta.';
          content.append(empty);
        }
        detail.append(content);
        fragment.append(detail);
        nativeCommunities.push({id:String(item.id),title:item.title,groups:children});
      }
      $('tg-communities-list').replaceChildren(fragment);
      $('tg-communities-area').hidden=nativeCommunities.length===0;
      $('tg-communities-status').textContent=nativeCommunities.length+
        ' comunidades disponíveis; selecione apenas os grupos autorizados.';
      const query=$('tg-search').value.trim().toLocaleLowerCase();
      if(query.length>=2){
        for(const community of nativeCommunities){
          if(community.title.toLocaleLowerCase().includes(query)){
            for(const group of community.groups)searchMatches.add(String(group.id));
          }
        }
      }
      renderList();
    }catch(_error){
      communitiesLoaded=false;
      $('tg-communities-area').hidden=false;
      $('tg-communities-status').textContent='Não foi possível listar as comunidades. Atualize a página para tentar novamente.';
    }
  };

  const catalogComplete=()=>catalogFolders[0].done&&catalogFolders[1].done;
  const catalogAtLimit=()=>[0,1].every(folder=>
    catalogFolders[folder].done||catalogFolders[folder].requests>=catalogLimit);
  const showSearchProgress=()=>{
    const q=$('tg-search').value.trim();
    if(q.length<2){
      $('tg-search-status').textContent='Digite pelo menos 2 caracteres. Os grupos já consultados ficam disponíveis imediatamente.';
      $('tg-reload-groups').textContent='Atualizar lista';
      return;
    }
    const scanned=catalogFolders[0].scanned+catalogFolders[1].scanned;
    const matched=searchMatches.size;
    const prefix=matched+' grupos encontrados · '+scanned+' diálogos adicionais consultados.';
    $('tg-reload-groups').textContent=catalogComplete()?'Atualizar busca':
      catalogAtLimit()?'Continuar busca':'Atualizar lista';
    $('tg-search-status').textContent=catalogError?
      prefix+' Busca parcial: '+catalogError+' Use Atualizar lista para tentar novamente.':
      catalogRunning?prefix+' Procurando mais, incluindo arquivados…':
      catalogComplete()?prefix+' Busca concluída. Alterar o texto não refaz chamadas à API.':
      catalogAtLimit()?prefix+' Busca parcial por limite de segurança. Clique em Continuar busca.':
      prefix+' Mostrando resultados locais; as demais páginas serão consultadas.';
  };

  /**
   * Build a volatile, account-scoped catalog in the current browser page.
   * Unlike the previous query-bound RPC search, one catalog page is read
   * only once even if the person changes the search term many times.
   */
  const searchAll=async()=>{
    if(catalogRunning||catalogComplete()||catalogAtLimit())return;
    if($('tg-search').value.trim().length<2)return;
    catalogRunning=true;
    catalogError='';
    const controller=new AbortController();
    catalogController=controller;
    renderList();
    try{
      while(!catalogComplete()&&!catalogAtLimit()&&
            $('tg-search').value.trim().length>=2&&!controller.signal.aborted){
        // Alternate folders so archived matches are not delayed until all
        // non-archived groups have been scanned.
        for(const folder of [0,1]){
          const state=catalogFolders[folder];
          if(state.done||state.requests>=catalogLimit||
             controller.signal.aborted||$('tg-search').value.trim().length<2)continue;
          const payload={folder:String(folder)};
          if(state.cursor!==null)payload.cursor=state.cursor;
          const timeout=setTimeout(()=>controller.abort('timeout'),16000);
          let data;
          try{data=await post(urls.catalog,payload,controller.signal);}
          finally{clearTimeout(timeout);}
          if(data.status!=='ok'||data.folder!==folder||
             !Array.isArray(data.groups)||data.groups.length>200||
             !Number.isSafeInteger(data.scanned_dialog_count)||
             data.scanned_dialog_count<0||data.scanned_dialog_count>200||
             !(data.next_cursor===null||
               (typeof data.next_cursor==='string'&&data.next_cursor.length<=2048))||
             (state.cursor!==null&&data.next_cursor===state.cursor)){
            throw new Error('Resposta de catálogo inválida.');
          }
          // Catalog browsing must never add a group to the allowlist
          // merely because the Telegram username is a known suggestion.
          for(const group of data.groups)upsertGroup({...group,recommended:false});
          state.scanned+=data.scanned_dialog_count;
          state.requests++;
          state.cursor=data.next_cursor;
          state.done=data.next_cursor===null;
          renderList();
        }
      }
    }catch(error){
      if(controller.signal.reason!=='query-cleared'){
        catalogError=controller.signal.reason==='timeout'?
          'Uma página excedeu o tempo limite de 16 segundos.':
          (error.message||'Falha de comunicação.');
      }
    }finally{
      catalogRunning=false;
      catalogController=null;
      renderList();
      if(controller.signal.reason==='query-cleared'&&
         $('tg-search').value.trim().length>=2&&!catalogAtLimit()){
        searchTimer=setTimeout(()=>void searchAll(),250);
      }
    }
  };

  const reloadCurrent=()=>{
    if($('tg-search').value.trim().length>=2){
      // Explicit retries never discard already indexed groups.
      if(catalogComplete()){
        for(const folder of [0,1]){
          Object.assign(catalogFolders[folder],
            {cursor:null,done:false,requests:0,scanned:0});
        }
        catalogLimit=12;
      }else if(catalogError.includes('expirado')){
        // A 20-minute encrypted cursor can expire during a long editing
        // session. Restart only on explicit retry, preserving indexed rows.
        for(const folder of [0,1]){
          if(!catalogFolders[folder].done){
            Object.assign(catalogFolders[folder],
              {cursor:null,done:false,requests:0,scanned:0});
          }
        }
        catalogLimit=12;
      }else if(catalogAtLimit()){
        catalogLimit+=12;
      }
      catalogError='';
      void searchAll();
      return;
    }
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

  const avatar=$('tg-avatar');
  avatar.addEventListener('load',()=>{
    avatar.hidden=false;
    $('tg-avatar-fallback').hidden=true;
  });
  avatar.addEventListener('error',()=>{
    avatar.hidden=true;
    $('tg-avatar-fallback').hidden=false;
  });
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
    if(connected){
      const account=data.account||{};
      const name=typeof account.display_name==='string' ?
        account.display_name:'Conta Telegram conectada';
      $('tg-account-name').textContent=name;
      $('tg-account-username').textContent=typeof account.username==='string'&&account.username?
        '@'+account.username:'Identidade verificada no Telegram';
      $('tg-avatar-fallback').textContent=(Array.from(name)[0]||'T').toLocaleUpperCase();
      if(!avatarRequested){
        avatarRequested=true;
        avatar.src=urls.avatar;
      }
    }
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
      if(!communitiesLoaded)void loadCommunities();
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
  $('tg-search').addEventListener('input',()=>{
    if(searchTimer!==null)clearTimeout(searchTimer);
    const q=$('tg-search').value.trim();
    if(q.length<2 && catalogController){
      catalogController.abort('query-cleared');
    }
    // Update immediately from the in-memory catalog, including groups
    // found during older searches. Do not repeat MTProto pagination.
    renderList();
    if(q.length>=2 && !catalogRunning&&!catalogComplete()&&!catalogAtLimit()&&
       catalogError===''){
      searchTimer=setTimeout(()=>void searchAll(),320);
    }
  });
  $('tg-sort').addEventListener('change',renderList);
  $('tg-folder-active').addEventListener('click',()=>switchFolder(0));
  $('tg-folder-archived').addEventListener('click',()=>switchFolder(1));
  $('tg-reload-groups').addEventListener('click',reloadCurrent);
  $('tg-disconnect').addEventListener('click',async()=>{
    if(!window.confirm('Desconectar a conta Telegram deste dispositivo? Isso revoga a sessão e apaga a seleção e os metadados locais dos grupos. As horas no Kimai não serão alteradas.'))return;
    const button=$('tg-disconnect');
    button.disabled=true;
    $('tg-disconnect-status').textContent='Encerrando sessão do Telegram…';
    try{
      const result=await post(urls.disconnect);
      if(result.status!=='disconnected')throw new Error('Falha ao desconectar.');
      $('tg-disconnect-status').textContent='Telegram desconectado. Atualizando a tela…';
      window.location.reload();
    }catch(error){
      $('tg-disconnect-status').textContent=error.message;
      button.disabled=false;
    }
  });
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
    if($('tg-search').value.trim().length<2&&
       page.loaded&&!fetchingGroups&&page.cursor!==null&&
       list.scrollHeight>list.clientHeight &&
       list.scrollTop+list.clientHeight>=list.scrollHeight-85){
      loadGroups();
    }
  });
  $('tg-groups-form').addEventListener('submit',async event=>{
    event.preventDefault();
    if(!initialized||!dirty())return;
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
    } finally {renderSelection();}
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
