(function () {
  const path = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
  if (path === 'login.html' || path === 'index.html') return;
  const api = 'api/index.php';
  async function initAuth() {
    try {
      const res = await fetch(api + '?resource=auth', { credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.success || !data.user) {
        location.replace('login.html');
        return;
      }
      const user = data.user;
      document.querySelectorAll('.sidebar-foot, .sidebar-footer').forEach(foot => {
        foot.innerHTML = `<div class="who" style="display:flex;align-items:center;gap:10px"><div class="avatar">${escapeHtml(initials(user.full_name || user.username))}</div><div style="min-width:0;flex:1"><strong class="current-user-name">${escapeHtml(user.username)}</strong><br><span class="role-label" style="color:#8d9ab8">${escapeHtml(user.role)}</span></div><button type="button" class="btn btn-ghost logout-btn" style="padding:7px 9px;font-size:11px;color:white" aria-label="Log out">Logout</button></div>`;
      });
      document.querySelectorAll('.logout-btn').forEach(btn => btn.addEventListener('click', async () => {
        btn.disabled = true;
        try { await fetch(api + '?resource=logout', { method: 'POST', credentials: 'same-origin' }); }
        finally { location.replace('login.html'); }
      }));
    } catch (_) { location.replace('login.html'); }
  }
  function initials(s) { return String(s).trim().split(/\s+/).slice(0,2).map(x=>x[0]||'').join('').toUpperCase() || 'U'; }
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  initAuth();
})();
