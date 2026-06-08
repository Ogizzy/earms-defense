// ORION-PAY Global JavaScript

// ── Modal helpers ────────────────────────────────────────────
function openModal(id) { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

// Close modal on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});
// Close on ESC
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
});

// ── Alert toast ──────────────────────────────────────────────
function showAlert(msg, type = 'info') {
  const icons = { success: 'check_circle', error: 'error', info: 'info', warn: 'warning' };
  const el = document.createElement('div');
  el.className = 'alert alert-' + type;
  el.style.cssText = 'position:fixed;top:80px;right:18px;z-index:9999;min-width:280px;max-width:400px;box-shadow:var(--sh-lg);animation:modalIn .25s cubic-bezier(.34,1.56,.64,1)';
  el.innerHTML = `<span class="material-symbols-outlined">${icons[type] || 'info'}</span>${msg}`;
  document.body.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .5s'; setTimeout(() => el.remove(), 500); }, 5000);
}

// ── Number formatters ────────────────────────────────────────
function fmt(n, d = 0) { return Number(n).toLocaleString('en-ZM', { minimumFractionDigits: d, maximumFractionDigits: d }); }
function fmtK(n) { return 'K ' + fmt(n, 2); }

// ── Confirm delete / action ──────────────────────────────────
function confirmAction(url, label, method = 'POST') {
  if (!confirm(`${label}\n\nThis cannot be undone.`)) return;
  const form = document.createElement('form');
  form.method = 'POST'; form.action = url;
  const inp = document.createElement('input'); inp.type = 'hidden'; inp.name = '_method'; inp.value = method;
  form.appendChild(inp); document.body.appendChild(form); form.submit();
}

// ── Global search ────────────────────────────────────────────
const gs = document.getElementById('globalSearch');
if (gs) {
  gs.addEventListener('keydown', e => {
    if (e.key === 'Enter' && gs.value.trim()) {
      const base = window.BASE_URL || '';
      // Route to appropriate search based on role
      location.href = base + '/pages/superadmin/collections.php?q=' + encodeURIComponent(gs.value.trim());
    }
  });
}

// ── Auto-dismiss alerts ──────────────────────────────────────
document.querySelectorAll('.alert').forEach(el => {
  if (!el.closest('.modal')) {
    setTimeout(() => { el.style.transition = 'opacity .5s,margin .5s,padding .5s,height .5s'; el.style.opacity = '0'; el.style.marginBottom = '0'; el.style.padding = '0'; setTimeout(() => el.remove(), 600); }, 6000);
  }
});

// ── Copy to clipboard ────────────────────────────────────────
function copyText(text, btn) {
  navigator.clipboard.writeText(text).then(() => {
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="material-symbols-outlined">check</span>Copied!';
    btn.style.color = 'var(--green-dark)';
    setTimeout(() => { btn.innerHTML = orig; btn.style.color = ''; }, 2000);
  });
}

// ── Toggle password visibility ───────────────────────────────
function togglePw(inputId, iconId) {
  const i = document.getElementById(inputId), e = document.getElementById(iconId);
  if (!i) return;
  if (i.type === 'password') { i.type = 'text'; if (e) e.textContent = 'visibility_off'; }
  else { i.type = 'password'; if (e) e.textContent = 'visibility'; }
}

// ── Real-time clock in topbar ────────────────────────────────
const clockEl = document.querySelector('.topbar-row2 [data-clock]');
if (clockEl) {
  setInterval(() => {
    const now = new Date();
    clockEl.textContent = now.toLocaleTimeString('en-ZM', { hour: '2-digit', minute: '2-digit' }) + ' CAT';
  }, 60000);
}

// ── Chart.js global defaults ─────────────────────────────────
if (typeof Chart !== 'undefined') {
  Chart.defaults.color = '#6b7280';
  Chart.defaults.borderColor = 'rgba(0,0,0,0.05)';
  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.font.size = 11;
}

// ── Responsive sidebar toggle ────────────────────────────────
const menuBtn = document.querySelector('.tb-menu');
const sidebar = document.querySelector('.sidebar');
if (menuBtn && sidebar) {
  menuBtn.addEventListener('click', () => sidebar.classList.toggle('open'));
}
