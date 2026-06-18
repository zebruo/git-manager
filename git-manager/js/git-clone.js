const API_URL = 'git-api.php';

document.addEventListener('DOMContentLoaded', function() {
  if (localStorage.getItem('gitManagerDarkMode') === 'true') {
    document.body.classList.add('dark-mode');
    updateDarkModeIcon();
  }
  loadFolders();
});

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

async function apiCall(action, params = {}) {
  try {
    const response = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...params })
    });
    return await response.json();
  } catch (error) {
    return { success: false, error: error.message };
  }
}

async function loadFolders() {
  const list = document.getElementById('foldersList');
  const result = await apiCall('listFolders');

  if (result.success) {
    document.querySelectorAll('.parent-path').forEach(el => el.textContent = result.data.parentDir);
    const folders = result.data.folders;
    if (folders.length === 0) {
      list.innerHTML = '<div style="padding: 15px; color: var(--text-secondary);">Aucun dossier trouvé</div>';
    } else {
      list.innerHTML = folders.map(folder => `
        <div class="folder-item">
          <i class="fas ${folder.isGitRepo ? 'fa-git-alt' : 'fa-folder'}"></i>
          <span>${folder.name}</span>
          ${folder.isGitRepo ? '<small>(dépôt Git)</small>' : ''}
        </div>
      `).join('');
    }
  } else {
    list.innerHTML = '<div style="padding: 15px; color: var(--text-secondary);">Erreur de chargement</div>';
  }
}

async function cloneRepo() {
  const url = document.getElementById('repoUrl').value.trim();
  const targetDir = document.getElementById('folderName').value.trim();
  const copyGitManager = document.getElementById('copyGitManager').checked;

  if (!url) {
    showAlert('error', 'Veuillez entrer l\'URL du dépôt');
    return;
  }

  const btn = document.getElementById('cloneBtn');
  const loading = document.getElementById('loadingIndicator');
  const resultBox = document.getElementById('resultBox');

  btn.disabled = true;
  loading.classList.add('visible');
  resultBox.classList.remove('visible');

  const result = await apiCall('clone', { url, targetDir, copyGitManager, subfolder: 'git-manager' });

  btn.disabled = false;
  loading.classList.remove('visible');

  if (result.success) {
    showAlert('success', 'Dépôt cloné avec succès !');
    const expectedUrl = window.location.protocol + '//' + result.data.folderName + '/git-manager/git-manager.html';
    const link = document.getElementById('openRepoBtn');
    link.href = expectedUrl;
    link.textContent = expectedUrl;
    document.getElementById('resultPath').textContent = result.data.path;

    if (result.data.copiedFiles && result.data.copiedFiles.length > 0) {
      document.getElementById('resultFiles').innerHTML =
        '<i class="fas fa-copy"></i> Fichiers copiés dans git-manager/ : ' + result.data.copiedFiles.join(', ');
    } else {
      document.getElementById('resultFiles').textContent = '';
    }

    if (result.data.copyErrors && result.data.copyErrors.length > 0) {
      showAlert('error', 'Erreurs de copie : ' + result.data.copyErrors.join(', '));
    }

    resultBox.classList.add('visible');
    loadFolders();
  } else {
    showAlert('error', 'Erreur : ' + result.error);
  }
}

