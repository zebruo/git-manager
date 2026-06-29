const API_URL = 'git-api.php';

document.addEventListener('DOMContentLoaded', function() {
  initDarkMode();
  loadFolders();
});

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
    list.innerHTML = `<div style="padding: 15px; color: var(--text-secondary);">${result.error ?? 'Erreur de chargement'}</div>`;
  }
}

async function cloneRepo() {
  const url = document.getElementById('repoUrl').value.trim();
  const targetDir = document.getElementById('folderName').value.trim();

  if (!url) {
    showAlert('error', 'Veuillez entrer l\'URL du dépôt');
    return;
  }

  const btn       = document.getElementById('cloneBtn');
  const loading   = document.getElementById('loadingIndicator');
  const resultBox = document.getElementById('resultBox');

  btn.disabled = true;
  loading.classList.add('visible');
  resultBox.classList.remove('visible');

  const result = await apiCall('clone', { url, targetDir });

  btn.disabled = false;
  loading.classList.remove('visible');

  if (result.success) {
    showAlert('success', 'Dépôt cloné avec succès !');
    document.getElementById('resultPath').textContent = result.data.path;
    document.getElementById('openCloneBtn').href = 'git-manager.html?repo=' + encodeURIComponent(result.data.folderName);
    resultBox.classList.add('visible');
    loadFolders();
  } else {
    showAlert('error', 'Erreur : ' + result.error);
  }
}

async function initRepo() {
  const name = document.getElementById('newRepoName').value.trim();
  if (!name) {
    showAlert('error', 'Veuillez entrer un nom de dépôt');
    return;
  }

  const btn       = document.getElementById('initRepoBtn');
  const loading   = document.getElementById('loadingNewRepo');
  const resultBox = document.getElementById('resultNewRepo');

  btn.disabled = true;
  loading.classList.add('visible');
  resultBox.classList.remove('visible');

  const result = await apiCall('initRepo', { name });

  btn.disabled = false;
  loading.classList.remove('visible');

  if (result.success) {
    showAlert('success', `Dépôt "${result.data.name}" créé avec succès !`);
    document.getElementById('resultNewRepoPath').textContent = result.data.path;
    document.getElementById('openNewRepoBtn').href = 'git-manager.html?repo=' + encodeURIComponent(result.data.name);
    resultBox.classList.add('visible');
    document.getElementById('newRepoName').value = '';
    loadFolders();
  } else {
    showAlert('error', 'Erreur : ' + result.error);
  }
}
