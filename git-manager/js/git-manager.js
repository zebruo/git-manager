const API_URL = 'git-api.php';
let modalResolve = null;
let currentAhead = 0;
let currentHasRemote = false;
let activeRepo = new URLSearchParams(window.location.search).get('repo') || '';

// Initialisation
document.addEventListener('DOMContentLoaded', async function () {
  initDarkMode();

  // Propager ?repo= sur les liens vers les pages secondaires
  if (activeRepo) {
    ['git-clone.html', 'git-auth.html', 'git-reset-remote.html', 'index.html'].forEach(page => {
      document.querySelectorAll(`a[href="${page}"]`).forEach(a => {
        a.href = page + '?repo=' + encodeURIComponent(activeRepo);
      });
    });
  }

  // Charger le sélecteur de dépôts (si reposRoot configuré)
  loadRepoSwitcher();

  // Vérifier si c'est un dépôt Git (résultat réutilisé pour la LED serveur)
  const repoRaw   = await apiCall('checkRepo');
  const repoCheck = repoRaw.success ? repoRaw.data : { isGitRepo: false, path: '' };
  updateServerLed(repoRaw.success);
  setInterval(checkServerStatus, 30000);

  if (repoCheck.isGitRepo) {
    document.getElementById('initSection').style.display = 'none';
    document.getElementById('mainContent').style.display = 'block';
    loadRepoInfo();
    refreshAll();
  } else {
    document.getElementById('initSection').style.display = 'block';
    document.getElementById('mainContent').style.display = 'none';
    document.getElementById('initPath').textContent = repoCheck.path;
    document.getElementById('repoInfo').innerHTML = '<i class="fas fa-folder"></i> ' + repoCheck.path;
  }
});

function updateServerLed(online) {
  const led = document.getElementById('serverLed');
  if (!led) return;
  led.className = online ? 'server-led online' : 'server-led offline';
  led.title    = online ? 'Serveur en ligne'  : 'Serveur hors ligne';
}

async function checkServerStatus() {
  const result = await apiCall('checkRepo');
  updateServerLed(result.success);
}

