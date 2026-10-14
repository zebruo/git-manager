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
  const container = document.getElementById('foldersContainer');
  const result = await apiCall('listFolders');

  if (!result.success) {
    container.innerHTML = `<div class="folders-empty">${escapeHtml(result.error ?? 'Erreur de chargement')}</div>`;
    return;
  }

  const roots   = result.data.roots;
  const multi   = roots.length > 1;
  const labels  = rootLabels(roots.map(r => r.path));
  const usable  = roots.filter(r => !r.error);
  const gitCount = r => r.folders.filter(f => f.isGitRepo).length;

  // Bandeau : un seul chemin, ou la liste des emplacements possibles
  document.getElementById('rootsInfo').innerHTML = multi
    ? `<p>Les dépôts seront placés dans l'emplacement choisi :</p>
       <ul class="roots-list">${roots.map(r => `<li><code class="parent-path">${escapeHtml(r.path)}</code></li>`).join('')}</ul>`
    : `<p>Les dépôts seront placés dans : <code class="parent-path">${escapeHtml(roots[0].path)}</code></p>`;

  // Listes "Emplacement" : affichées seulement s'il y a un choix à faire
  document.querySelectorAll('.root-select').forEach(select => {
    const previous = select.value;
    select.innerHTML = usable.map(r =>
      `<option value="${r.index}" title="${escapeHtml(r.path)}">${escapeHtml(labels[roots.indexOf(r)])}</option>`
    ).join('');
    if (usable.some(r => String(r.index) === previous)) select.value = previous;
    select.closest('.root-choice').hidden = usable.length < 2;
  });

  document.getElementById('reposCount').textContent = '(' + roots.reduce((n, r) => n + gitCount(r), 0) + ')';

  container.innerHTML = roots.map(r => `
    <p class="folders-desc">
      Dépôts dans : <span class="parent-path">${escapeHtml(r.path)}</span>
      ${multi && !r.error ? `<span class="root-count">(${gitCount(r)})</span>` : ''}
    </p>
    <div class="folders-list">
      ${r.error
        ? `<div class="folders-empty">${escapeHtml(r.error)}</div>`
        : r.folders.length === 0
          ? '<div class="folders-empty">Aucun dossier trouvé</div>'
          : r.folders.map(folder => `
            <div class="folder-item">
              <i class="fas ${folder.isGitRepo ? 'fa-git-alt' : 'fa-folder'}"></i>
              <span>${escapeHtml(folder.name)}</span>
              ${folder.isGitRepo ? '<small>(dépôt Git)</small>' : ''}
            </div>
          `).join('')}
    </div>
  `).join('');
}

function selectedRoot(selectId) {
  return parseInt(document.getElementById(selectId).value, 10) || 0;
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

  const result = await apiCall('clone', { url, targetDir, root: selectedRoot('cloneRoot') });

  btn.disabled = false;
  loading.classList.remove('visible');

  if (result.success) {
    showAlert('success', 'Dépôt cloné avec succès !');
    document.getElementById('resultPath').textContent = result.data.path;
    document.getElementById('openCloneBtn').href = 'git-manager.html?repo=' + encodeURIComponent(result.data.id);
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

  const result = await apiCall('initRepo', { name, root: selectedRoot('initRoot') });

  btn.disabled = false;
  loading.classList.remove('visible');

  if (result.success) {
    showAlert('success', `Dépôt "${result.data.name}" créé avec succès !`);
    document.getElementById('resultNewRepoPath').textContent = result.data.path;
    document.getElementById('openNewRepoBtn').href = 'git-manager.html?repo=' + encodeURIComponent(result.data.id);
    resultBox.classList.add('visible');
    document.getElementById('newRepoName').value = '';
    loadFolders();
  } else {
    showAlert('error', 'Erreur : ' + result.error);
  }
}
