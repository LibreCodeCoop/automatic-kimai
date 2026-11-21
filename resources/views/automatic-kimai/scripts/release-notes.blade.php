(() => {
  const native = window.AutomaticKimaiUpdates;
  const dialog = document.getElementById('release-notes-dialog');
  const manual = document.getElementById('updates-release-notes');
  const versionLabel = document.getElementById('release-notes-version');
  const content = document.getElementById('release-notes-content');
  const retry = document.getElementById('release-notes-retry');
  const dismiss = document.getElementById('release-notes-dismiss');
  if (!dialog || !manual || !versionLabel || !content || !retry || !dismiss ||
      typeof native?.status !== 'function' || typeof native?.releaseNotes !== 'function') return;

  // Public GitHub Markdown must never be interpreted as HTML.
  function renderSafeMarkdown(markdown) {
    content.replaceChildren();
    const lines = String(markdown).slice(0, 18000).split(/\r?\n/).slice(0, 300);
    let list = null;
    for (const raw of lines) {
      const line = raw.trim();
      if (!line) { list = null; continue; }
      const heading = /^#{1,4}\s+(.+)$/.exec(line);
      const bullet = /^(?:[-*]|\d+\.)\s+(.+)$/.exec(line);
      if (heading) {
        list = null;
        const element = document.createElement('h3');
        element.textContent = heading[1];
        content.append(element);
      } else if (bullet) {
        if (!list) {
          list = document.createElement('ul');
          content.append(list);
        }
        const item = document.createElement('li');
        item.textContent = bullet[1];
        list.append(item);
      } else {
        list = null;
        const element = document.createElement('p');
        element.textContent = line;
        content.append(element);
      }
    }
    if (!content.children.length) {
      const message = document.createElement('p');
      message.textContent = 'Esta versão não possui notas de lançamento detalhadas.';
      content.append(message);
    }
  }

  let loading = false;
  let successfullyLoaded = false;
  async function loadNotes(onlyIfUpdated = false) {
    if (loading) return;
    loading = true;
    successfullyLoaded = false;
    retry.hidden = true;
    versionLabel.textContent = '';
    content.replaceChildren();
    const loadingMessage = document.createElement('p');
    loadingMessage.textContent = 'Carregando as novidades desta versão…';
    content.append(loadingMessage);
    if (!dialog.open) dialog.showModal();
    try {
      const release = await native.releaseNotes();
      if (onlyIfUpdated && release?.pending !== true) {
        dialog.close();
        return;
      }
      if (!/^\d+\.\d+\.\d+$/.test(release?.version ?? '')) throw new Error('Invalid version');
      versionLabel.textContent = 'Versão ' + release.version;
      if (typeof release.notes !== 'string' || release.error) throw new Error('Release notes unavailable');
      renderSafeMarkdown(release.notes);
      successfullyLoaded = true;
    } catch (_error) {
      content.replaceChildren();
      const message = document.createElement('p');
      message.textContent = 'Não foi possível carregar as novidades. Verifique sua conexão e tente novamente.';
      content.append(message);
      retry.hidden = false;
    } finally {
      loading = false;
    }
  }

  manual.addEventListener('click', () => void loadNotes());
  retry.addEventListener('click', () => void loadNotes());
  dismiss.addEventListener('click', () => dialog.close());
  dialog.addEventListener('close', () => {
    // Offline/failed loads remain pending for a later launch.
    if (successfullyLoaded && typeof native.acknowledgeReleaseNotes === 'function') {
      void native.acknowledgeReleaseNotes().catch(() => {});
    }
    successfullyLoaded = false;
  });
  void native.status().then(status => {
    manual.hidden = status?.packaged !== true;
    if (status?.packaged && status?.releaseNotesPending) void loadNotes(true);
  }).catch(() => {});
})();
