(() => {
  const updater = window.AutomaticKimaiUpdates;
  const hasNativeUpdater = Boolean(updater && typeof updater.status === 'function');
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
  const footerCheck = el('app-check-updates');
  const footerVersion = el('app-version-label');
  if (![trigger, panel, triggerLabel, description, check, download, restart,
        manual, progress, dismiss, footerCheck, footerVersion].every(Boolean)) return;

  // Electron packages have a privileged, fixed-purpose updater. The local
  // preview and ordinary browser may only consult the public release number.
  trigger.hidden = !hasNativeUpdater;
  let wasAlertedFor = '';
  let status = null;
  let previewChecking = false;
  const validVersion = v => typeof v === 'string' &&
    /^\d+\.\d+\.\d+(?:-[\da-zA-Z.-]+)?$/.test(v);
  const setOpen = open => {
    panel.hidden = !open;
    trigger.setAttribute('aria-expanded', String(open));
    footerCheck.setAttribute('aria-expanded', String(open));
  };
  const draw = next => {
    if (!next || typeof next !== 'object') return;
    status = next;
    const phase = String(next.phase || 'idle');
    const version = validVersion(next.version) ? next.version : '';
    const current = validVersion(next.currentVersion) ? next.currentVersion : '';
    const isPackage = next.packaged !== false;
    const available = phase === 'available';
    const ready = phase === 'ready';
    const downloading = phase === 'downloading';
    const checking = phase === 'checking';
    const installing = phase === 'installing';

    footerVersion.textContent = isPackage && current ? 'Versão ' + current : 'Prévia local';
    triggerLabel.textContent = available || downloading || ready ? 'Atualização ' + version : 'Atualizações';
    trigger.dataset.available = String(available || downloading || ready);
    if ((available || ready) && version && wasAlertedFor !== version) {
      wasAlertedFor = version;
      setOpen(true);
    }

    const labels = {
      idle: 'Versão instalada: ' + (current || 'não identificada') + '. Verifique se há atualizações disponíveis.',
      checking: 'Procurando uma versão mais recente…',
      current: 'Você já está usando a versão mais recente (' + current + ').',
      downloading: 'Baixando versão ' + version + '… ' + Math.round(Number(next.progress) || 0) + '%',
      ready: 'Versão ' + version + ' baixada e verificada. Reinicie para concluir a instalação.',
      installing: 'Fechando o aplicativo para concluir a atualização…',
      unavailable: 'Esta é uma prévia de desenvolvimento, não uma versão instalada. Consulte a última release oficial.',
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
    manual.hidden = !(available && next.mode !== 'automatic') && phase !== 'error' && phase !== 'unavailable';
    progress.hidden = !downloading;
    progress.value = Math.max(0, Math.min(100, Number(next.progress) || 0));
    trigger.disabled = installing;
    footerCheck.disabled = installing;
  };
  const action = async method => {
    try {
      const result = await updater[method]();
      if (result && typeof result === 'object') draw(result);
    } catch (_error) {
      description.textContent = 'Não foi possível executar esta ação. Tente novamente ou utilize o download oficial.';
      manual.hidden = false;
    }
  };

  const checkPreviewRelease = async () => {
    if (previewChecking) return;
    previewChecking = true;
    check.disabled = true;
    description.textContent = 'Consultando a última versão oficial…';
    manual.hidden = true;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 9000);
    try {
      const response = await fetch(footerCheck.dataset.releaseCheckUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      if (!response.ok) throw new Error('Release lookup failed');
      const data = await response.json();
      if (!data || !validVersion(data.version)) throw new Error('Invalid release metadata');
      footerVersion.textContent = 'Prévia local · última oficial: v' + data.version;
      description.textContent =
        'Esta é uma prévia local, sem versão oficial instalada. Última versão publicada: v' +
        data.version + '. Para atualizar este ambiente de desenvolvimento, use o processo de implantação. ' +
        'Você pode consultar e instalar a versão distribuída separadamente.';
      manual.hidden = false;
    } catch (_error) {
      description.textContent = 'Não foi possível consultar a versão oficial. Confira a conexão e tente novamente.';
      manual.hidden = false;
    } finally {
      clearTimeout(timeout);
      check.disabled = false;
      previewChecking = false;
    }
  };

  const useNative = () => hasNativeUpdater && status?.packaged !== false;
  const checkNow = () => {
    setOpen(true);
    if (useNative()) {
      if (!status || !['downloading', 'ready', 'installing'].includes(status.phase)) void action('check');
    } else {
      void checkPreviewRelease();
    }
  };
  footerCheck.addEventListener('click', checkNow);
  trigger.addEventListener('click', () => {
    const wasClosed = panel.hidden;
    setOpen(wasClosed);
    if (wasClosed && (!status || status.phase === 'idle')) checkNow();
  });
  dismiss.addEventListener('click', () => setOpen(false));
  check.addEventListener('click', checkNow);
  download.addEventListener('click', () => { if (hasNativeUpdater) void action('download'); });
  restart.addEventListener('click', () => { if (hasNativeUpdater) void action('restart'); });
  manual.addEventListener('click', event => {
    if (hasNativeUpdater) {
      event.preventDefault();
      void action('openReleases');
    }
    // Non-packaged previews open only the fixed public releases URL.
  });
  if (hasNativeUpdater) {
    updater.onStatus(draw);
    footerVersion.textContent = 'Verificando versão…';
    void updater.status().then(draw).catch(() => {
      footerVersion.textContent = 'Versão indisponível';
      description.textContent = 'O serviço de atualizações está temporariamente indisponível.';
    });
  }
})();
