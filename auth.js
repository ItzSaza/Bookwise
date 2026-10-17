(function () {
  const path = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
  if (path === 'login.html' || path === 'index.html') return;
  const api = 'api/index.php';

  function initials(s) {
    return String(s).trim().split(/\s+/).slice(0, 2).map(x => x[0] || '').join('').toUpperCase() || 'U';
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function applySidebarNavigationFilter(role) {
    const navLinks = document.querySelectorAll('.sidebar .nav a, aside ul.nav a, aside nav a, .sidebar a');
    navLinks.forEach(link => {
      const href = (link.getAttribute('href') || '').split('/').pop().toLowerCase();
      if (!href.endsWith('.html')) return;
      const container = link.closest('li') || link;

      if (role === 'Cashier') {
        if (href !== 'orders-pos.html') {
          container.style.display = 'none';
        } else {
          container.style.display = '';
        }
      } else if (role === 'Staff') {
        if (href === 'user-management.html' || href === 'services-staff.html') {
          container.style.display = 'none';
        } else {
          container.style.display = '';
        }
      } else {
        // Admin
        container.style.display = '';
      }
    });
  }

  async function initAuth() {
    try {
      const res = await fetch(api + '?resource=auth', { credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json();
      if (!res.ok || !data.success || !data.user) {
        location.replace('login.html');
        return;
      }
      const user = data.user;
      window.currentUser = user;
      try { sessionStorage.setItem('bookwise_user', JSON.stringify(user)); } catch (_) {}
      try { localStorage.setItem('bookwise_user', JSON.stringify(user)); } catch (_) {}

      // Client-side role-based page gating
      // 1. Cashier is restricted to orders-pos.html and sales-report.html
      // 2. Staff cannot access user-management or services-staff (and their reports)
      if (user.role === 'Cashier') {
        const cashierAllowed = ['orders-pos.html', 'sales-report.html'];
        if (!cashierAllowed.includes(path)) {
          location.replace('orders-pos.html');
          return;
        }
      } else if (user.role === 'Staff') {
        const staffForbidden = ['user-management.html', 'users-report.html', 'services-staff.html', 'staff-report.html'];
        if (staffForbidden.includes(path)) {
          location.replace('inventory.html');
          return;
        }
      }

      // Update sidebar footers across pages
      document.querySelectorAll('.sidebar-foot, .sidebar-footer').forEach(foot => {
        foot.innerHTML = `
          <div class="who" style="display:flex;align-items:center;gap:10px">
            <div class="avatar">${escapeHtml(initials(user.full_name || user.username))}</div>
            <div style="min-width:0;flex:1">
              <strong class="current-user-name" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escapeHtml(user.full_name || user.username)}</strong>
              <span class="role-label" style="color:#8d9ab8;font-size:11px">${escapeHtml(user.role)}</span>
            </div>
            <button type="button" class="btn btn-ghost logout-btn" style="padding:7px 9px;font-size:11px;color:white" aria-label="Log out">Logout</button>
          </div>`;
      });

      // Role-based sidebar menu filtering
      applySidebarNavigationFilter(user.role);

      document.querySelectorAll('.logout-btn').forEach(btn => btn.addEventListener('click', async () => {
        btn.disabled = true;
        try { await fetch(api + '?resource=logout', { method: 'POST', credentials: 'same-origin' }); }
        finally {
          try { sessionStorage.removeItem('bookwise_user'); } catch (_) {}
          try { localStorage.removeItem('bookwise_user'); } catch (_) {}
          location.replace('login.html');
        }
      }));

      // Notify other scripts that user auth is ready
      window.dispatchEvent(new CustomEvent('bookwise:user-ready', { detail: user }));
    } catch (_) {
      location.replace('login.html');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAuth);
  } else {
    initAuth();
  }
})();
