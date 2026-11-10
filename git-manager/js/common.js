function initDarkMode() {
  if (localStorage.getItem('gitManagerDarkMode') === 'true') {
    document.body.classList.add('dark-mode');
    updateDarkModeIcon();
  }
}

function toggleDarkMode() {
  document.body.classList.toggle('dark-mode');
  const isDark = document.body.classList.contains('dark-mode');
  localStorage.setItem('gitManagerDarkMode', isDark);
  updateDarkModeIcon();
}

function updateDarkModeIcon() {
  const btn = document.getElementById('darkModeBtn');
  const isDark = document.body.classList.contains('dark-mode');
  btn.innerHTML = isDark ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>';
  btn.title = isDark ? 'Mode clair' : 'Mode sombre';
}

function navigateBack() {
  const repo = new URLSearchParams(window.location.search).get('repo');
  window.location.href = repo ? 'git-manager.html?repo=' + encodeURIComponent(repo) : 'git-manager.html';
}

// Tout texte venant du dépôt (messages de commit, noms de branches, de fichiers, sorties git…)
// doit passer par escapeHtml avant d'être inséré en HTML : un commit ou une branche piégés
// pourraient sinon exécuter du code dans la page et piloter l'API.
function escapeHtml(str) {
  return String(str)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

// Valeur passée en argument dans un attribut onclick="fn(${jsArg(x)})" :
// littéral JavaScript (JSON) puis échappement HTML de l'attribut
function jsArg(value) {
  return escapeHtml(JSON.stringify(String(value)));
}

// Libellés courts des racines de dépôts (nom du dernier dossier),
// chemins complets si deux racines portent le même nom
function rootLabels(paths) {
  const names = paths.map(p => p.split(/[\\/]/).findLast(Boolean) || p);
  return new Set(names).size === names.length ? names : paths.slice();
}

const ALERT_ICONS     = { success: 'check-circle', error: 'exclamation-circle', warning: 'exclamation-triangle' };
const ALERT_DURATIONS = { error: 8000, warning: 6000 };

// message : texte brut (échappé ici — il contient souvent une sortie git)
function showAlert(type, message) {
  const container = document.getElementById('alertContainer');
  const toast = document.createElement('div');
  toast.className = `alert alert-${type}`;

  const icon = ALERT_ICONS[type] || 'info-circle';
  toast.innerHTML = `<i class="fas fa-${icon}"></i> ${escapeHtml(message)}`;
  container.appendChild(toast);

  const duration = ALERT_DURATIONS[type] || 4000;
  const dismiss = () => {
    toast.classList.add('toast-out');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
  };
  toast.addEventListener('click', dismiss);
  setTimeout(dismiss, duration);
}
