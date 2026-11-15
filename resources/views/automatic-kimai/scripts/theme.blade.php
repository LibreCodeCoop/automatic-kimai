(() => {
  const button = document.getElementById('theme-switch');
  if (!button) return;
  const system = window.matchMedia('(prefers-color-scheme: dark)');
  const key = 'automatic-kimai:theme';
  const preference = () => {
    try { return localStorage.getItem(key); }
    catch (_error) { return null; }
  };
  const paint = () => {
    const stored = preference();
    const dark = stored === 'dark' || (stored !== 'light' && system.matches);
    const icon = dark ? 'sun' : 'moon';
    button.dataset.icon = icon;
    button.setAttribute('aria-label', dark ? 'Ativar tema claro' : 'Ativar tema escuro');
    button.setAttribute('title', dark ? 'Ativar tema claro' : 'Ativar tema escuro');
    button.setAttribute('aria-pressed', stored === 'light' || stored === 'dark' ? 'true' : 'false');
  };
  button.addEventListener('click', () => {
    const dark = document.documentElement.dataset.theme === 'dark' ||
      (!document.documentElement.dataset.theme && system.matches);
    const next = dark ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem(key, next); } catch (_error) {}
    paint();
  });
  if (system.addEventListener) system.addEventListener('change',paint);
  paint();
})();
