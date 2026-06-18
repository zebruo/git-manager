document.addEventListener('DOMContentLoaded', function() {
  if (localStorage.getItem('gitManagerDarkMode') === 'true') {
    document.body.classList.add('dark-mode');
    updateDarkModeIcon();
  }

  document.getElementById('sshEmail').addEventListener('input', function() {
    const email = this.value || 'votre-email@example.com';
    document.getElementById('sshGenCommand').innerHTML =
      `<button class="copy-btn" onclick="copyCode(this)"><i class="fas fa-copy"></i></button>
       ssh-keygen -t ed25519 -C "${email}"`;
  });
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
}

function showTab(tabName) {
  document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
  document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

  document.querySelector(`.tab[onclick="showTab('${tabName}')"]`).classList.add('active');
  document.getElementById(`${tabName}-tab`).classList.add('active');
}

function copyCode(button) {
  const codeBlock = button.parentElement;
  const code = codeBlock.textContent.replace(button.textContent, '').trim();

  navigator.clipboard.writeText(code).then(() => {
    button.classList.add('copied');
    button.innerHTML = '<i class="fas fa-check"></i>';

    setTimeout(() => {
      button.classList.remove('copied');
      button.innerHTML = '<i class="fas fa-copy"></i>';
    }, 2000);
  });
}
