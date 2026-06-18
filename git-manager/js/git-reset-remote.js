const API_URL = 'git-api.php';

let remoteBranches = [];

document.addEventListener('DOMContentLoaded', function() {
  if (localStorage.getItem('gitManagerDarkMode') === 'true') {
    document.body.classList.add('dark-mode');
    updateDarkModeIcon();
  }

  // Activer le bouton seulement si toutes les cases sont cochées
  const checkboxes = document.querySelectorAll('.checkbox-group input[type="checkbox"]');
  checkboxes.forEach(cb => {
    cb.addEventListener('change', updateButtonState);
  });

  // Charger les branches distantes
  loadRemoteBranches();
});

async function loadRemoteBranches() {
  const box = document.getElementById('remoteBranchesBox');
  const list = document.getElementById('remoteBranchesList');

  const result = await apiCall('listRemoteBranches');

  if (result.success && result.data.branches.length > 0) {
    remoteBranches = result.data.branches;
    box.style.display = 'block';

    let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';

    // Boutons sélectionner tout / aucun
    if (remoteBranches.length > 1) {
      html += `<div style="display: flex; gap: 10px; margin-bottom: 5px;">
        <button type="button" onclick="selectAllBranches(true)" class="btn btn-secondary" style="padding: 5px 10px; font-size: 0.8em;">
          <i class="fas fa-check-square"></i> Tout sélectionner
        </button>
        <button type="button" onclick="selectAllBranches(false)" class="btn btn-secondary" style="padding: 5px 10px; font-size: 0.8em;">
          <i class="fas fa-square"></i> Tout désélectionner
        </button>
      </div>`;
    }

    html += '<div style="display: flex; flex-wrap: wrap; gap: 8px;">';
    remoteBranches.forEach(branch => {
      html += `<label style="background: var(--bg-color); padding: 8px 12px; border-radius: 8px; font-size: 0.9em; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; border: 2px solid var(--border-color); transition: all 0.2s;">
        <input type="checkbox" class="branch-checkbox" value="${branch}" checked onchange="updateBranchSelection(this)" style="width: 16px; height: 16px;">
        <i class="fas fa-code-branch" style="color: var(--accent-color);"></i> ${branch}
      </label>`;
    });
    html += '</div>';

    if (remoteBranches.length > 1) {
      html += `<p style="margin-top: 10px; color: var(--text-secondary); font-size: 0.9em;">
        <i class="fas fa-info-circle"></i> ${remoteBranches.length} branches trouvées.
        <span id="selectedCount">Cochez les branches à <strong>supprimer</strong>.</span>
      </p>`;
    }

    html += '</div>';
    list.innerHTML = html;
  } else {
    // Pas de branches ou erreur
    list.innerHTML = '<span style="color: var(--text-secondary);">Aucune branche distante trouvée</span>';
    box.style.display = 'block';
  }
}

function selectAllBranches(select) {
  const checkboxes = document.querySelectorAll('.branch-checkbox');
  checkboxes.forEach(cb => {
    cb.checked = select;
    updateBranchSelection(cb);
  });
}

function updateBranchSelection(checkbox) {
  const label = checkbox.parentElement;
  if (checkbox.checked) {
    label.style.borderColor = 'var(--danger-color)';
    label.style.background = 'rgba(244, 67, 54, 0.1)';
  } else {
    label.style.borderColor = 'var(--accent-color)';
    label.style.background = 'rgba(69, 183, 175, 0.1)';
  }
  updateSelectedCount();
}

function updateSelectedCount() {
  const checkboxes = document.querySelectorAll('.branch-checkbox');
  const checked = document.querySelectorAll('.branch-checkbox:checked');
  const countEl = document.getElementById('selectedCount');
  if (countEl) {
    const toDelete = checked.length;
    const toKeep = checkboxes.length - toDelete;
    countEl.innerHTML = `<strong style="color: var(--danger-color);">${toDelete}</strong> à supprimer, <strong style="color: var(--accent-color);">${toKeep}</strong> à conserver.`;
  }
}

function getSelectedBranchesToDelete() {
  const checkboxes = document.querySelectorAll('.branch-checkbox:checked');
  return Array.from(checkboxes).map(cb => cb.value);
}

function updateButtonState() {
  const confirmAll = document.getElementById('confirmAll').checked;
  const resetBtn = document.getElementById('resetBtn');
  resetBtn.disabled = !confirmAll;
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

function terminalLog(text, type = '') {
  const terminal = document.getElementById('terminalOutput');
  terminal.classList.add('visible');
  const line = document.createElement('div');
  line.className = type;
  line.textContent = text;
  terminal.appendChild(line);
  terminal.scrollTop = terminal.scrollHeight;
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

async function resetRemote() {
  const branchName = document.getElementById('branchName').value.trim() || 'clean';
  const selectedBranchesToDelete = getSelectedBranchesToDelete();

  // Filtrer pour ne pas supprimer la nouvelle branche
  const branchesToDelete = selectedBranchesToDelete.filter(b => b !== branchName);

  let confirmMsg = `⚠️ DERNIÈRE CONFIRMATION ⚠️\n\n` +
    `Vous êtes sur le point de :\n` +
    `• Créer une branche "${branchName}" vide (sans historique)\n`;

  if (branchesToDelete.length > 0) {
    confirmMsg += `• Supprimer ${branchesToDelete.length} branche(s): ${branchesToDelete.join(', ')}\n`;
  } else {
    confirmMsg += `• Aucune branche ne sera supprimée\n`;
  }

  confirmMsg += `\nCette action est IRRÉVERSIBLE !\n\nVoulez-vous vraiment continuer ?`;

  const finalConfirm = confirm(confirmMsg);
  if (!finalConfirm) return;

  const btn = document.getElementById('resetBtn');
  const loading = document.getElementById('loadingIndicator');
  const terminal = document.getElementById('terminalOutput');

  btn.disabled = true;
  loading.classList.add('visible');
  terminal.innerHTML = '';
  terminal.classList.remove('visible');

  terminalLog('Démarrage de la réinitialisation...', '');

  const params = { branchName };
  if (branchesToDelete.length > 0) {
    params.deleteOtherBranches = true;
    params.branchesToDelete = branchesToDelete;
  }

  const result = await apiCall('resetRemote', params);

  loading.classList.remove('visible');

  if (result.success) {
    showAlert('success', 'Dépôt distant réinitialisé avec succès !');
    terminalLog(result.output, 'success');
    terminalLog('\n✅ Opération terminée. Allez sur GitHub pour finaliser.', 'success');
  } else {
    showAlert('error', 'Erreur : ' + result.error);
    terminalLog(result.error, 'error');
    updateButtonState();
  }
}
