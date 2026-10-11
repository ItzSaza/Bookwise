(function () {
  'use strict';

  const STORAGE_KEY = 'bookwise_notifications_v1';
  const MAX_ITEMS = 30;
  let items = [];
  try { items = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); } catch (_) { items = []; }
  if (!Array.isArray(items)) items = [];
  let unread = items.filter(item => !item.read).length;
  let panel, badge, list, toastHost;

  function persist() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(items.slice(0, MAX_ITEMS))); } catch (_) {}
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
  }

  function render() {
    if (!badge || !list) return;
    badge.textContent = unread > 99 ? '99+' : String(unread);
    badge.hidden = unread === 0;
    list.innerHTML = items.length ? items.map(item => `
      <div class="bw-notification-item ${item.read ? '' : 'is-unread'} ${item.type === 'error' ? 'is-error' : 'is-success'}">
        <span class="bw-notification-icon">${item.type === 'error' ? '!' : '✓'}</span>
        <div class="bw-notification-copy">
          <strong>${esc(item.title)}</strong>
          <p>${esc(item.message)}</p>
          <time>${esc(item.time)}</time>
        </div>
        <button class="bw-notification-dismiss" type="button" data-remove-notification="${esc(item.id)}" aria-label="Dismiss notification">×</button>
      </div>`).join('') : '<div class="bw-notification-empty">No notifications yet. CRUD results will appear here.</div>';
  }

  function showToast(item) {
    if (!toastHost) return;
    const toast = document.createElement('div');
    toast.className = `bw-toast ${item.type === 'error' ? 'is-error' : 'is-success'}`;
    toast.innerHTML = `<span class="bw-toast-mark">${item.type === 'error' ? '!' : '✓'}</span><div><strong>${esc(item.title)}</strong><p>${esc(item.message)}</p></div><button type="button" aria-label="Dismiss">×</button>`;
    toast.querySelector('button').addEventListener('click', () => toast.remove());
    toastHost.appendChild(toast);
    window.setTimeout(() => toast.remove(), 5000);
  }

  function notify(type, title, message) {
    const item = {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      type, title, message: message || (type === 'error' ? 'The operation could not be completed.' : 'The operation completed successfully.'),
      time: new Date().toLocaleString(), read: false
    };
    items.unshift(item);
    items = items.slice(0, MAX_ITEMS);
    unread = items.filter(entry => !entry.read).length;
    persist();
    render();
    showToast(item);
    window.dispatchEvent(new CustomEvent('bookwise:notification', { detail: item }));
  }
  window.showAppNotification = notify;

  function operationName(method, url) {
    const m = method.toUpperCase();
    const path = (url.split('?')[0].split('/').pop() || '').replace('.php', '');
    const params = new URLSearchParams(url.split('?')[1] || '');
    const resource = params.get('resource') || path || 'record';
    const names = { products: 'product', suppliers: 'supplier', users: 'user', sales: 'sale', expenses: 'expense', employees: 'employee', services: 'service', purchase_orders: 'purchase order', categories: 'category' };
    const entity = names[resource] || resource.replace(/[_-]/g, ' ').replace(/s$/, '');
    const action = m === 'POST' ? 'created' : (m === 'DELETE' ? 'deleted' : (m === 'PATCH' || m === 'PUT' ? 'updated' : 'saved'));
    return `${entity.charAt(0).toUpperCase()}${entity.slice(1)} ${action}`;
  }

  function isApiMutation(input, init) {
    const method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    let url = typeof input === 'string' ? input : (input && input.url) || '';
    try { url = new URL(url, window.location.href).href; } catch (_) {}
    return { track: /api\/index\.php/i.test(url) && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method), method, url };
  }

  // Observe API write requests and report the actual HTTP/API result, not just button clicks.
  if (!window.__bookwiseNotificationFetchPatched && window.fetch) {
    window.__bookwiseNotificationFetchPatched = true;
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async function (input, init) {
      const meta = isApiMutation(input, init);
      try {
        const response = await nativeFetch(input, init);
        if (meta.track) {
          let payload = null;
          try { payload = await response.clone().json(); } catch (_) {}
          const apiSuccess = payload && Object.prototype.hasOwnProperty.call(payload, 'success') ? payload.success !== false : response.ok;
          const ok = response.ok && apiSuccess;
          const title = operationName(meta.method, meta.url);
          const message = payload && (payload.message || payload.error)
            ? String(payload.message || payload.error)
            : (ok ? `${title} successfully.` : `${title} failed (HTTP ${response.status}).`);
          notify(ok ? 'success' : 'error', ok ? `${title} — Success` : `${title} — Failed`, message);
        }
        return response;
      } catch (error) {
        if (meta.track) {
          const title = operationName(meta.method, meta.url);
          notify('error', `${title} — Failed`, error && error.message ? error.message : 'Could not connect to the server.');
        }
        throw error;
      }
    };
  }

  function buildUI() {
    if (document.getElementById('bw-notification-center')) return;
    const styles = document.createElement('style');
    styles.textContent = `
      #bw-notification-center{position:fixed;top:18px;right:22px;z-index:99999;font-family:Inter,Arial,sans-serif}
      .bw-notification-trigger{position:relative;display:flex;align-items:center;justify-content:center;gap:8px;width:44px;height:44px;border:1px solid #e4ddcb;border-radius:12px;background:#fff;color:#1b2a4a;box-shadow:0 5px 20px #12203a14;cursor:pointer;font-size:21px}
      .bw-notification-trigger:hover{background:#f7f3ea}
      .bw-notification-badge{position:absolute;top:-6px;right:-6px;min-width:19px;height:19px;padding:0 5px;border:2px solid #fff;border-radius:99px;background:#dc2626;color:#fff;font:700 10px/15px Inter,Arial,sans-serif;text-align:center}
      .bw-notification-badge[hidden]{display:none}
      .bw-notification-panel{position:absolute;right:0;top:54px;width:min(390px,calc(100vw - 28px));max-height:min(520px,calc(100vh - 95px));display:none;overflow:hidden;background:#fff;border:1px solid #e4ddcb;border-radius:14px;box-shadow:0 18px 55px #12203a2b}
      .bw-notification-panel.is-open{display:block}
      .bw-notification-head{display:flex;align-items:center;justify-content:space-between;padding:15px 16px;border-bottom:1px solid #eee8dc}
      .bw-notification-head strong{font-size:14px;color:#1b2a4a}
      .bw-notification-head button{border:0;background:transparent;color:#7a2e2e;cursor:pointer;font:600 11px Inter,Arial,sans-serif}
      .bw-notification-list{max-height:430px;overflow:auto}
      .bw-notification-item{display:flex;gap:10px;align-items:flex-start;padding:13px 12px;border-bottom:1px solid #f0ece4}
      .bw-notification-item.is-unread{background:#fbf9f4}
      .bw-notification-icon{display:flex;align-items:center;justify-content:center;flex:0 0 25px;height:25px;border-radius:50%;font-weight:800;background:#e7f5e9;color:#24723b}
      .bw-notification-item.is-error .bw-notification-icon{background:#fee2e2;color:#b91c1c}
      .bw-notification-copy{min-width:0;flex:1}
      .bw-notification-copy strong{display:block;font-size:12px;color:#1b2a4a;margin:1px 0 4px}
      .bw-notification-copy p{margin:0;color:#625f59;font-size:12px;line-height:1.45;overflow-wrap:anywhere}
      .bw-notification-copy time{display:block;margin-top:5px;color:#999184;font-size:10px}
      .bw-notification-dismiss{border:0;background:transparent;color:#9b9488;cursor:pointer;font-size:19px;line-height:1}
      .bw-notification-empty{padding:25px 18px;text-align:center;color:#8a8377;font-size:12px}
      #bw-notification-toasts{position:fixed;top:74px;right:22px;z-index:100000;display:flex;flex-direction:column;gap:9px;width:min(360px,calc(100vw - 28px));pointer-events:none}
      .bw-toast{display:flex;gap:10px;align-items:flex-start;padding:13px 12px;background:#fff;border:1px solid #d9e9dc;border-left:4px solid #27834a;border-radius:10px;box-shadow:0 10px 30px #12203a24;pointer-events:auto;animation:bwToastIn .18s ease-out}
      .bw-toast.is-error{border-color:#f4d1d1;border-left-color:#dc2626}
      .bw-toast-mark{display:flex;align-items:center;justify-content:center;flex:0 0 22px;height:22px;border-radius:50%;background:#e7f5e9;color:#24723b;font-weight:800}
      .bw-toast.is-error .bw-toast-mark{background:#fee2e2;color:#b91c1c}
      .bw-toast div{flex:1;min-width:0}.bw-toast strong{display:block;color:#1b2a4a;font-size:12px}.bw-toast p{margin:4px 0 0;color:#625f59;font-size:12px;line-height:1.4;overflow-wrap:anywhere}
      .bw-toast button{border:0;background:transparent;color:#8a8377;cursor:pointer;font-size:18px}
      @keyframes bwToastIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
      @media(max-width:600px){#bw-notification-center{top:12px;right:12px}.bw-notification-trigger{width:40px;height:40px}#bw-notification-toasts{top:62px;right:12px}}
    `;
    document.head.appendChild(styles);
    const center = document.createElement('div');
    center.id = 'bw-notification-center';
    center.innerHTML = `
      <button class="bw-notification-trigger" type="button" aria-label="Open notifications" aria-expanded="false" title="Notifications">
        <svg width="21" height="21" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="bw-notification-badge" hidden>0</span>
      </button>
      <section class="bw-notification-panel" aria-label="Notifications">
        <div class="bw-notification-head"><strong>Notifications</strong><button type="button" data-mark-all>Mark all as read</button></div>
        <div class="bw-notification-list"></div>
      </section>`;
    document.body.appendChild(center);
    toastHost = document.createElement('div');
    toastHost.id = 'bw-notification-toasts';
    document.body.appendChild(toastHost);
    panel = center.querySelector('.bw-notification-panel');
    badge = center.querySelector('.bw-notification-badge');
    list = center.querySelector('.bw-notification-list');
    const trigger = center.querySelector('.bw-notification-trigger');
    trigger.addEventListener('click', () => {
      const open = panel.classList.toggle('is-open');
      trigger.setAttribute('aria-expanded', String(open));
      if (open) {
        items = items.map(item => ({ ...item, read: true }));
        unread = 0; persist(); render();
      }
    });
    center.querySelector('[data-mark-all]').addEventListener('click', () => {
      items = items.map(item => ({ ...item, read: true }));
      unread = 0; persist(); render();
    });
    list.addEventListener('click', event => {
      const btn = event.target.closest('[data-remove-notification]');
      if (!btn) return;
      items = items.filter(item => item.id !== btn.dataset.removeNotification);
      unread = items.filter(item => !item.read).length;
      persist(); render();
    });
    document.addEventListener('click', event => {
      if (!center.contains(event.target)) {
        panel.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
      }
    });
    render();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildUI);
  else buildUI();
})();