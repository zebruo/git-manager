const API_URL = 'git-api.php';
let isStandalone = false;

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
    isStandalone = result.data.isStandalone || false;
    document.querySelectorAll('.parent-path').forEach(el => el.textContent = result.data.parentDir);
    adaptUIToMode(result.data.parentDir);

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

function adaptUIToMode(parentDir) {
  document.getElementById('infoEmbedded').style.display          = isStandalone ? 'none' : '';
  document.getElementById('infoStandalone').style.display        = isStandalone ? ''     : 'none';
  document.getElementById('checkboxCopyGitManager').style.display = isStandalone ? 'none' : '';
  document.getElementById('cardNewRepo').style.display           = isStandalone ? ''     : 'none';

  const badge = document.getElementById('modeBadge');
  if (isStandalone) {
    badge.textContent = 'Multi-dépôts';
    badge.style.display = '';
  } else {
    badge.style.display = 'none';
  }

  if (isStandalone) {
    document.getElementById('foldersTitle').innerHTML = '<i class="fas fa-folder"></i> Dépôts existants';
    document.getElementById('foldersDesc').innerHTML  =
      'Dépôts dans : <span class="parent-path">' + parentDir + '</span>';
  }
}

async function cloneRepo() {
  const url = document.getElementById('repoUrl').value.trim();
  const targetDir = document.getElementById('folderName').value.trim();
  const copyGitManager = !isStandalone && document.getElementById('copyGitManager').checked;

  if (!url) {
    showAlert('error', 'Veuillez entrer l\'URL du dépôt');
    return;
  }

  const btn     = document.getElementById('cloneBtn');
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
    document.getElementById('resultPath').textContent = result.data.path;

    const resultUrlEl = document.getElementById('resultUrl');
    if (isStandalone) {
      resultUrlEl.style.display = 'none';
      document.getElementById('resultFiles').innerHTML =
        '<i class="fas fa-info-circle"></i> Le dépôt est maintenant disponible dans le sélecteur de dépôts.';
    } else {
      resultUrlEl.style.display = '';
      const expectedUrl = window.location.protocol + '//' + result.data.folderName + '/git-manager/git-manager.html';
      const link = document.getElementById('openRepoBtn');
      link.href = expectedUrl;
      link.textContent = expectedUrl;

      if (result.data.copiedFiles && result.data.copiedFiles.length > 0) {
        document.getElementById('resultFiles').innerHTML =
          '<i class="fas fa-copy"></i> Fichiers copiés dans git-manager/ : ' + result.data.copiedFiles.join(', ');
      } else {
        document.getElementById('resultFiles').textContent = '';
      }
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
    resultBox.classList.add('visible');
    document.getElementById('newRepoName').value = '';
    loadFolders();
  } else {
    showAlert('error', 'Erreur : ' + result.error);
  }
}
