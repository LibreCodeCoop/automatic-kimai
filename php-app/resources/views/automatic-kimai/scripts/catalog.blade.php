(() => {
  const button = document.getElementById('catalog-load');
  if (!button) return;
  const $ = (id) => document.getElementById(id);
  let data = null;
  const select = (target, rows, prompt) => {
    const element = $(target);
    element.replaceChildren();
    const first = document.createElement('option');
    first.value = '';
    first.textContent = prompt;
    element.appendChild(first);
    for (const row of rows) {
      const option = document.createElement('option');
      option.value = String(row.id);
      option.textContent = row.name || String(row.id);
      element.appendChild(option);
    }
  };
  const updateProjects = () => {
    const customer = Number($('catalog-customers').value);
    const available = data.projects.filter(item => item.customer_id === customer);
    select('catalog-projects', available, 'Escolha um projeto');
    select('catalog-activities', [], 'Escolha o projeto primeiro');
    $('customer-id').value = customer || '';
  };
  const updateActivities = () => {
    const project = Number($('catalog-projects').value);
    const available = data.activities.filter(item => item.project_id === null || item.project_id === project);
    select('catalog-activities', available, 'Escolha uma atividade');
    $('project-id').value = project || '';
  };
  $('catalog-customers').addEventListener('change', updateProjects);
  $('catalog-projects').addEventListener('change', updateActivities);
  $('catalog-activities').addEventListener('change', () => {
    $('activity-id').value = $('catalog-activities').value || '';
  });
  button.addEventListener('click', async () => {
    const message = $('catalog-message');
    message.textContent = 'Procurando seus projetos no Kimai...';
    button.disabled = true;
    try {
      const response = await fetch(@json(route('kimai.catalog-preview')), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': @json(csrf_token()),
        },
        body: new URLSearchParams({
          kimai_url: $('kimai-url').value,
          kimai_token: $('kimai-token').value,
        }),
      });
      const answer = await response.json();
      if (!response.ok || !Array.isArray(answer.customers) ||
          !Array.isArray(answer.projects) || !Array.isArray(answer.activities)) {
        throw new Error('Não foi possível consultar os projetos. Verifique o endereço e o token.');
      }
      data = answer;
      select('catalog-customers', answer.customers, 'Escolha um cliente');
      select('catalog-projects', [], 'Escolha um cliente primeiro');
      select('catalog-activities', [], 'Escolha um projeto primeiro');
      if (answer.timezone) {
        const timeField = document.querySelector('input[name="timezone"]');
        timeField.value = answer.timezone;
      }
      message.textContent = 'Projetos encontrados. Escolha cliente, projeto e tipo de atividade abaixo.';
    } catch (error) {
      message.textContent = error.message;
    } finally {
      button.disabled = false;
    }
  });
})();