// Initialiser un nouveau dépôt Git
async function initRepo() {
  const confirmed = await showConfirm('Initialiser un nouveau dépôt Git dans ce dossier ?');
  if (!confirmed) return;

  const result = await apiCall('init');

  if (result.success) {
    showAlert('success', result.output);
    // Recharger la page pour afficher l'interface complète
    setTimeout(() => location.reload(), 1000);
  } else {
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Charger les informations du dépôt
async function loadRepoInfo() {
  const repoInfo = document.getElementById('repoInfo');
  const remoteBtn = document.getElementById('remoteBtn');
  const resetRemoteBtn = document.getElementById('resetRemoteBtn');
  const result = await apiCall('repoInfo');

  if (result.success && result.data) {
    // Bandeau si git-config.php manquant
    document.getElementById('configBanner').style.display = result.data.configExists ? 'none' : 'flex';

    // Afficher le bouton remote dans le header
    remoteBtn.style.display = 'inline-flex';

    currentHasRemote = result.data.hasRemote || false;

    if (result.data.hasRemote && result.data.name && result.data.url) {
      const githubUrl = result.data.url.replace(/\.git$/, '').replace('git@github.com:', 'https://github.com/');
      const isSSH = result.data.url.startsWith('git@');
      const authBadge = isSSH
        ? '<span style="background: color-mix(in srgb, var(--success-color) 15%, transparent); color: var(--success-color); padding: 2px 8px; border-radius: 10px; font-size: 0.75em; margin-left: 8px;" title="Connexion SSH"><i class="fas fa-key"></i> SSH</span>'
        : '<span style="background: color-mix(in srgb, var(--info-color) 15%, transparent); color: var(--info-color); padding: 2px 8px; border-radius: 10px; font-size: 0.75em; margin-left: 8px;" title="Connexion HTTPS / Token"><i class="fas fa-lock"></i> HTTPS</span>';
      repoInfo.innerHTML = `
        <span style="color: var(--text-secondary); font-size: 0.85em; margin-right: 4px;">origin</span>
        <i class="fab fa-github repo-icon"></i>
        <a href="${githubUrl}" target="_blank" title="Ouvrir sur GitHub">${result.data.name}</a>
        ${authBadge}
      `;
      // Icône d'édition quand remote existe
      remoteBtn.innerHTML = '<i class="fas fa-edit"></i>';
      remoteBtn.title = 'Modifier le remote GitHub';
      // Afficher le bouton reset remote quand un remote est configuré
      resetRemoteBtn.style.display = 'inline-flex';
    } else {
      // Pas de remote configuré
      repoInfo.innerHTML = `
        <i class="fas fa-folder repo-icon"></i>
        <span id="repoPath">${result.data.path || 'Dépôt local'}</span>
      `;
      // Icône de lien quand pas de remote
      remoteBtn.innerHTML = '<i class="fas fa-link"></i>';
      remoteBtn.title = 'Lier à GitHub';
      // Masquer le bouton reset remote quand pas de remote
      resetRemoteBtn.style.display = 'none';
    }
  } else {
    repoInfo.innerHTML = `<i class="fas fa-folder repo-icon"></i> Dépôt local`;
    remoteBtn.style.display = 'none';
    resetRemoteBtn.style.display = 'none';
  }
}

// Afficher le formulaire pour configurer le remote
function showRemoteForm() {
  const modal = document.getElementById('confirmModal');
  const modalContent = modal.querySelector('.modal-content');

  modalContent.innerHTML = /*html*/`
    <h3><i class="fab fa-github"></i> Configurer le remote GitHub</h3>
    <p style="color: var(--text-secondary); margin-bottom: 15px;">
      Entrez l'URL de votre dépôt GitHub :
    </p>
    <input type="text" id="remoteUrlInput" class="file-select"
      placeholder="https://github.com/username/repo.git"
      style="width: 100%; margin-bottom: 15px;">
    <p style="color: var(--text-secondary); font-size: 0.85em; margin-bottom: 20px;">
      <i class="fas fa-info-circle"></i> Vous pouvez aussi utiliser le format SSH : git@github.com:username/repo.git
    </p>
    <div class="modal-buttons">
      <button class="btn btn-secondary" onclick="closeRemoteForm()">Annuler</button>
      <button class="btn btn-danger" onclick="removeRemote()" id="removeRemoteBtn" style="display: none;">
        <i class="fas fa-unlink"></i> Supprimer
      </button>
      <button class="btn btn-primary" onclick="saveRemote()">
        <i class="fas fa-save"></i> Enregistrer
      </button>
    </div>
  `;

  // Pré-remplir si un remote existe déjà
  apiCall('repoInfo').then(result => {
    if (result.success && result.data.hasRemote) {
      document.getElementById('remoteUrlInput').value = result.data.url || '';
      document.getElementById('removeRemoteBtn').style.display = 'inline-flex';
    }
  });

  modal.classList.add('active');
}

// Fermer le formulaire remote et restaurer la modal
function closeRemoteForm() {
  const modal = document.getElementById('confirmModal');
  const modalContent = modal.querySelector('.modal-content');

  // Restaurer le contenu original de la modal
  modalContent.innerHTML = /*html*/`
    <h3><i class="fas fa-question-circle"></i> Confirmation</h3>
    <p id="confirmModalMessage">Êtes-vous sûr ?</p>
    <div class="modal-buttons">
      <button class="btn btn-secondary" onclick="closeModal(false)" id="cancelModalBtn">Annuler</button>
      <button class="btn btn-danger" onclick="closeModal(true)" id="confirmModalBtn">Confirmer</button>
    </div>
  `;

  modal.classList.remove('active');
}

// Enregistrer le remote
async function saveRemote() {
  const url = document.getElementById('remoteUrlInput').value.trim();

  if (!url) {
    showAlert('error', 'Veuillez entrer une URL');
    return;
  }

  const result = await apiCall('addRemote', { url });

  if (result.success) {
    if (result.warning) {
      // Avertissement: fetch a échoué (problème SSH probable)
      showAlert('warning', result.warning);
      console.warn('Fetch error:', result.fetchError);
    } else {
      showAlert('success', result.output);
    }
    closeRemoteForm();
    loadRepoInfo();
    loadBranches(); // Recharger les branches distantes
  } else {
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Supprimer le remote
async function removeRemote() {
  // Utiliser confirm() natif car la modale est déjà utilisée par le formulaire
  const confirmed = confirm('Supprimer la liaison avec GitHub ?\n\nCette action supprime uniquement le lien vers le dépôt distant, pas les fichiers.');
  if (!confirmed) return;

  const result = await apiCall('removeRemote');

  if (result.success) {
    showAlert('success', result.output);
    closeRemoteForm();
    loadRepoInfo();
  } else {
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Toggle mode sombre
// Afficher la modale d'aide
let helpLoaded = false;
async function showHelpModal() {
  document.getElementById('helpModal').classList.add('active');
  if (!helpLoaded) {
    try {
      const response = await fetch('git-help.html');
      if (response.ok) {
        document.getElementById('helpContent').innerHTML = await response.text();
        helpLoaded = true;
      } else {
        document.getElementById('helpContent').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Impossible de charger l\'aide (git-help.html)</p></div>';
      }
    } catch (e) {
      document.getElementById('helpContent').innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Erreur : ' + e.message + '</p></div>';
    }
  }
}

// Fermer la modale d'aide
function closeHelpModal() {
  document.getElementById('helpModal').classList.remove('active');
}

function showHelpTab(tabId) {
  document.querySelectorAll('.help-tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.help-tab-btn').forEach(b => b.classList.remove('active'));
  const panel = document.getElementById('helpTab-' + tabId);
  if (panel) panel.classList.add('active');
  const btn = document.querySelector(`.help-tab-btn[data-tab="${tabId}"]`);
  if (btn) btn.classList.add('active');
}

// Actualiser toutes les sections
async function refreshAll() {
  const btn = document.getElementById('refreshAllBtn');
  const icon = btn ? btn.querySelector('i') : null;
  if (icon) icon.classList.add('fa-spin');
  if (btn) btn.disabled = true;

  try {
    await Promise.allSettled([
      loadStatus(),
      loadHistory(),
      loadGitignore(),
      loadBranches(),
      loadStashList(),
      loadTagList()
    ]);
  } catch (e) {
    console.error('refreshAll error:', e);
  }

  if (icon) icon.classList.remove('fa-spin');
  if (btn) btn.disabled = false;
}

// Modal de confirmation personnalisée
function showConfirm(message, isDanger = false, confirmLabel = 'Confirmer', cancelLabel = 'Annuler') {
  return new Promise((resolve) => {
    modalResolve = resolve;
    document.getElementById('confirmModalMessage').textContent = message;
    const confirmBtn = document.getElementById('confirmModalBtn');
    const cancelBtn = document.getElementById('cancelModalBtn');
    confirmBtn.className = isDanger ? 'btn btn-danger' : 'btn btn-primary';
    confirmBtn.textContent = confirmLabel;
    cancelBtn.textContent = cancelLabel;
    document.getElementById('confirmModal').classList.add('active');
  });
}

function closeModal(result) {
  document.getElementById('confirmModal').classList.remove('active');
  if (modalResolve) {
    modalResolve(result);
    modalResolve = null;
  }
}

// Fermer les modales en cliquant à l'extérieur
document.addEventListener('click', function (e) {
  if (e.target.id === 'confirmModal') {
    closeModal(false);
  }
  if (e.target.id === 'helpModal') {
    closeHelpModal();
  }
});

// Afficher une alerte
// Ajouter du texte au terminal
function terminalLog(text, type = '') {
  const terminal = document.getElementById('terminalOutput');
  const line = document.createElement('div');
  line.className = type;
  line.textContent = text;
  terminal.appendChild(line);
  terminal.scrollTop = terminal.scrollHeight;
}

// Effacer le terminal
function terminalClear() {
  document.getElementById('terminalOutput').innerHTML = '';
}

// Appel API
async function apiCall(action, params = {}) {
  try {
    const response = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...(activeRepo ? { repo: activeRepo } : {}), ...params })
    });
    return await response.json();
  } catch (error) {
    return { success: false, error: error.message };
  }
}

// Charger le statut
async function loadStatus() {
  const statusContent = document.getElementById('statusContent');
  statusContent.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i> Chargement...</div>';

  try {
    const result = await apiCall('status');

    if (result.success) {
      const data = result.data;
      const modified = data.modified || [];
      const untracked = data.untracked || [];
      const staged = data.staged || [];
      const stagedDeleted = data.stagedDeleted || [];
      const conflicted = data.conflicted || [];
      const allFiles = data.allFiles || [];
      const totalStaged = staged.length + stagedDeleted.length;
      const totalTracked = allFiles.length;
      currentAhead = data.ahead || 0;
      const branchDisplay = data.isRebasing
        ? `<span style="color: var(--info-color); font-size: 0.7em;"><i class="fas fa-sync"></i> Rebase en cours</span>`
        : data.isDetached
          ? `<span style="color: var(--warning-color); font-size: 0.7em;"><i class="fas fa-exclamation-triangle"></i> HEAD détaché</span> <code style="font-size: 0.7em;">${data.detachedAt || '?'}</code>`
          : (data.branch || '—');
      statusContent.innerHTML = `
        <div class="status-info">
          <div class="status-row">
            <span class="status-label">Branche</span>
            <span class="status-value branch">${branchDisplay}</span>
          </div>
          <div class="status-row">
            <span class="status-label"><i class="fas fa-file-alt" style="color: var(--accent-color);"></i> Fichiers suivis</span>
            <span class="status-value">${totalTracked}</span>
          </div>
          ${conflicted.length > 0 ? `
          <div class="status-row">
            <span class="status-label"><span class="status-badge conflicted">C</span> Conflits</span>
            <span class="status-value" style="color: var(--warning-color); font-weight: bold;">${conflicted.length}</span>
          </div>` : ''}
          <div class="status-row">
            <span class="status-label"><span class="status-badge modified">M</span> Modifiés</span>
            <span class="status-value">${modified.length}</span>
          </div>
          <div class="status-row">
            <span class="status-label"><span class="status-badge added">A</span> Stagés</span>
            <span class="status-value">${totalStaged}</span>
          </div>
          <div class="status-row">
            <span class="status-label"><span class="status-badge deleted">D</span> Supprimés</span>
            <span class="status-value">${stagedDeleted.length}</span>
          </div>
          <div class="status-row">
            <span class="status-label"><span class="status-badge untracked">U</span> Non suivis</span>
            <span class="status-value">${untracked.length}</span>
          </div>
          <div class="status-row">
            <span class="status-label"><span class="ahead">↑</span> En avance</span>
            <span class="status-value">${data.ahead || 0}</span>
          </div>
          <div class="status-row">
            <span class="status-label"><span class="behind">↓</span> En retard</span>
            <span class="status-value">${data.behind || 0}</span>
          </div>
          <div class="status-row">
            <span class="status-label"><i class="fas fa-archive" style="color: var(--info-color);"></i> Stash</span>
            <span class="status-value">${data.stashCount || 0}</span>
          </div>
        </div>
      `;

      // Mettre à jour la liste des fichiers
      updateFileList(modified, untracked, staged, stagedDeleted, conflicted);

      // Mettre à jour le select pour checkout
      updateCheckoutSelect(modified, allFiles);
    } else {
      statusContent.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>${result.error}</p></div>`;
    }
  } catch (e) {
    console.error('loadStatus error:', e);
    statusContent.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>Erreur de chargement du statut</p></div>`;
  }
}

// Charger la liste des stashes
async function loadStashList() {
  const stashListDiv = document.getElementById('stashList');
  if (!stashListDiv) return;

  const result = await apiCall('stashList');

  if (result.success && result.data.stashes.length > 0) {
    const stashes = result.data.stashes;
    stashListDiv.innerHTML = `
      <div style="color: var(--text-secondary); font-size: 0.9em; margin-bottom: 8px;">
        <i class="fas fa-archive"></i> ${stashes.length} stash${stashes.length > 1 ? 'es' : ''} en attente
      </div>
      ${stashes.map(s => `
        <div class="stash-item">
          <div class="stash-item-info">
            <span class="stash-item-id">${s.id}</span>
            <span class="stash-item-message">${s.message}</span>
            <div class="stash-item-date">${s.date}</div>
          </div>
          <div class="stash-item-actions">
            <button class="stash-btn-pop" onclick="doStashApply('${s.id}', true)" title="Restaurer et supprimer">
              <i class="fas fa-redo"></i> Pop
            </button>
            <button class="stash-btn-apply" onclick="doStashApply('${s.id}', false)" title="Restaurer sans supprimer">
              <i class="fas fa-copy"></i> Appliquer
            </button>
            <button class="stash-btn-drop" onclick="doStashDrop('${s.id}')" title="Supprimer ce stash">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </div>
      `).join('')}
    `;
  } else {
    stashListDiv.innerHTML = '<div class="empty-state" style="padding: 10px;"><i class="fas fa-check-circle"></i><p>Aucun stash en attente</p></div>';
  }
}

// Mettre de côté les modifications (git stash)
async function doStashSave() {
  const message = document.getElementById('stashMessage').value.trim();
  const includeUntracked = document.getElementById('stashIncludeUntracked').checked;

  terminalClear();
  let cmd = '$ git stash push';
  if (includeUntracked) cmd += ' --include-untracked';
  if (message) cmd += ` -m "${message}"`;
  terminalLog(cmd, 'info');

  const result = await apiCall('stashSave', { message, includeUntracked });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', 'Modifications mises de côté');
    document.getElementById('stashMessage').value = '';
    loadStatus();
    loadStashList();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Restaurer un stash (pop ou apply)
async function doStashApply(stashId, drop) {
  const confirmed = await showConfirm(
    `Restaurer les modifications de ${stashId} ?${drop ? '\nLe stash sera supprimé après restauration.' : '\nLe stash sera conservé.'}`,
    false,
    drop ? 'Restaurer et supprimer' : 'Restaurer',
    'Annuler'
  );
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git stash ${drop ? 'pop' : 'apply'} ${stashId}`, 'info');

  const result = await apiCall('stashApply', { stashId, drop });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', drop ? 'Stash restauré et supprimé' : 'Stash restauré (conservé)');
    loadStatus();
    loadStashList();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

function showHistoryTab(tab) {
  const isHistory = tab === 'history';
  document.getElementById('historyPanel').style.display = isHistory ? '' : 'none';
  document.getElementById('tagsPanel').style.display   = isHistory ? 'none' : '';
  document.getElementById('histTab').classList.toggle('active', isHistory);
  document.getElementById('tagsTab').classList.toggle('active', !isHistory);
}

// ── Tags ──────────────────────────────────────────────────────────────────────

async function loadTagList() {
  const tagListDiv = document.getElementById('tagList');
  if (!tagListDiv) return;

  const result = await apiCall('listTags');

  if (result.success && result.data.tags.length > 0) {
    const tags = result.data.tags;
    tagListDiv.innerHTML = `
      <div style="color: var(--text-secondary); font-size: 0.9em; margin-bottom: 8px;">
        <i class="fas fa-tags"></i> ${tags.length} tag${tags.length > 1 ? 's' : ''}
      </div>
      ${tags.map(t => `
        <div class="stash-item">
          <div class="stash-item-info">
            <span class="stash-item-id">${escapeHtml(t.name)}</span>
            <span class="stash-item-message">${t.type}</span>
          </div>
          <div class="stash-item-actions">
            <button class="stash-btn-apply" onclick="doPushTag('${escapeHtml(t.name)}')" title="Pousser vers origin">
              <i class="fas fa-upload"></i> Push
            </button>
            <button class="stash-btn-drop" onclick="doDeleteTag('${escapeHtml(t.name)}')" title="Supprimer le tag">
              <i class="fas fa-trash"></i>
            </button>
          </div>
        </div>
      `).join('')}
    `;
  } else {
    tagListDiv.innerHTML = '<div class="empty-state" style="padding: 10px;"><i class="fas fa-tag"></i><p>Aucun tag</p></div>';
  }
}

async function doCreateTag() {
  const name    = document.getElementById('tagName').value.trim();
  const message = document.getElementById('tagMessage').value.trim();

  if (!name) { showAlert('warning', 'Saisissez un nom de tag (ex : v1.0.0)'); return; }

  terminalClear();
  const cmd = message ? `$ git tag -a ${name} -m "${message}"` : `$ git tag ${name}`;
  terminalLog(cmd, 'info');

  const result = await apiCall('createTag', { name, message });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    document.getElementById('tagName').value = '';
    document.getElementById('tagMessage').value = '';
    loadTagList();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur : ' + result.error);
  }
}

async function doPushTag(name) {
  terminalClear();
  terminalLog(`$ git push origin ${name}`, 'info');

  const result = await apiCall('pushTag', { name });

  if (result.success) {
    terminalLog(result.output, 'success');
    if (result.alreadyPushed) {
      showAlert('success', `Tag "${name}" déjà publié sur GitHub`);
    } else {
      showAlert('success', `Tag "${name}" poussé vers GitHub`);
    }
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur : ' + result.error);
  }
}

async function doDeleteTag(name) {
  const confirmed = await showConfirm(`Supprimer le tag "${name}" ?`, true, 'Supprimer', 'Annuler');
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git tag -d ${name}`, 'info');

  const localResult = await apiCall('deleteTag', { name });

  if (!localResult.success) {
    terminalLog(localResult.error, 'error');
    showAlert('error', 'Erreur : ' + localResult.error);
    return;
  }

  terminalLog(localResult.output, 'success');

  terminalLog(`$ git push origin --delete ${name}`, 'info');
  const remoteResult = await apiCall('deleteRemoteTag', { name });
  if (remoteResult.success) {
    if (remoteResult.output) terminalLog(remoteResult.output, 'success');
  } else {
    terminalLog(remoteResult.error, 'error');
    showAlert('error', 'Erreur remote : ' + remoteResult.error);
  }

  showAlert('success', `Tag "${name}" supprimé`);
  loadTagList();
}

// Supprimer un stash
async function doStashDrop(stashId) {
  const confirmed = await showConfirm(
    `Supprimer définitivement ${stashId} ?\nLes modifications contenues seront perdues.`,
    true,
    'Supprimer',
    'Annuler'
  );
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git stash drop ${stashId}`, 'info');

  const result = await apiCall('stashDrop', { stashId });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', 'Stash supprimé');
    loadStatus();
    loadStashList();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Mettre à jour la liste des fichiers
function updateFileList(modified, untracked, staged, stagedDeleted, conflicted) {
  const fileList = document.getElementById('fileList');
  const deletedSet = new Set(stagedDeleted || []);
  const conflictedSet = new Set(conflicted || []);

  // Filtrer les fichiers non suivis qui sont aussi stagés pour suppression
  // (évite les doublons D et U pour le même fichier)
  const filteredUntracked = untracked.filter(f => !deletedSet.has(f));
  // Exclure les fichiers en conflit des listes modified/staged pour éviter les doublons
  const filteredModified = modified.filter(f => !conflictedSet.has(f));
  const filteredStaged   = (staged || []).filter(f => !conflictedSet.has(f));

  const allFiles = [
    ...(conflicted || []).map(f => ({ name: f, status: 'C', type: 'conflicted' })),
    ...(stagedDeleted || []).map(f => ({ name: f, status: 'D', type: 'deleted' })),
    ...filteredModified.map(f => ({ name: f, status: 'M', type: 'modified' })),
    ...filteredUntracked.map(f => ({ name: f, status: 'U', type: 'untracked' })),
    ...filteredStaged.map(f => ({ name: f, status: 'A', type: 'added' }))
  ];

  if (allFiles.length === 0) {
    fileList.innerHTML = '<div class="empty-state"><i class="fas fa-check-circle"></i><p>Aucun fichier modifié</p></div>';
    return;
  }

  fileList.innerHTML = `
    <div class="select-all-row">
      <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
      <label for="selectAll">Tout sélectionner</label>
    </div>
    ${allFiles.map(f => {
      const diffable = f.type !== 'untracked';
      const nameSpan = diffable
        ? `<span class="file-name diff-link" onclick="showFileDiff('${f.name.replace(/'/g, "\\'")}','${f.type}')" title="Voir le diff">${f.name}</span>`
        : `<span class="file-name">${f.name}</span>`;
      return `
      <div class="file-item">
        <input type="checkbox" class="file-checkbox" value="${f.name}" data-status="${f.type}">
        <span class="file-status ${f.type}">${f.status}</span>
        ${nameSpan}
      </div>`;
    }).join('')}
  `;
}

// Mettre à jour le select checkout
function updateCheckoutSelect(modified, allFiles) {
  const select = document.getElementById('checkoutFileSelect');
  const deleteSelect = document.getElementById('deleteFileSelect');
  // Toujours utiliser tous les fichiers pour pouvoir restaurer n'importe lequel
  const files = allFiles || [];

  select.innerHTML = '<option value="">-- Sélectionner un fichier --</option>';
  deleteSelect.innerHTML = '<option value="">-- Sélectionner un fichier ou dossier --</option>';

  // Extraire les dossiers uniques pour le select delete
  const dirs = new Set();
  files.forEach(f => {
    const parts = f.split('/');
    for (let i = 1; i < parts.length; i++) {
      dirs.add(parts.slice(0, i).join('/'));
    }
  });

  if (dirs.size > 0) {
    const sepDirs = document.createElement('option');
    sepDirs.disabled = true;
    sepDirs.textContent = '── Dossiers ──';
    deleteSelect.appendChild(sepDirs);

    Array.from(dirs).sort().forEach(dir => {
      const opt = document.createElement('option');
      opt.value = dir;
      opt.dataset.isDir = 'true';
      opt.textContent = '📁 ' + dir + '/';
      deleteSelect.appendChild(opt);
    });

    const sepFiles = document.createElement('option');
    sepFiles.disabled = true;
    sepFiles.textContent = '── Fichiers ──';
    deleteSelect.appendChild(sepFiles);
  }

  const createFileOption = (value) => {
    const opt = document.createElement('option');
    opt.value = value;
    opt.textContent = modified.includes(value) ? value + ' (modifié)' : value;
    if (modified.includes(value)) opt.style.fontWeight = 'bold';
    return opt;
  };

  files.forEach(f => {
    select.appendChild(createFileOption(f));
    deleteSelect.appendChild(createFileOption(f));
  });
}

// Toggle select all
function toggleSelectAll() {
  const selectAll = document.getElementById('selectAll');
  const checkboxes = document.querySelectorAll('.file-checkbox');
  checkboxes.forEach(cb => cb.checked = selectAll.checked);
}

// Obtenir les fichiers sélectionnés avec leur statut
function getSelectedFilesWithStatus() {
  const checkboxes = document.querySelectorAll('.file-checkbox:checked');
  return Array.from(checkboxes).map(cb => ({
    name: cb.value,
    status: cb.dataset.status
  }));
}

// Charger l'historique
async function loadHistory() {
  const historyList = document.getElementById('historyList');
  historyList.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i> Chargement...</div>';

  const result = await apiCall('log');

  if (result.success && result.data.length > 0) {
    historyList.innerHTML = result.data.map((commit, index) => `
      <div class="commit-item">
        <div class="commit-details">
          <span class="commit-hash">${commit.hash}</span>
          <div class="commit-message">${commit.message}</div>
          <div class="commit-meta">
            <span><i class="fas fa-user"></i>${commit.author}</span>
            <span><i class="fas fa-calendar"></i>${commit.date}</span>
          </div>
        </div>
        <div class="commit-actions" style="gap: 5px; display: flex;">
          ${index === 0 ? `<button class="checkout-commit-btn" onclick="loadCommitMessageToField('${commit.hash}')" title="Charger le message complet dans le champ pour l'amender (amend)"><i class="fas fa-pencil-alt"></i></button>` : ''}
          <button class="checkout-commit-btn" onclick="showCommitDetail('${commit.hash}')" title="Voir le détail de ce commit (git show --stat)">
            <i class="fas fa-search"></i>
          </button>
          <button class="checkout-commit-btn" onclick="checkoutCommit('${commit.hash}')" title="Se placer sur ce commit (HEAD détaché) — plutôt pour explorer ou récupérer un fichier précis">
            <i class="fas fa-sign-in-alt"></i>
          </button>
          <button class="revert-commit-btn" data-hash="${commit.hash}" data-msg="${escapeHtml(commit.message)}" onclick="revertCommit(this.dataset.hash, this.dataset.msg)" title="Revert — Annuler ce commit (crée un nouveau commit inverse) — solution la plus propre pour annuler des commits déjà pushés">
            <i class="fas fa-undo"></i>
          </button>
        </div>
      </div>
    `).join('');
  } else {
    historyList.innerHTML = '<div class="empty-state"><i class="fas fa-history"></i><p>Aucun commit trouvé</p></div>';
  }
}

// Voir le détail d'un commit (git show --stat)
async function showCommitDetail(hash) {
  terminalClear();
  terminalLog(`$ git show --stat ${hash}`, 'info');

  const result = await apiCall('showCommit', { hash });

  if (result.success) {
    terminalLog(result.output, 'success');
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur : ' + result.error);
  }
}

// Checkout sur un commit (HEAD détaché)
async function checkoutCommit(hash) {
  const confirmed = await showConfirm(
    `Se placer sur le commit ${hash} ?\n\nCela va détacher le HEAD. Vous ne serez plus sur aucune branche.\nVous pourrez créer une branche depuis ce commit ou revenir sur une branche existante.`,
    true, 'Confirmer', 'Annuler'
  );
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git checkout ${hash}`, 'info');

  const result = await apiCall('checkoutCommit', { hash });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Annuler un commit (git revert)
async function revertCommit(hash, message) {
  const confirmed = await showConfirm(
    `Annuler le commit ${hash} ?\n\n"${message}"\n\nUn nouveau commit sera créé pour inverser ces modifications.`,
    true, 'Confirmer', 'Annuler'
  );
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git revert ${hash} --no-edit`, 'info');

  const result = await apiCall('revertCommit', { hash });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', 'Commit annulé avec succès');
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Stager les fichiers sélectionnés (git add)
async function stageFiles() {
  const selectedFiles = getSelectedFilesWithStatus().map(f => f.name);

  if (selectedFiles.length === 0) {
    showAlert('error', 'Veuillez sélectionner au moins un fichier à stager');
    return;
  }

  terminalClear();
  terminalLog(`$ git add ${selectedFiles.join(' ')}`, 'info');

  const result = await apiCall('stageFiles', { files: selectedFiles });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    loadStatus();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Retirer les fichiers du staging (git reset)
async function unstageFiles() {
  const selectedFiles = getSelectedFilesWithStatus().map(f => f.name);

  if (selectedFiles.length === 0) {
    showAlert('error', 'Veuillez sélectionner au moins un fichier à unstager');
    return;
  }

  terminalClear();
  terminalLog(`$ git reset HEAD -- ${selectedFiles.join(' ')}`, 'info');

  const result = await apiCall('unstageFiles', { files: selectedFiles });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    loadStatus();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Faire un commit
async function doCommit() {
  const selectedFiles = getSelectedFilesWithStatus();
  const message = document.getElementById('commitMessage').value.trim();

  if (selectedFiles.length === 0) {
    showAlert('error', 'Veuillez sélectionner au moins un fichier');
    return;
  }

  if (!message) {
    showAlert('error', 'Veuillez entrer un message de commit');
    return;
  }

  // Séparer les fichiers : ceux à ajouter vs ceux déjà stagés (suppressions)
  const filesToAdd = selectedFiles.filter(f => f.status !== 'deleted').map(f => f.name);
  const hasStaged = selectedFiles.some(f => f.status === 'deleted');

  terminalClear();
  if (filesToAdd.length > 0) {
    terminalLog('$ git add ' + filesToAdd.join(' '), 'info');
  }
  if (hasStaged) {
    terminalLog('$ (fichiers stagés pour suppression inclus)', 'info');
  }
  terminalLog('$ git commit -m "' + message + '"', 'info');

  const result = await apiCall('commit', { files: filesToAdd, message, hasStaged });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', 'Commit effectué avec succès');
    currentAhead++;
    clearMessageField();
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur lors du commit: ' + result.error);
  }
}

// Commit et Push
async function doCommitAndPush() {
  const selectedFiles = getSelectedFilesWithStatus();
  const message = document.getElementById('commitMessage').value.trim();

  if (selectedFiles.length === 0) {
    showAlert('error', 'Veuillez sélectionner au moins un fichier');
    return;
  }

  if (!message) {
    showAlert('error', 'Veuillez entrer un message de commit');
    return;
  }

  // Séparer les fichiers : ceux à ajouter vs ceux déjà stagés (suppressions)
  const filesToAdd = selectedFiles.filter(f => f.status !== 'deleted').map(f => f.name);
  const hasStaged = selectedFiles.some(f => f.status === 'deleted');

  terminalClear();
  terminalLog('$ git add && git commit && git push', 'info');

  const result = await apiCall('commitAndPush', { files: filesToAdd, message, hasStaged });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', 'Commit et Push effectués avec succès');
    currentAhead = 0;
    clearMessageField();
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Amend - Ajouter des fichiers au dernier commit et/ou changer le message
function updateClearBtn() {
  const field = document.getElementById('commitMessage');
  document.getElementById('clearMessageBtn').style.display = field.value ? 'block' : 'none';
}

function clearMessageField() {
  document.getElementById('commitMessage').value = '';
  updateClearBtn();
}

async function loadCommitMessageToField(hash) {
  const result = await apiCall('getCommitMessage', { hash });
  if (result.success) {
    const field = document.getElementById('commitMessage');
    field.value = result.message;
    updateClearBtn();
    field.focus();
    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
    showAlert('success', 'Message chargé — modifiez-le puis cliquez Amend');
  } else {
    showAlert('error', 'Erreur : ' + result.error);
  }
}

async function doAmendLastCommit() {
  const selectedFiles = getSelectedFilesWithStatus();
  const message = document.getElementById('commitMessage').value.trim();

  const hasStagedInList = document.querySelectorAll('.file-checkbox[data-status="added"]').length > 0;
  if (selectedFiles.length === 0 && !message && !hasStagedInList) {
    showAlert('error', 'Sélectionnez des fichiers et/ou saisissez un nouveau message');
    return;
  }

  if (currentHasRemote && currentAhead === 0) {
    const ok = await showConfirm('Ce commit a déjà été pushé sur le remote. Un git push --force sera nécessaire pour l\'écraser. Continuer quand même ?');
    if (!ok) return;
  }

  const filesToAdd = selectedFiles.filter(f => f.status !== 'deleted').map(f => f.name);
  const hasStaged = selectedFiles.some(f => f.status === 'deleted');

  terminalClear();
  if (filesToAdd.length > 0) {
    terminalLog('$ git add ' + filesToAdd.join(' '), 'info');
  }
  if (hasStaged) {
    terminalLog('$ (suppressions déjà stagées incluses)', 'info');
  }
  terminalLog(message ? `$ git commit --amend -m "${message}"` : '$ git commit --amend --no-edit', 'info');

  const result = await apiCall('amendLastCommit', { files: filesToAdd, hasStaged, message });

  if (result.success) {
    terminalLog(result.output, 'success');
    const msg = message ? 'Commit amendé avec succès' : 'Fichiers ajoutés au dernier commit avec succès';
    showAlert('success', msg);
    if (message) clearMessageField();
    loadStatus();
    loadHistory();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur lors du amend: ' + result.error);
  }
}

// Push
async function doPush() {
  terminalClear();
  terminalLog('$ git push origin', 'info');

  const result = await apiCall('push');

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', 'Push effectué avec succès');
    currentAhead = 0;
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur lors du push: ' + result.error);
  }
}

// Pull
async function doPull() {
  const rebase = document.getElementById('pullRebaseCheck').checked;
  terminalClear();
  terminalLog(rebase ? '$ git pull --rebase origin' : '$ git pull origin', 'info');

  const result = await apiCall('pull', { rebase });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', 'Pull effectué avec succès');
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur lors du pull: ' + result.error);
  }
}

// Fetch
async function doFetch() {
  terminalClear();
  terminalLog('$ git fetch origin', 'info');

  const result = await apiCall('fetch');

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', 'Fetch effectué avec succès');
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur lors du fetch: ' + result.error);
  }
}

// Rebase — continuer
async function doRebaseContinue() {
  terminalLog('$ git rebase --continue', 'info');
  const result = await apiCall('rebaseContinue');
  if (result.success) {
    showAlert('success', 'Rebase terminé');
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur : ' + result.error);
  }
}

// Rebase — abandonner
async function doRebaseAbort() {
  const confirmed = await showConfirm('Abandonner le rebase ? Les modifications en cours de rebase seront annulées et vous retrouverez l\'état avant le pull --rebase.');
  if (!confirmed) return;
  terminalLog('$ git rebase --abort', 'info');
  const result = await apiCall('rebaseAbort');
  if (result.success) {
    showAlert('success', 'Rebase abandonné');
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur : ' + result.error);
  }
}

// Masquer la liste des commits quand origin/main est sélectionné (hashes locaux non pertinents)
function onCheckoutSourceChange() {
  const source = document.getElementById('checkoutSource').value;
  const container = document.getElementById('fileCommitsContainer');
  if (source === 'origin/main') {
    container.style.display = 'none';
  } else if (document.getElementById('fileCommitsList').innerHTML.trim() !== '') {
    container.style.display = 'block';
  }
}

// Ouvrir/fermer la liste des commits par fichier
function toggleFileCommits() {
  const list = document.getElementById('fileCommitsList');
  const chevron = document.getElementById('fileCommitsChevron');
  const isOpen = list.style.display !== 'none';
  list.style.display = isOpen ? 'none' : 'block';
  chevron.style.transform = isOpen ? '' : 'rotate(90deg)';
}

// Charger les commits d'un fichier spécifique
async function loadFileCommits() {
  const file = document.getElementById('checkoutFileSelect').value;
  const container = document.getElementById('fileCommitsContainer');
  const list = document.getElementById('fileCommitsList');
  const chevron = document.getElementById('fileCommitsChevron');

  if (!file) {
    container.style.display = 'none';
    return;
  }

  const result = await apiCall('fileLog', { file });

  if (result.success && result.data.length > 0) {
    list.innerHTML = result.data.map(commit => `
      <div class="commit-item">
        <div class="commit-details">
          <span class="commit-hash">${commit.hash}</span>
          <div class="commit-message">${commit.message}</div>
          <div class="commit-meta"><span><i class="fas fa-calendar"></i>${commit.date}</span></div>
        </div>
        <div class="commit-actions" style="gap:5px;display:flex;">
          <button class="checkout-commit-btn" onclick="showCommitDetail('${commit.hash}')" title="Voir le détail de ce commit">
            <i class="fas fa-search"></i>
          </button>
          <button class="checkout-commit-btn" onclick="doCheckout('${commit.hash}')" title="Restaurer le fichier à cette version">
            <i class="fas fa-undo"></i>
          </button>
        </div>
      </div>
    `).join('');
    // Liste fermée par défaut, chevron remis à zéro
    list.style.display = 'none';
    chevron.style.transform = '';
    container.style.display = 'block';
  } else {
    container.style.display = 'none';
  }
}

// Checkout un fichier — sourceOverride fourni par le bouton Récupérer d'une ligne de la liste
async function doCheckout(sourceOverride) {
  const file = document.getElementById('checkoutFileSelect').value;
  const source = sourceOverride || document.getElementById('checkoutSource').value;

  if (!file) {
    showAlert('error', 'Veuillez sélectionner un fichier');
    return;
  }

  const confirmed = await showConfirm(`Êtes-vous sûr de vouloir récupérer "${file}" depuis ${source} ? Les modifications locales seront perdues.`);
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git checkout ${source} -- ${file}`, 'info');

  const result = await apiCall('checkout', { file, source });

  if (result.success) {
    if (result.noChange) {
      terminalLog(result.output, 'warning');
      showAlert('warning', `Le fichier "${file}" à ce commit est identique à la version actuelle — aucun changement`);
    } else {
      terminalLog(result.output || 'Fichier récupéré avec succès', 'success');
      showAlert('success', `Fichier "${file}" récupéré — badge A dans la liste`);
    }
    loadStatus();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Annuler toutes les modifications
async function doDiscardAll() {
  const confirmed = await showConfirm('ATTENTION: Toutes les modifications locales seront perdues. Êtes-vous sûr ?', true);
  if (!confirmed) return;

  terminalClear();
  terminalLog('$ git checkout -- .', 'info');

  const result = await apiCall('discardAll');

  if (result.success) {
    terminalLog('Toutes les modifications ont été annulées', 'success');
    showAlert('success', 'Modifications annulées');
    loadStatus();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Supprimer un fichier ou dossier du dépôt ET du disque
async function doRemoveFromRepo() {
  const select = document.getElementById('deleteFileSelect');
  const file = select.value;
  const isDir = select.options[select.selectedIndex]?.dataset.isDir === 'true';

  if (!file) {
    showAlert('error', 'Veuillez sélectionner un fichier ou un dossier');
    return;
  }

  const label = isDir ? `le dossier "${file}/" et TOUS ses fichiers` : `le fichier "${file}"`;
  const confirmed = await showConfirm(`ATTENTION: ${label} sera supprimé du dépôt ET de votre disque. Cette action est irréversible. Êtes-vous sûr ?`, true);
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git rm ${isDir ? '-r ' : ''}${file}`, 'info');

  const result = await apiCall('removeFromRepo', { file, isDir });

  if (result.success) {
    terminalLog(result.output, 'success');
    const labelSuccess = isDir ? `Dossier "${file}/"` : `Fichier "${file}"`;
    showAlert('success', `${labelSuccess} supprimé. Faites Commit & Push !`);
    loadStatus();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Arrêter le suivi d'un fichier ou dossier (sans le supprimer du disque)
async function doUntrackFile() {
  const select = document.getElementById('deleteFileSelect');
  const file = select.value;
  const isDir = select.options[select.selectedIndex]?.dataset.isDir === 'true';

  if (!file) {
    showAlert('error', 'Veuillez sélectionner un fichier ou un dossier');
    return;
  }

  const label = isDir ? `le dossier "${file}/" et tous ses fichiers` : `le fichier "${file}"`;
  const confirmed = await showConfirm(`${label.charAt(0).toUpperCase() + label.slice(1)} sera retiré du suivi Git mais conservé sur votre disque. Voulez-vous continuer ?`);
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git rm --cached ${isDir ? '-r ' : ''}${file}`, 'info');

  const result = await apiCall('untrackFile', { file, isDir });

  if (result.success) {
    terminalLog(result.output, 'success');
    const labelSuccess = isDir ? `Dossier "${file}/"` : `Fichier "${file}"`;
    showAlert('success', `${labelSuccess} retiré du suivi. Faites Commit & Push !`);
    loadStatus();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Charger le contenu de .gitignore
async function loadGitignore() {
  const list = document.getElementById('gitignoreList');
  list.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i> Chargement...</div>';

  const result = await apiCall('getGitignore');

  if (result.success) {
    const patterns = result.data.patterns || [];

    if (patterns.length === 0) {
      list.innerHTML = '<div class="gitignore-empty"><i class="fas fa-check-circle"></i> Aucun fichier ignoré</div>';
    } else {
      list.innerHTML = patterns.map(pattern => `
        <div class="gitignore-item">
          <span class="pattern">${pattern}</span>
          <button class="remove-btn" onclick="removeFromGitignore('${pattern.replace(/'/g, "\\'")}')" title="Retirer">
            <i class="fas fa-times"></i>
          </button>
        </div>
      `).join('');
    }

    // Mettre à jour la liste des fichiers non suivis
    updateUntrackedSelect(result.data.untracked || []);
  } else {
    list.innerHTML = '<div class="gitignore-empty"><i class="fas fa-exclamation-triangle"></i> Erreur de chargement</div>';
  }
}

// Mettre à jour le select des fichiers non suivis
function updateUntrackedSelect(files) {
  const select = document.getElementById('untrackedFiles');
  select.innerHTML = '<option value="">-- Fichiers non suivis --</option>';
  files.forEach(f => {
    const option = document.createElement('option');
    option.value = f;
    option.textContent = f;
    select.appendChild(option);
  });
}

// Ajouter un fichier au .gitignore (depuis le select)
async function addToGitignore() {
  const select = document.getElementById('untrackedFiles');
  const pattern = select.value;

  if (!pattern) {
    showAlert('error', 'Veuillez sélectionner un fichier');
    return;
  }

  await addPatternToGitignore(pattern);
}

// Ajouter un pattern personnalisé au .gitignore
async function addCustomToGitignore() {
  const input = document.getElementById('customIgnore');
  const pattern = input.value.trim();

  if (!pattern) {
    showAlert('error', 'Veuillez saisir un pattern');
    return;
  }

  await addPatternToGitignore(pattern);
  input.value = '';
}

// Ajouter un pattern au .gitignore
async function addPatternToGitignore(pattern) {
  terminalClear();
  terminalLog(`Ajout de "${pattern}" à .gitignore`, 'info');

  const result = await apiCall('addToGitignore', { pattern });

  if (result.success) {
    terminalLog('Pattern ajouté avec succès', 'success');
    showAlert('success', `"${pattern}" ajouté au .gitignore`);
    loadGitignore();
    loadStatus();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Retirer un pattern du .gitignore
async function removeFromGitignore(pattern) {
  const confirmed = await showConfirm(`Retirer "${pattern}" du .gitignore ?`);
  if (!confirmed) return;

  terminalClear();
  terminalLog(`Retrait de "${pattern}" de .gitignore`, 'info');

  const result = await apiCall('removeFromGitignore', { pattern });

  if (result.success) {
    terminalLog('Pattern retiré avec succès', 'success');
    showAlert('success', `"${pattern}" retiré du .gitignore`);
    loadGitignore();
    loadStatus();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// ========== GESTION DES BRANCHES ==========

// Charger la liste des branches
async function loadBranches() {
  const content = document.getElementById('branchesContent');
  content.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i> Chargement...</div>';

  const result = await apiCall('branches');

  if (result.success) {
    const { current, local, remote, isDetached, detachedAt, isRebasing } = result.data;
    let html = '';

    // Avertissement si rebase en cours (priorité sur HEAD détaché générique)
    if (isRebasing) {
      html += `
        <div class="detached-banner" style="border-color: var(--info-color);">
          <i class="fas fa-sync" style="color: var(--info-color);"></i>
          <span class="detached-info">
            <strong>Rebase en cours</strong>
            <span style="color: var(--text-secondary);"> — HEAD temporairement détaché</span>
          </span>
          <button class="btn btn-primary detached-btn" onclick="doRebaseContinue()">
            <i class="fas fa-play"></i> Continuer
          </button>
          <button class="btn btn-danger detached-btn" onclick="doRebaseAbort()">
            <i class="fas fa-times"></i> Abandonner
          </button>
        </div>
      `;
    } else if (isDetached) {
      html += `
        <div class="detached-banner">
          <i class="fas fa-exclamation-triangle" style="color: var(--warning-color);"></i>
          <span class="detached-info">
            <strong>HEAD détaché</strong> · <code>${detachedAt}</code>
            <span style="color: var(--text-secondary);"> — aucune branche active</span>
          </span>
          <button class="btn btn-primary detached-btn" onclick="createBranchFromDetached()">
            <i class="fas fa-code-branch"></i> Créer une branche
          </button>
        </div>
      `;
    }

    // Branches locales (filtrer le HEAD détaché, triées avec l'actuelle en premier)
    const filteredLocal = local.filter(b => !b.name.includes('(HEAD'));
    if (filteredLocal.length > 0) {
      const sortedLocal = [...filteredLocal].sort((a, b) => {
        if (a.current) return -1;
        if (b.current) return 1;
        return a.name.localeCompare(b.name);
      });

      html += '<div class="branch-section-title"><i class="fas fa-laptop"></i> Branches locales</div>';
      html += '<div class="branch-list">';
      sortedLocal.forEach(branch => {
        const isCurrent = branch.current;
        const hasUpstream = branch.upstream && branch.upstream.length > 0;

        // Boutons spécifiques selon upstream
        let renameHtml = '';
        if (!hasUpstream) {
          renameHtml += '<button class="publish-btn" onclick="pushBranch(\'' + branch.name + '\')" title="Publier sur GitHub"><i class="fas fa-cloud-upload-alt"></i></button>';
        }
        renameHtml += '<button class="rename-btn" onclick="renameBranch(\'' + branch.name + '\')" title="Renommer"><i class="fas fa-pen"></i></button>';

        html += `
          <div class="branch-item ${isCurrent ? 'current' : ''}">
            <div class="branch-info">
              <span class="branch-name ${isCurrent ? 'current' : ''}">${branch.name}</span>
              <span class="branch-badge ${isCurrent ? '' : 'hidden'}">${isCurrent ? 'actuelle' : ''}</span>
              ${hasUpstream ? '<span class="branch-badge tracked"><i class="fas fa-cloud"></i> suivie</span>' : '<span class="branch-badge local-only">locale</span>'}
              <span class="branch-tracking">
                ${branch.ahead > 0 ? `<span class="ahead">↑${branch.ahead}</span>` : ''}
                ${branch.behind > 0 ? `<span class="behind">↓${branch.behind}</span>` : ''}
              </span>
            </div>
            <div class="branch-actions">
              ${renameHtml}
              ${!isCurrent ? `
                <button class="switch-btn" onclick="switchBranch('${branch.name}')" title="Basculer">
                  <i class="fas fa-exchange-alt"></i>
                </button>
                <button class="merge-btn" onclick="mergeBranch('${branch.name}')" title="Fusionner dans ${current}">
                  <i class="fas fa-code-branch"></i>
                </button>
                <button class="delete-btn" onclick="deleteBranch('${branch.name}', ${hasUpstream})" title="Supprimer">
                  <i class="fas fa-times"></i>
                </button>
              ` : ''}
            </div>
          </div>
        `;
      });
      html += '</div>';
    }

    // Branches distantes non trackées
    if (remote.length > 0) {
      html += '<div class="branch-section-title"><i class="fas fa-cloud"></i> Branches distantes</div>';
      html += '<div class="branch-list">';
      remote.forEach(branch => {
        // Extraire le nom de branche sans le préfixe origin/
        const branchName = branch.replace(/^origin\//, '');
        html += `
          <div class="branch-item">
            <div class="branch-info">
              <span class="branch-name">${branch}</span>
              <span class="branch-badge remote-only">distante</span>
            </div>
            <div class="branch-actions">
              <button class="switch-btn" onclick="switchBranch('${branch}')" title="Récupérer et basculer">
                <i class="fas fa-download"></i>
              </button>
              <button class="delete-btn" onclick="deleteRemoteBranchDirect('${branchName}')" title="Supprimer sur origin">
                <i class="fas fa-times"></i>
              </button>
            </div>
          </div>
        `;
      });
      html += '</div>';
    }

    if (filteredLocal.length === 0 && remote.length === 0) {
      html = '<div class="empty-state"><i class="fas fa-code-branch"></i><p>Aucune branche trouvée</p></div>';
    }

    content.innerHTML = html;

    // Masquer le formulaire de création en HEAD détaché ou rebase en cours
    const createForm = document.querySelector('.create-branch-form');
    if (createForm) {
      createForm.style.display = (isDetached || isRebasing) ? 'none' : '';
    }

    // Remplir le sélecteur de branche source
    if (!isDetached) {
      const sourceSelect = document.getElementById('sourceBranchSelect');
      if (sourceSelect) {
        let options = `<option value="">Depuis: ${current || 'branche en cours'}</option>`;
        options += '<option value="__orphan__">🆕 Branche vide (sans fichiers, sans historique)</option>';
        options += '<option value="__freshstart__">🔄 Nouveau départ (fichiers actuels, sans historique)</option>';
        // Ajouter les branches locales (sauf la branche courante)
        filteredLocal.forEach(branch => {
          if (!branch.current) {
            options += `<option value="${branch.name}">${branch.name}</option>`;
          }
        });
        // Ajouter les branches distantes
        remote.forEach(branch => {
          options += `<option value="${branch}">${branch}</option>`;
        });
        sourceSelect.innerHTML = options;
      }
    }
  } else {
    content.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-triangle"></i><p>' + result.error + '</p></div>';
  }
}

// Créer une branche depuis un HEAD détaché
function createBranchFromDetached() {
  const modal = document.getElementById('confirmModal');
  const modalContent = modal.querySelector('.modal-content');

  modalContent.innerHTML = `
    <h3><i class="fas fa-code-branch"></i> Créer une branche ici</h3>
    <p style="color: var(--text-secondary); margin-bottom: 15px;">
      Créer une nouvelle branche depuis le commit actuel (HEAD détaché) :
    </p>
    <input type="text" id="detachedBranchNameInput" class="file-select"
      placeholder="nom-de-la-branche"
      style="width: 100%; margin-bottom: 20px;">
    <div class="modal-buttons">
      <button class="btn btn-secondary" onclick="closeRemoteForm()">Annuler</button>
      <button class="btn btn-primary" onclick="confirmCreateBranchFromDetached()">
        <i class="fas fa-plus"></i> Créer
      </button>
    </div>
  `;
  modal.classList.add('active');
  document.getElementById('detachedBranchNameInput').focus();
}

async function confirmCreateBranchFromDetached() {
  const branchName = document.getElementById('detachedBranchNameInput').value.trim();
  if (!branchName) {
    showAlert('error', 'Nom de branche requis');
    return;
  }

  closeRemoteForm();
  terminalClear();
  terminalLog(`$ git checkout -b ${branchName}`, 'info');

  const result = await apiCall('createBranch', { branch: branchName, checkout: true });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Changer de branche
async function switchBranch(branch, force = false) {
  if (!force) {
    const confirmed = await showConfirm(`Basculer vers la branche "${branch}" ?`);
    if (!confirmed) return;
  }

  terminalClear();
  const forceFlag = force ? '-f ' : '';
  terminalLog(`$ git checkout ${forceFlag}${branch}`, 'info');

  const result = await apiCall('switchBranch', { branch, force });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    refreshAll();
  } else {
    terminalLog(result.error, 'error');

    // Si l'erreur est due à des fichiers non suivis, proposer le force checkout
    if (result.needsForce) {
      const forceConfirmed = await showConfirm(
        `Des fichiers non suivis bloquent le basculement.\n\n` +
        `⚠️ Forcer le basculement ?\n\n` +
        `ATTENTION : Les fichiers locaux en conflit seront écrasés par ceux de la branche "${branch}".`,
        true
      );
      if (forceConfirmed) {
        await switchBranch(branch, true);
        return;
      }
    }

    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Créer une nouvelle branche
async function createBranch() {
  const input = document.getElementById('newBranchName');
  const sourceSelect = document.getElementById('sourceBranchSelect');
  const sourceHash = (document.getElementById('sourceHashInput')?.value || '').trim();
  const branchName = input.value.trim();
  const sourceBranch = sourceHash || (sourceSelect ? sourceSelect.value : '');
  const isOrphan = sourceBranch === '__orphan__';
  const isFreshStart = sourceBranch === '__freshstart__';

  if (!branchName) {
    showAlert('error', 'Veuillez entrer un nom de branche');
    return;
  }

  terminalClear();
  if (isOrphan) {
    terminalLog(`$ git checkout --orphan ${branchName}`, 'info');
    terminalLog(`$ git rm -rf --cached .`, 'info');
    terminalLog(`$ git commit --allow-empty -m "Initial commit"`, 'info');
  } else if (isFreshStart) {
    terminalLog(`$ git checkout --orphan ${branchName}`, 'info');
    terminalLog(`$ git add -A`, 'info');
    terminalLog(`$ git commit -m "Fresh start"`, 'info');
  } else if (sourceBranch) {
    terminalLog(`$ git checkout -b ${branchName} ${sourceBranch}`, 'info');
  } else {
    terminalLog(`$ git checkout -b ${branchName}`, 'info');
  }

  const params = { branch: branchName, checkout: true };
  if (isOrphan) {
    params.orphan = true;
  } else if (isFreshStart) {
    params.freshStart = true;
  } else if (sourceBranch) {
    params.sourceBranch = sourceBranch;
  }

  const result = await apiCall('createBranch', params);

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    input.value = '';
    if (sourceSelect) sourceSelect.value = '';
    const hashInput = document.getElementById('sourceHashInput');
    if (hashInput) hashInput.value = '';
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Renommer une branche
async function renameBranch(oldName) {
  const modal = document.getElementById('confirmModal');
  const modalContent = modal.querySelector('.modal-content');

  modalContent.innerHTML = `
    <h3><i class="fas fa-pen"></i> Renommer la branche</h3>
    <p style="color: var(--text-secondary); margin-bottom: 15px;">
      Renommer la branche <strong>${oldName}</strong> :
    </p>
    <input type="text" id="newBranchNameInput" class="file-select"
      value="${oldName}"
      style="width: 100%; margin-bottom: 20px;">
    <div class="modal-buttons">
      <button class="btn btn-secondary" onclick="closeRemoteForm()">Annuler</button>
      <button class="btn btn-primary" onclick="confirmRenameBranch('${oldName}')">
        <i class="fas fa-check"></i> Renommer
      </button>
    </div>
  `;

  modal.classList.add('active');

  // Focus et sélection du texte
  setTimeout(() => {
    const input = document.getElementById('newBranchNameInput');
    input.focus();
    input.select();
  }, 100);
}

// Confirmer le renommage
async function confirmRenameBranch(oldName) {
  const newName = document.getElementById('newBranchNameInput').value.trim();

  if (!newName) {
    showAlert('error', 'Veuillez entrer un nom de branche');
    return;
  }

  if (newName === oldName) {
    closeRemoteForm();
    return;
  }

  terminalClear();
  terminalLog(`$ git branch -m ${oldName} ${newName}`, 'info');

  const result = await apiCall('renameBranch', { oldName, newName });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    closeRemoteForm();
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Supprimer une branche
async function deleteBranch(branch, hasUpstream = false) {
  const confirmed = await showConfirm(`Supprimer la branche "${branch}" ? Cette action est irréversible.`, true);
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git branch -d ${branch}`, 'info');

  const result = await apiCall('deleteBranch', { branch });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);

    // Proposer de supprimer aussi sur le dépôt distant si la branche était publiée
    if (hasUpstream) {
      const deleteRemote = await showConfirm(`Supprimer aussi la branche "${branch}" sur GitHub ?`, true, 'Oui', 'Non');
      if (deleteRemote) {
        await deleteRemoteBranch(branch);
      }
    }

    loadBranches();
  } else {
    // Si la branche n'est pas fusionnée, proposer la suppression forcée
    if (result.error.includes('not fully merged')) {
      const forceDelete = await showConfirm(`La branche "${branch}" n'est pas fusionnée. Forcer la suppression ?`, true);
      if (forceDelete) {
        terminalLog(`$ git branch -D ${branch}`, 'info');
        const forceResult = await apiCall('deleteBranch', { branch, force: true });
        if (forceResult.success) {
          terminalLog(forceResult.output, 'success');
          showAlert('success', forceResult.output);

          // Proposer de supprimer aussi sur le dépôt distant
          if (hasUpstream) {
            const deleteRemote = await showConfirm(`Supprimer aussi la branche "${branch}" sur GitHub ?`, true, 'Oui', 'Non');
            if (deleteRemote) {
              await deleteRemoteBranch(branch);
            }
          }

          loadBranches();
        } else {
          terminalLog(forceResult.error, 'error');
          showAlert('error', 'Erreur: ' + forceResult.error);
        }
      }
    } else {
      terminalLog(result.error, 'error');
      showAlert('error', 'Erreur: ' + result.error);
    }
  }
}

// Supprimer une branche sur le dépôt distant
async function deleteRemoteBranch(branch) {
  terminalLog(`$ git push origin --delete ${branch}`, 'info');

  const result = await apiCall('deleteRemoteBranch', { branch });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// Supprimer une branche distante directement (depuis la liste des branches distantes)
async function deleteRemoteBranchDirect(branch) {
  const confirmed = await showConfirm(`Supprimer la branche "${branch}" sur GitHub ?\n\nCette action est irréversible.`, true);
  if (!confirmed) return;

  terminalClear();
  await deleteRemoteBranch(branch);
  await loadBranches();
}

// Fusionner une branche
async function mergeBranch(branch) {
  const confirmed = await showConfirm(`Fusionner la branche "${branch}" dans la branche en cours ?`);
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git merge ${branch}`, 'info');

  const result = await apiCall('mergeBranch', { branch });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', `Branche "${branch}" fusionnée avec succès`);
    refreshAll();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur de fusion: ' + result.error);
  }
}

// Publier une branche sur le dépôt distant
async function pushBranch(branch) {
  const confirmed = await showConfirm(`Publier la branche "${branch}" sur GitHub ?`);
  if (!confirmed) return;

  terminalClear();
  terminalLog(`$ git push -u origin ${branch}`, 'info');

  const result = await apiCall('pushBranch', { branch });

  if (result.success) {
    terminalLog(result.output, 'success');
    showAlert('success', result.output);
    loadBranches();
  } else {
    terminalLog(result.error, 'error');
    showAlert('error', 'Erreur: ' + result.error);
  }
}

// ── Diff visuel ──────────────────────────────────────────────────────────────

let _diffRaw = '';

function setDiffTitle(titleEl, file, label) {
  titleEl.innerHTML = `<i class="fas fa-code"></i> ${file} <small style="font-weight:normal;color:var(--text-secondary);">(${label})</small>`;
}

function renderDiffHtml(raw, emptyMsg = 'Aucune modification détectable') {
  const hunks = parseDiff(raw);
  return hunks.length ? renderDiff(hunks) : `<div class="diff-empty">${emptyMsg}</div>`;
}

async function showFileDiff(file, type) {
  const modal   = document.getElementById('diffModal');
  const title   = document.getElementById('diffModalTitle');
  const content = document.getElementById('diffModalContent');

  const staged = (type === 'added' || type === 'deleted');
  _diffRaw = '';

  title.innerHTML = `<i class="fas fa-code"></i> ${file}`;
  content.innerHTML = '<div class="diff-empty"><i class="fas fa-spinner fa-spin"></i> Chargement...</div>';
  modal.classList.add('active');

  let html = '';

  if (type === 'modified' || type === 'added') {
    const [resUnstaged, resStaged] = await Promise.all([
      apiCall('fileDiff', { file, staged: false }),
      apiCall('fileDiff', { file, staged: true })
    ]);

    const hunksUnstaged = parseDiff(resUnstaged.success ? resUnstaged.data.diff : '');
    const hunksStaged   = parseDiff(resStaged.success   ? resStaged.data.diff   : '');
    _diffRaw = [resUnstaged.data?.diff, resStaged.data?.diff].filter(Boolean).join('\n');

    if (hunksUnstaged.length === 0 && hunksStaged.length === 0) {
      html = '<div class="diff-empty">Aucune modification détectable</div>';
    } else if (hunksStaged.length > 0 && hunksUnstaged.length > 0) {
      setDiffTitle(title, file, 'stagé + non stagé');
      html = `
        <div class="diff-tabs">
          <button class="diff-tab active" id="diffTabUnstaged" onclick="switchDiffTab('unstaged')">
            Non stagé (${hunksUnstaged.length} hunk${hunksUnstaged.length > 1 ? 's' : ''})
          </button>
          <button class="diff-tab" id="diffTabStaged" onclick="switchDiffTab('staged')">
            Stagé (${hunksStaged.length} hunk${hunksStaged.length > 1 ? 's' : ''})
          </button>
        </div>
        <div id="diffPaneUnstaged">${renderDiff(hunksUnstaged)}</div>
        <div id="diffPaneStaged" style="display:none">${renderDiff(hunksStaged)}</div>`;
    } else {
      setDiffTitle(title, file, hunksStaged.length ? 'stagé' : 'non stagé');
      html = renderDiff(hunksUnstaged.length ? hunksUnstaged : hunksStaged);
    }
  } else if (type === 'conflicted') {
    const res        = await apiCall('fileDiff', { file, staged: false, conflicted: true });
    _diffRaw         = res.data?.diff ?? '';
    const hasMarkers = _diffRaw.split('\n').some(l => /^\+[<=>]{7}/.test(l));
    const hunks      = parseDiff(_diffRaw);

    const bannerStyle = 'padding:8px 12px;margin-bottom:10px;border-radius:4px;font-size:0.88em;border-left:3px solid;';
    let banner;
    if (hasMarkers) {
      banner = `<div style="${bannerStyle}background:color-mix(in srgb,var(--warning-color) 15%,transparent);border-color:var(--warning-color);color:var(--text-primary);">
        <i class="fas fa-exclamation-triangle" style="color:var(--warning-color);margin-right:6px;"></i>Marqueurs de conflit détectés — résolvez les conflits avant de stager.
      </div>`;
      setDiffTitle(title, file, 'conflits à résoudre');
    } else {
      banner = `<div style="${bannerStyle}background:color-mix(in srgb,var(--success-color) 15%,transparent);border-color:var(--success-color);color:var(--text-primary);">
        <i class="fas fa-check-circle" style="color:var(--success-color);margin-right:6px;"></i>Conflit résolu — stagez ce fichier pour finaliser la fusion.
      </div>`;
      setDiffTitle(title, file, 'conflit résolu');
    }
    html = banner + renderDiffHtml(_diffRaw, 'Aucune modification par rapport à HEAD');
  } else {
    const res = await apiCall('fileDiff', { file, staged });
    _diffRaw  = res.data?.diff ?? '';
    setDiffTitle(title, file, staged ? 'stagé' : 'non stagé');
    html = renderDiffHtml(_diffRaw);
  }

  content.innerHTML = html;
}

function switchDiffTab(tab) {
  document.getElementById('diffTabUnstaged').classList.toggle('active', tab === 'unstaged');
  document.getElementById('diffTabStaged').classList.toggle('active', tab === 'staged');
  document.getElementById('diffPaneUnstaged').style.display = tab === 'unstaged' ? '' : 'none';
  document.getElementById('diffPaneStaged').style.display   = tab === 'staged'   ? '' : 'none';
}

function closeDiffModal() {
  document.getElementById('diffModal').classList.remove('active');
}

function copyDiff() {
  if (!_diffRaw) return;
  const btn = document.getElementById('copyDiffBtn');

  const feedback = () => {
    btn.innerHTML = '<i class="fas fa-check"></i> Copié';
    setTimeout(() => { btn.innerHTML = '<i class="fas fa-copy"></i> Copier'; }, 2000);
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(_diffRaw).then(feedback);
  } else {
    const ta = document.createElement('textarea');
    ta.value = _diffRaw;
    ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none;';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); feedback(); } catch (e) {}
    document.body.removeChild(ta);
  }
}

function parseDiff(diffText) {
  if (!diffText || !diffText.trim()) return [];

  const lines  = diffText.split('\n');
  const hunks  = [];
  let hunk     = null;

  for (const line of lines) {
    if (line.startsWith('@@')) {
      const m = line.match(/@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)/);
      if (m) {
        hunk = { header: line, oldStart: +m[1], newStart: +m[2], lines: [] };
        hunks.push(hunk);
      }
    } else if (hunk && (line.startsWith('-') || line.startsWith('+') || line.startsWith(' '))) {
      hunk.lines.push(line);
    }
  }

  return hunks;
}

function renderDiff(hunks) {
  let html = '<table class="diff-table"><colgroup><col class="diff-col-num"><col class="diff-col-num"><col></colgroup>';

  for (const hunk of hunks) {
    html += `<tr class="diff-hunk-header"><td colspan="3">${escapeHtml(hunk.header)}</td></tr>`;

    let o = hunk.oldStart;
    let n = hunk.newStart;

    for (const line of hunk.lines) {
      const ch   = line[0];
      const type = ch === '-' ? 'removed' : ch === '+' ? 'added' : 'context';
      const oldN = (type !== 'added')   ? o++ : '';
      const newN = (type !== 'removed') ? n++ : '';

      html += `<tr class="diff-line diff-${type}">
        <td class="diff-num">${oldN}</td>
        <td class="diff-num">${newN}</td>
        <td class="diff-content"><span class="diff-prefix">${escapeHtml(ch)}</span>${escapeHtml(line.slice(1))}</td>
      </tr>`;
    }
  }

  return html + '</table>';
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ── Multi-dépôts ─────────────────────────────────────────────────────────────

async function loadRepoSwitcher() {
  const result = await apiCall('listRepos');
  if (!result.success || !result.data || result.data.length === 0) return;

  const switcher = document.getElementById('repoSwitcher');
  const select   = document.getElementById('repoSelect');

  const currentFolder = result.currentRepo || activeRepo || window.location.hostname;
  const repos = result.data.slice();
  if (result.currentRepo && !repos.includes(result.currentRepo)) {
    repos.unshift(result.currentRepo);
  }

  select.innerHTML = '';
  repos.forEach(repo => {
    const opt = document.createElement('option');
    opt.value = repo;
    const isUninit = repo === result.currentRepo && !result.data.includes(repo);
    opt.textContent = isUninit ? repo + ' (non initialisé)' : repo;
    if (repo === currentFolder) opt.selected = true;
    select.appendChild(opt);
  });

  switcher.style.display = 'block';
}

function switchRepo(repoName) {
  if (!repoName) return;
  const url = new URL(window.location.href);
  url.searchParams.set('repo', repoName);
  window.location.href = url.toString();
}
