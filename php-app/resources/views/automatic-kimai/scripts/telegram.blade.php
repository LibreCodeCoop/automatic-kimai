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
  let groupsLoaded=false;
  const loadedGroups=new Map();
  let nextCursor=null;
  let scannedDialogs=0;
  let suggestionsAllowed=false;
  let enabled=false;
  let currentState='unknown';
  let savedSelection=new Set();
  let selectedSelection=new Set();
  const manuallyExcludedSuggestions=new Set();

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
  const refreshCount=()=>{
    const count=selectedSelection.size;
    $('tg-group-count').textContent=count===0?'Nenhum grupo selecionado.':
      count===1?'1 grupo selecionado.':count+' grupos selecionados.';
    $('tg-save-groups').disabled=count===0||count>40;
    $('tg-authorized-area').hidden=enabled||savedSelection.size===0;
    $('tg-enabled-area').hidden=!enabled;
    const altered=count!==savedSelection.size ||
      Array.from(selectedSelection).some(id=>!savedSelection.has(id));
    if(enabled && altered){
      $('tg-groups-status').textContent=
        'As alterações só terão efeito após salvar e ativar novamente a análise.';
    }
  };
  const applySearch=()=>{
    const query=$('tg-search').value.trim().toLocaleLowerCase();
    let visible=0;
    for(const [id,group] of loadedGroups){
      const show=group.title.toLocaleLowerCase().includes(query);
      group.element.hidden=!show;
      if(show)visible++;
    }
    $('tg-page-status').textContent=
      loadedGroups.size+' grupos carregados'+
      (query?' ('+visible+' correspondem à busca)':'')+
      (nextCursor===null?' · fim da lista':' · há mais páginas');
  };
  const appendGroup=group=>{
    if(!Number.isSafeInteger(group.id)||typeof group.title!=='string')return;
    const id=String(group.id);
    if(loadedGroups.has(id))return;
    const label=document.createElement('label');
    label.className='telegram-group-option';
    const checkbox=document.createElement('input');
    checkbox.className='tg-group-checkbox';
    checkbox.type='checkbox';
    checkbox.name='groups';
    checkbox.value=id;
    if(suggestionsAllowed&&group.recommended===true&&
       !manuallyExcludedSuggestions.has(id)&&savedSelection.size===0){
      selectedSelection.add(id);
    }
    checkbox.checked=selectedSelection.has(id);
    checkbox.addEventListener('change',()=>{
      if(checkbox.checked)selectedSelection.add(id);
      else {
        selectedSelection.delete(id);
        if(group.recommended===true)manuallyExcludedSuggestions.add(id);
      }
      refreshCount();
    });
    const title=document.createElement('span');
    title.textContent=group.title;
    label.append(checkbox,title);
    if(group.recommended===true){
      const recommendation=document.createElement('span');
      recommendation.className='group-recommendation';
      recommendation.textContent='Grupo público sugerido';
      label.appendChild(recommendation);
    }
    loadedGroups.set(id,{title:group.title,element:label});
    $('tg-group-list').appendChild(label);
  };
  const resetGroupList=()=>{
    loadedGroups.clear();
    nextCursor=null;
    scannedDialogs=0;
    groupsLoaded=false;
    suggestionsAllowed=false;
    savedSelection.clear();
    selectedSelection.clear();
    manuallyExcludedSuggestions.clear();
    $('tg-group-list').replaceChildren();
    $('tg-page-status').textContent='';
    $('tg-load-more').hidden=true;
    refreshCount();
  };
  // Each request advances the Telegram cursor by one bounded raw-dialog page;
  // neither the backend nor the browser first enumerates the entire account.
  const loadGroups=async (force=false)=>{
    if(fetchingGroups)return;
    if(groupsLoaded&&!force&&nextCursor===null)return;
    if(force)resetGroupList();
    fetchingGroups=true;
    const button=$('tg-load-more');
    button.disabled=true;
    $('tg-groups-status').textContent='Carregando a próxima página de grupos…';
    try {
      const url=new URL(urls.groups,location.origin);
      if(nextCursor!==null)url.searchParams.set('cursor',nextCursor);
      const response=await fetch(url.toString(),{
        method:'GET',cache:'no-store',credentials:'same-origin',
        headers:{Accept:'application/json'},
      });
      const data=await response.json();
      if(!response.ok)throw new Error(data.message||'Não foi possível listar grupos.');
      if(!Array.isArray(data.groups)||data.groups.length>30||
         !(data.next_cursor===null || typeof data.next_cursor==='string')){
        throw new Error('Resposta de paginação inválida. Atualize a lista.');
      }
      if(!groupsLoaded){
        const prior=(data.selected_ids||[]).map(String);
        savedSelection=new Set(prior);
        selectedSelection=new Set(prior);
        suggestionsAllowed=data.suggestions_allowed===true;
        groupsLoaded=true;
      }
      if(!Array.isArray(data.recommended_groups)||
         data.recommended_groups.length>2){
        throw new Error('Resposta de recomendações inválida.');
      }
      // Verified joined groups may appear deep in Telegram's dialog ordering.
      // Show these at the top on the first page without fetching all dialogs.
      for(const group of data.recommended_groups)appendGroup(group);
      for(const group of data.groups)appendGroup(group);
      if(Number.isSafeInteger(data.scanned_dialog_count)){
        scannedDialogs+=data.scanned_dialog_count;
      }
      nextCursor=data.next_cursor;
      button.hidden=nextCursor===null;
      if(loadedGroups.size===0){
        const empty=document.createElement('p');
        empty.className='muted';
        empty.textContent=nextCursor===null?'Nenhum grupo encontrado na conta.':
          'Nenhum grupo nesta página. Carregue a próxima para continuar.';
        $('tg-group-list').replaceChildren(empty);
      }else{
        for(const placeholder of $('tg-group-list').querySelectorAll('p.muted')){
          placeholder.remove();
        }
      }
      applySearch();
      refreshCount();
      $('tg-groups-status').textContent=
        'Lista parcial: '+scannedDialogs+' diálogos verificados por páginas. '+
        (nextCursor===null?'Fim da lista.':
          'Continue rolando ou clique em Carregar mais grupos.')+
        ' Nenhum histórico de mensagens foi consultado.';
    }catch(error){
      $('tg-groups-status').textContent=error.message;
      button.hidden=false;
    }finally{
      fetchingGroups=false;
      button.disabled=false;
    }
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
      if(!groupsLoaded)loadGroups();
      refreshCount();
    } else {
      groupsLoaded=false;
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
  $('tg-search').addEventListener('input',applySearch);
  $('tg-reload-groups').addEventListener('click',()=>loadGroups(true));
  $('tg-load-more').addEventListener('click',()=>loadGroups());
  // Lazy load one additional page only after a real user scroll event.
  $('tg-group-list').addEventListener('scroll',()=>{
    const list=$('tg-group-list');
    if(groupsLoaded&&!fetchingGroups&&nextCursor!==null&&
       list.scrollHeight>list.clientHeight &&
       list.scrollTop+list.clientHeight>=list.scrollHeight-85){
      loadGroups();
    }
  });
  $('tg-groups-form').addEventListener('submit',async event=>{
    event.preventDefault();
    const button=$('tg-save-groups');
    const values=Array.from(selectedSelection);
    if(values.length===0||values.length>40)return;
    button.disabled=true;
    try {
      const data=await post(urls.saveGroups,{groups:values});
      if(data.status!=='saved')throw new Error('A seleção não foi salva.');
      enabled=false;
      savedSelection=new Set(values);
      $('tg-groups-status').textContent=data.selected_count+' grupo(s) autorizado(s) localmente. Clique em ativar para iniciar a análise.';
      refreshCount();
    } catch(error){ $('tg-groups-status').textContent=error.message;}
    finally{refreshCount();}
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
      refreshCount();
    } catch(error){ $('tg-groups-status').textContent=error.message;}
    finally{button.disabled=false;}
  });
  check();
})();
