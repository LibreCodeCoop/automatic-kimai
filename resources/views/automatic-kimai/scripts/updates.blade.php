(() => {
  const updater = window.AutomaticKimaiUpdates;
  if (!updater) return; // Regular web browsers never control desktop updates.
  const el = id => document.getElementById(id);
  const trigger = el('updates-trigger');
  const panel = el('updates-panel');
  const triggerLabel = el('updates-trigger-label');
  const description = el('updates-description');
  const check = el('updates-check');
  const download = el('updates-download');
  const restart = el('updates-restart');
  const manual = el('updates-manual');
  const progress = el('updates-progress');
  const dismiss = el('updates-dismiss');
  if (![trigger, panel, triggerLabel, description, check, download, restart, manual, progress, dismiss].every(Boolean)) return;
  trigger.hidden = false;
  let wasAlertedFor = '';
  let status = null;
  const setOpen = open => {
    panel.hidden = !open;
    trigger.setAttribute('aria-expanded', String(open));
  };
  const draw = next => {
    if (!next || typeof next !== 'object') return;
    status = next;
    const phase = String(next.phase || 'idle');
    const version = typeof next.version === 'string' ? next.version : '';
    const current = typeof next.currentVersion === 'string' ? next.currentVersion : '';
    const available = phase === 'available';
    const ready = phase === 'ready';
    const downloading = phase === 'downloading';
    const checking = phase === 'checking';
    const installing = phase === 'installing';

    triggerLabel.textContent = available || downloading || ready ? 'Atualização ' + version : 'Atualizações';
    trigger.dataset.available = String(available || downloading || ready);
    if ((available || ready) && version && wasAlertedFor !== version) {
      wasAlertedFor = version;
      setOpen(true);
    }

    const labels = {
      idle: 'Versão instalada: ' + current + '. Verifique se há atualizações disponíveis.',
      checking: 'Procurando uma versão mais recente…',
      current: 'Você já está usando a versão mais recente (' + current + ').',
      downloading: 'Baixando versão ' + version + '… ' + Math.round(Number(next.progress) || 0) + '%',
      ready: 'Versão ' + version + ' baixada e verificada. Reinicie para concluir a instalação.',
      installing: 'Fechando o aplicativo para concluir a atualização…',
      unavailable: 'As atualizações integradas estão disponíveis apenas no aplicativo instalado.',
      error: 'Não foi possível concluir a atualização.',
      available: 'Nova versão ' + version + ' disponível (instalada: ' + current + ').',
    };
    description.textContent = labels[phase] || labels.idle;
    if (next.message && ['available', 'error', 'unavailable'].includes(phase)) {
      description.textContent += ' ' + String(next.message);
    }
    check.hidden = downloading || ready || installing;
    check.disabled = checking;
    download.hidden = !(available && next.mode === 'automatic');
    restart.hidden = !ready;
    manual.hidden = !(available && next.mode !== 'automatic') && phase !== 'error';
    progress.hidden = !downloading;
    progress.value = Math.max(0, Math.min(100, Number(next.progress) || 0));
    trigger.disabled = installing;
  };
  const action = async (method) => {
    try {
      const result = await updater[method]();
      if (result && typeof result === 'object') draw(result);
    } catch (_error) {
      description.textContent = 'Não foi possível executar esta ação. Tente novamente ou utilize o download oficial.';
      manual.hidden = false;
    }
  };
  trigger.addEventListener('click', () => {
    setOpen(panel.hidden);
    if (!panel.hidden && (!status || status.phase === 'idle')) void action('check');
  });
  dismiss.addEventListener('click', () => setOpen(false));
  check.addEventListener('click', () => void action('check'));
  download.addEventListener('click', () => void action('download'));
  restart.addEventListener('click', () => void action('restart'));
  manual.addEventListener('click', () => void action('openReleases'));
  updater.onStatus(draw);
  void updater.status().then(draw).catch(() => {
    description.textContent = 'O serviço de atualizações está temporariamente indisponível.';
  });
})();
