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
  let allGroups=[];
  let enabled=false;
  let currentState='unknown';
  let savedSelection=new Set();
  let selectedSelection=new Set();

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
    if(enabled && altered) {
      $('tg-groups-status').textContent=
        'As alterações só terão efeito após salvar e ativar novamente a análise.';
    }
  };
  const renderGroups=()=>{
    const query=$('tg-search').value.trim().toLocaleLowerCase();
    const list=$('tg-group-list');
    list.replaceChildren();
    const filtered=allGroups.filter(group=>group.title.toLocaleLowerCase().includes(query));
    if(filtered.length===0){
      const p=document.createElement('p');
      p.className='muted';
      p.textContent=allGroups.length===0?'Nenhum grupo encontrado na conta.':'Nenhum grupo corresponde à busca.';
      list.appendChild(p);
    } else {
      const fragment=document.createDocumentFragment();
      for(const group of filtered){
        const id=String(group.id);
        const label=document.createElement('label');
        label.className='telegram-group-option';
        const checkbox=document.createElement('input');
        checkbox.className='tg-group-checkbox';
        checkbox.type='checkbox';
        checkbox.name='groups';
        checkbox.value=id;
        checkbox.checked=selectedSelection.has(id);
        checkbox.addEventListener('change',()=>{
          if(checkbox.checked) selectedSelection.add(id); else selectedSelection.delete(id);
          refreshCount();
        });
        const text=document.createElement('span');
        text.textContent=group.title;
        label.append(checkbox,text);
        fragment.appendChild(label);
      }
      list.appendChild(fragment);
    }
    refreshCount();
  };
  const loadGroups=async (force=false)=>{
    if(fetchingGroups||(groupsLoaded&&!force))return;
    fetchingGroups=true;
    $('tg-groups-status').textContent='Consultando somente a lista de grupos…';
    try {
      const response=await fetch(urls.groups,{
        method:'GET',cache:'no-store',credentials:'same-origin',
        headers:{Accept:'application/json'},
      });
      const data=await response.json();
      if(!response.ok)throw new Error(data.message||'Não foi possível listar grupos.');
      allGroups=Array.isArray(data.groups)?data.groups.filter(group=>
        typeof group.title==='string'&&Number.isSafeInteger(group.id)):[];
      const knownIds=new Set(allGroups.map(group=>String(group.id)));
      const prior=(data.selected_ids||[]).map(String);
      savedSelection=new Set(prior.filter(id=>knownIds.has(id)));
      selectedSelection=new Set(savedSelection);
      groupsLoaded=true;
      renderGroups();
      $('tg-groups-status').textContent=prior.length>savedSelection.size?
        'Alguns grupos autorizados anteriormente não estão mais disponíveis. Confira a seleção antes de salvar.':
        (enabled && prior.length===0?
          'A configuração anterior usava nomes de grupos. Selecione os grupos novamente para tornar a autorização mais precisa.':
          'Escolha os grupos e salve. Nenhum histórico foi consultado nesta etapa.');
    } catch(error){
      $('tg-groups-status').textContent=error.message;
    } finally {fetchingGroups=false;}
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
      loadGroups();
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
  $('tg-search').addEventListener('input',renderGroups);
  $('tg-reload-groups').addEventListener('click',()=>loadGroups(true));
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
