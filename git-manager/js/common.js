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

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// Libellés courts des racines de dépôts (nom du dernier dossier),
// chemins complets si deux racines portent le même nom
function rootLabels(paths) {
  const names = paths.map(p => p.split(/[\\/]/).filter(Boolean).pop() || p);
  return new Set(names).size === names.length ? names : paths.slice();
}

function showAlert(type, message) {
  const container = document.getElementById('alertContainer');
  const toast = document.createElement('div');
  toast.className = `alert alert-${type}`;

  let icon = 'info-circle';
  if (type === 'success') icon = 'check-circle';
  else if (type === 'error') icon = 'exclamation-circle';
  else if (type === 'warning') icon = 'exclamation-triangle';

  toast.innerHTML = `<i class="fas fa-${icon}"></i> ${message}`;
  container.appendChild(toast);

  const duration = type === 'error' ? 8000 : type === 'warning' ? 6000 : 4000;
  const dismiss = () => {
    toast.classList.add('toast-out');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
  };
  toast.addEventListener('click', dismiss);
  setTimeout(dismiss, duration);
}
