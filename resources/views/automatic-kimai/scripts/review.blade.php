(() => {
  // Never hold the review page's first paint on an external Kimai request.
  // Load the catalog only when the user opens an eligible review proposal.
  const forms = [...document.querySelectorAll('form[data-review-catalog]')];
  if (!forms.length) return;
  const endpoint = @json(route('kimai.catalog'));
  let pendingCatalog = null;

  function catalog() {
    if (pendingCatalog !== null) return pendingCatalog;
    pendingCatalog = fetch(endpoint, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' },
    }).then(async (response) => {
      if (!response.ok) throw new Error('O catálogo do Kimai está indisponível.');
      const data = await response.json();
      if (!Array.isArray(data.projects) || !Array.isArray(data.activities)) {
        throw new Error('O catálogo recebido do Kimai está incompleto.');
      }
      return data;
    }).catch((error) => {
      // A failed request must not poison future attempts.
      pendingCatalog = null;
      throw error;
    });
    return pendingCatalog;
  }

  function options(select, rows, placeholder, previouslyChosen) {
    select.replaceChildren();
    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = placeholder;
    select.appendChild(empty);
    const match = String(previouslyChosen || '');
    for (const row of rows) {
      if (!row || row.visible === false || !Number.isSafeInteger(Number(row.id)) ||
          Number(row.id) < 1) continue;
      const option = document.createElement('option');
      option.value = String(row.id);
      option.textContent = String(row.name || row.id);
      select.appendChild(option);
    }
    if (match && [...select.options].some((option) => option.value === match)) {
      select.value = match;
    }
  }

  async function load(form) {
    if (form.dataset.catalogLoaded === 'true' ||
        form.dataset.catalogLoading === 'true') return;
    const project = form.elements.namedItem('project_id');
    const activity = form.elements.namedItem('activity_id');
    const submit = form.querySelector('[data-catalog-submit]');
    const status = form.querySelector('[data-catalog-status]');
    const retry = form.querySelector('[data-catalog-retry]');
    if (!project || !activity || !submit || !status || !retry) return;

    form.dataset.catalogLoading = 'true';
    retry.hidden = true;
    submit.disabled = true;
    project.disabled = true;
    activity.disabled = true;
    status.textContent = 'Consultando projetos e atividades no Kimai...';
    try {
      const data = await catalog();
      const projects = data.projects.filter((item) =>
        item && item.visible !== false && Number.isSafeInteger(Number(item.id)) &&
        Number(item.id) > 0);
      const activities = data.activities.filter((item) =>
        item && item.visible !== false && Number.isSafeInteger(Number(item.id)) &&
        Number(item.id) > 0);
      options(project, projects, 'Escolha um projeto', project.dataset.default);

      const refreshActivities = (initial = false) => {
        const selectedProject = Number(project.value);
        const compatible = activities.filter((item) =>
          item.project_id == null || Number(item.project_id) === selectedProject);
        options(activity, selectedProject ? compatible : [],
          selectedProject ? 'Escolha uma atividade' : 'Escolha o projeto primeiro',
          initial ? activity.dataset.default : '');
        activity.disabled = !selectedProject || compatible.length === 0;
        submit.disabled = !project.value || !activity.value;
      };
      project.disabled = projects.length === 0;
      project.addEventListener('change', () => refreshActivities());
      activity.addEventListener('change', () => {
        submit.disabled = !project.value || !activity.value;
      });
      refreshActivities(true);
      form.dataset.catalogLoaded = 'true';
      status.textContent = projects.length === 0 || activities.length === 0
        ? 'Nenhum projeto ou atividade disponível. Verifique a configuração do Kimai.'
        : 'Projetos e atividades disponíveis. Confirme o destino antes de aprovar.';
    } catch (_error) {
      status.textContent = 'Não foi possível carregar projetos e atividades. A revisão continua disponível; tente novamente ao selecionar este período.';
      retry.hidden = false;
    } finally {
      delete form.dataset.catalogLoading;
    }
  }

  for (const form of forms) {
    const detail = form.closest('details');
    const retry = form.querySelector('[data-catalog-retry]');
    if (detail) {
      detail.addEventListener('toggle', () => {
        if (detail.open) void load(form);
      });
      if (detail.open) void load(form);
    }
    if (retry) retry.addEventListener('click', () => void load(form));
  }
})();

(() => {
  // Remote entries are loaded on demand, never from the untrusted HTML cache.
  // The human sees the actual booked bounds before dismissing a partial overlap.
  const endpoint = @json(route('kimai.review-conflict-details'));
  for (const panel of document.querySelectorAll('[data-conflict-details]')) {
    const detail = panel.closest('details');
    const status = panel.querySelector('[data-conflict-status]');
    const list = panel.querySelector('[data-conflict-rows]');
    const retry = panel.querySelector('[data-conflict-retry]');
    if (!detail || !status || !list || !retry) continue;
    let inFlight = false;
    let loaded = false;
    const load = async () => {
      if (inFlight || loaded) return;
      inFlight = true;
      status.textContent = 'Conferindo os horários registrados no Kimai...';
      retry.hidden = true;
      list.replaceChildren();
      try {
        const url = new URL(endpoint);
        url.searchParams.set('candidate_id', panel.dataset.conflictId || '');
        url.searchParams.set('source_digest', panel.dataset.conflictDigest || '');
        const response = await fetch(url.toString(), {
          credentials: 'same-origin', cache: 'no-store',
          headers: { Accept: 'application/json' }
        });
        if (!response.ok) throw new Error('Kimai indisponível');
        const data = await response.json();
        if (!Array.isArray(data.timesheets)) throw new Error('Resposta inválida');
        for (const item of data.timesheets) {
          const row = document.createElement('li');
          row.textContent = 'Kimai #' + String(item.id) + ': ' +
            String(item.start) + '–' + String(item.end);
          list.appendChild(row);
        }
        status.textContent = data.timesheets.length
          ? 'Registros existentes que cruzam esta proposta:'
          : 'Nenhum lançamento sobreposto encontrado. Reanalise antes de decidir.';
        loaded = true;
      } catch (_error) {
        status.textContent = 'Não foi possível consultar os horários atuais. Não descarte por suposição.';
        retry.hidden = false;
      } finally {
        inFlight = false;
      }
    };
    detail.addEventListener('toggle', () => { if (detail.open) void load(); });
    retry.addEventListener('click', () => void load());
    if (detail.open) void load();
  }
})();
