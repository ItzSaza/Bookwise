(function () {
  'use strict';

  const STORAGE_KEY = 'bookwise_notifications_v1';
  const MAX_ITEMS = 30;
  let items = [];
  try { items = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); } catch (_) { items = []; }
  if (!Array.isArray(items)) items = [];

  // Upgrade legacy notifications to ensure actor attribution is present
  items = items.map(it => {
    if (!it || typeof it !== 'object') return it;
    let msg = String(it.message || '').trim();
    const actorName = (it.actor || '').replace(/\s*\([^)]*\)$/, '').trim() || 'User';
    if (msg && !/\bby\s+/i.test(msg)) {
      if (/successfully\.?$/i.test(msg)) {
        const actionPart = msg.replace(/\s*successfully\.?$/i, '').trim();
        it.message = `The ${actionPart.toLowerCase()} was completed by ${actorName}.`;
      } else {
        it.message = `${msg.replace(/\.+$/, '')} by ${actorName}.`;
      }
    }
    return it;
  });

  let panel, badge, list, toastHost;

  function persist() {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(items.slice(0, MAX_ITEMS))); } catch (_) {}
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[ch]));
  }

  function getCurrentUser() {
    if (window.currentUser) return window.currentUser;
    try {
      const raw = sessionStorage.getItem('bookwise_user') || localStorage.getItem('bookwise_user');
      if (raw) return JSON.parse(raw);
    } catch (_) {}
    return null;
  }

  function getCurrentUserName() {
    const user = getCurrentUser();
    if (!user) return 'User';
    return user.full_name || user.username || 'User';
  }

  function getCurrentActor() {
    const user = getCurrentUser();
    if (!user) return 'System';
    const name = user.full_name || user.username || 'User';
    const role = user.role ? ` (${user.role})` : '';
    return `${name}${role}`;
  }

  function getResourceScope(resource) {
    const res = String(resource || '').toLowerCase();
    // Sensitive admin operations: users, employees, services
    if (['users', 'employees', 'services', 'user', 'employee', 'service'].includes(res)) {
      return 'admin';
    }
    // Sales / POS operations: accessible to all (Cashier, Staff, Admin)
    if (['sales', 'orders', 'order', 'pos', 'sale'].includes(res)) {
      return 'all';
    }
    // Operational changes: products, suppliers, purchase_orders, expenses, categories
    return 'staff';
  }

  function isVisibleToCurrentRole(scope) {
    const user = getCurrentUser();
    const role = user ? user.role : 'Admin';
    if (role === 'Admin') return true;
    if (role === 'Staff') return scope !== 'admin';
    if (role === 'Cashier') return scope === 'all';
    return true;
  }

  function render() {
    if (!badge || !list) return;
    const visibleItems = items.filter(item => isVisibleToCurrentRole(item.scope));
    const unread = visibleItems.filter(item => !item.read).length;

    badge.textContent = unread > 99 ? '99+' : String(unread);
    badge.hidden = unread === 0;

    list.innerHTML = visibleItems.length ? visibleItems.map(item => `
      <div class="bw-notification-item ${item.read ? '' : 'is-unread'} ${item.type === 'error' ? 'is-error' : 'is-success'}">
        <span class="bw-notification-icon">${item.type === 'error' ? '!' : '✓'}</span>
        <div class="bw-notification-copy">
          <div class="bw-notification-title-row">
            <strong>${esc(item.title)}</strong>
            ${item.scope === 'admin' ? '<span class="bw-scope-badge admin">Admin Only</span>' : ''}
          </div>
          <p class="bw-notification-msg">${esc(item.message)}</p>
          <div class="bw-notification-meta">
            <span class="bw-notification-actor">By ${esc(item.actor || 'System')}</span>
            <time>${esc(item.time)}</time>
          </div>
        </div>
        <button class="bw-notification-dismiss" type="button" data-remove-notification="${esc(item.id)}" aria-label="Dismiss notification">×</button>
      </div>`).join('') : '<div class="bw-notification-empty">No notifications in your view. Activity logs will appear here.</div>';
  }

  function showToast(item) {
    if (!toastHost || !isVisibleToCurrentRole(item.scope)) return;
    const toast = document.createElement('div');
    toast.className = `bw-toast ${item.type === 'error' ? 'is-error' : 'is-success'}`;
    toast.innerHTML = `
      <span class="bw-toast-mark">${item.type === 'error' ? '!' : '✓'}</span>
      <div class="bw-toast-copy">
        <strong>${esc(item.title)}</strong>
        <p class="bw-toast-msg">${esc(item.message)}</p>
        <span class="bw-toast-actor">By ${esc(item.actor || 'System')}</span>
      </div>
      <button type="button" aria-label="Dismiss">×</button>`;
    toast.querySelector('button').addEventListener('click', () => toast.remove());
    toastHost.appendChild(toast);
    window.setTimeout(() => toast.remove(), 5500);
  }

  let lastNotificationTime = 0;
  let lastNotificationSig = '';

  function notify(type, title, message, scope, actor) {
    const resolvedActor = actor || getCurrentActor();
    const resolvedUserName = getCurrentUserName();
    const resolvedScope = scope || 'all';

    let finalMessage = String(message || '').trim();
    if (!finalMessage) {
      finalMessage = type === 'error'
        ? `The operation could not be completed by ${resolvedUserName}.`
        : `The operation was completed successfully by ${resolvedUserName}.`;
    } else if (!/\bby\s+[A-Za-z0-9]/i.test(finalMessage)) {
      finalMessage = `${finalMessage.replace(/\.+$/, '')} by ${resolvedUserName}.`;
    }

    // Deduplication check: ignore duplicate within 1200ms
    const sig = `${type}|${title}|${finalMessage}|${resolvedScope}`;
    const now = Date.now();
    if (sig === lastNotificationSig && (now - lastNotificationTime) < 1200) {
      return;
    }
    lastNotificationTime = now;
    lastNotificationSig = sig;

    const item = {
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      type,
      title,
      message: finalMessage,
      time: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' · ' + new Date().toLocaleDateString(),
      read: false,
      scope: resolvedScope,
      actor: resolvedActor
    };

    items.unshift(item);
    items = items.slice(0, MAX_ITEMS);
    persist();
    render();
    showToast(item);
    window.dispatchEvent(new CustomEvent('bookwise:notification', { detail: item }));
  }
  window.showAppNotification = notify;

  window.bookwiseConfirm = function (message, options) {
    options = options || {};
    return new Promise(function (resolve) {
      const title = options.title || 'Confirm Deletion';
      const confirmText = options.confirmText || 'Delete';
      const cancelText = options.cancelText || 'Cancel';
      const isDanger = options.danger !== false;

      const existing = document.getElementById('bw-confirm-modal');
      if (existing) existing.remove();

      const modal = document.createElement('div');
      modal.id = 'bw-confirm-modal';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.innerHTML = `
        <div class="bw-confirm-backdrop"></div>
        <div class="bw-confirm-card">
          <div class="bw-confirm-header">
            <div class="bw-confirm-icon ${isDanger ? 'danger' : ''}">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
              </svg>
            </div>
            <div class="bw-confirm-title">${esc(title)}</div>
          </div>
          <div class="bw-confirm-body">
            <p>${esc(message)}</p>
            ${isDanger ? '<p class="bw-confirm-sub">This action cannot be undone.</p>' : ''}
          </div>
          <div class="bw-confirm-actions">
            <button type="button" class="bw-confirm-btn bw-confirm-cancel" id="bw-confirm-cancel-btn">${esc(cancelText)}</button>
            <button type="button" class="bw-confirm-btn bw-confirm-ok ${isDanger ? 'danger' : 'primary'}" id="bw-confirm-ok-btn">${esc(confirmText)}</button>
          </div>
        </div>
      `;

      document.body.appendChild(modal);

      const cancelBtn = modal.querySelector('#bw-confirm-cancel-btn');
      const okBtn = modal.querySelector('#bw-confirm-ok-btn');
      const backdrop = modal.querySelector('.bw-confirm-backdrop');

      function cleanup(val) {
        document.removeEventListener('keydown', onKey);
        modal.classList.add('bw-confirm-closing');
        setTimeout(() => modal.remove(), 160);
        resolve(val);
      }

      function onKey(e) {
        if (e.key === 'Escape') {
          e.preventDefault();
          cleanup(false);
        } else if (e.key === 'Enter' && document.activeElement !== cancelBtn) {
          e.preventDefault();
          cleanup(true);
        }
      }

      cancelBtn.addEventListener('click', () => cleanup(false));
      backdrop.addEventListener('click', () => cleanup(false));
      okBtn.addEventListener('click', () => cleanup(true));
      document.addEventListener('keydown', onKey);
      setTimeout(() => okBtn.focus(), 40);
    });
  };
  window.confirmModal = window.bookwiseConfirm;

  const RESOURCE_ENTITY_MAP = {
    products: 'product',
    product: 'product',
    suppliers: 'supplier',
    supplier: 'supplier',
    users: 'user',
    user: 'user',
    sales: 'order',
    sale: 'order',
    orders: 'order',
    order: 'order',
    expenses: 'expense',
    expense: 'expense',
    employees: 'employee',
    employee: 'employee',
    services: 'service',
    service: 'service',
    purchase_orders: 'purchase order',
    purchase_order: 'purchase order',
    categories: 'category',
    category: 'category'
  };

  function getResourceEntity(resource) {
    const res = String(resource || '').toLowerCase().trim();
    return RESOURCE_ENTITY_MAP[res] || (res ? res.replace(/[_-]/g, ' ').replace(/s$/, '') : 'item');
  }

  function getActionInfo(method) {
    const m = String(method || '').toUpperCase();
    if (m === 'POST') return { verb: 'created', base: 'create', noun: 'Created' };
    if (m === 'DELETE') return { verb: 'deleted', base: 'delete', noun: 'Deleted' };
    if (m === 'PUT' || m === 'PATCH') return { verb: 'updated', base: 'update', noun: 'Updated' };
    return { verb: 'saved', base: 'save', noun: 'Saved' };
  }

  function formatTitle(method, resource, ok) {
    const entity = getResourceEntity(resource);
    const capEntity = entity.charAt(0).toUpperCase() + entity.slice(1);
    const action = getActionInfo(method);
    if (ok) {
      return `${capEntity} ${action.noun}`;
    }
    return `${capEntity} ${action.noun} Failed`;
  }

  function extractItemName(meta, payload, init) {
    // 1. Check API payload response
    if (payload && typeof payload === 'object') {
      const pName = payload.item_name || payload.name || payload.title || payload.order_ref || payload.po_ref || payload.expense_ref;
      if (pName && typeof pName === 'string' && pName.trim()) {
        return pName.trim();
      }
      if (payload.data && typeof payload.data === 'object') {
        const dName = payload.data.item_name || payload.data.name || payload.data.title || payload.data.order_ref || payload.data.po_ref;
        if (dName && typeof dName === 'string' && dName.trim()) {
          return dName.trim();
        }
      }
    }

    // 2. Check query string in URL
    try {
      const params = new URLSearchParams((meta.url || '').split('?')[1] || '');
      const qName = params.get('name') || params.get('item_name') || params.get('title') || params.get('ref');
      if (qName && qName.trim()) {
        return qName.trim();
      }
    } catch (_) {}

    // 3. Check request body
    if (init && init.body) {
      try {
        const bodyObj = typeof init.body === 'string' ? JSON.parse(init.body) : init.body;
        if (bodyObj && typeof bodyObj === 'object') {
          const bName = bodyObj.name || bodyObj.item_name || bodyObj.title || bodyObj.full_name || bodyObj.order_ref || bodyObj.po_ref || bodyObj.description;
          if (bName && typeof bName === 'string' && bName.trim()) {
            return bName.trim();
          }
          if (Array.isArray(bodyObj.items) && bodyObj.items.length > 0) {
            const first = bodyObj.items[0];
            const iName = first.name || first.product_name;
            if (iName && typeof iName === 'string' && iName.trim()) {
              return bodyObj.items.length > 1 ? `${iName.trim()} (+${bodyObj.items.length - 1} items)` : iName.trim();
            }
          }
        }
      } catch (_) {}
    }

    return null;
  }

  function formatActionMessage(method, resource, itemName, userName, ok, errorDetail) {
    const entity = getResourceEntity(resource);
    const action = getActionInfo(method);
    const namePart = itemName ? ` ${itemName}` : '';

    if (ok) {
      // Exactly matches: "The product red pen was deleted by (user name)"
      return `The ${entity}${namePart} was ${action.verb} by ${userName}.`;
    }

    const detail = errorDetail ? `: ${errorDetail}` : '.';
    return `The ${entity}${namePart} could not be ${action.verb} by ${userName}${detail}`;
  }

  function isApiMutation(input, init) {
    const method = String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
    let url = typeof input === 'string' ? input : (input && input.url) || '';
    try { url = new URL(url, window.location.href).href; } catch (_) {}
    return { track: /api\/index\.php/i.test(url) && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method), method, url };
  }

  // Observe API write requests and report the actual HTTP/API result with actor, action, item, and sensitivity scope.
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

          const params = new URLSearchParams((meta.url || '').split('?')[1] || '');
          const resource = params.get('resource') || '';
          const scope = getResourceScope(resource);
          const actor = getCurrentActor();
          const userName = getCurrentUserName();
          const itemName = extractItemName(meta, payload, init);

          const errorDetail = !ok ? (payload && (payload.message || payload.error) ? String(payload.message || payload.error) : `HTTP ${response.status}`) : '';
          const title = formatTitle(meta.method, resource, ok);
          const message = formatActionMessage(meta.method, resource, itemName, userName, ok, errorDetail);

          notify(ok ? 'success' : 'error', title, message, scope, actor);
        }
        return response;
      } catch (error) {
        if (meta.track) {
          const params = new URLSearchParams((meta.url || '').split('?')[1] || '');
          const resource = params.get('resource') || '';
          const scope = getResourceScope(resource);
          const actor = getCurrentActor();
          const userName = getCurrentUserName();
          const itemName = extractItemName(meta, null, init);

          const title = formatTitle(meta.method, resource, false);
          const errText = error && error.message ? error.message : 'Could not connect to the server.';
          const message = formatActionMessage(meta.method, resource, itemName, userName, false, errText);

          notify('error', title, message, scope, actor);
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
      .bw-notification-trigger{position:relative;display:flex;align-items:center;justify-content:center;gap:8px;width:44px;height:44px;border:1px solid #e4ddcb;border-radius:12px;background:#fff;color:#1b2a4a;box-shadow:0 5px 20px #12203a14;cursor:pointer;font-size:21px;transition:background .15s}
      .bw-notification-trigger:hover{background:#f7f3ea}
      .bw-notification-badge{position:absolute;top:-6px;right:-6px;min-width:19px;height:19px;padding:0 5px;border:2px solid #fff;border-radius:99px;background:#dc2626;color:#fff;font:700 10px/15px Inter,Arial,sans-serif;text-align:center}
      .bw-notification-badge[hidden]{display:none}
      .bw-notification-panel{position:absolute;right:0;top:54px;width:min(420px,calc(100vw - 28px));max-height:min(540px,calc(100vh - 95px));display:none;overflow:hidden;background:#fff;border:1px solid #e4ddcb;border-radius:14px;box-shadow:0 18px 55px #12203a2b}
      .bw-notification-panel.is-open{display:block}
      .bw-notification-head{display:flex;align-items:center;justify-content:space-between;padding:15px 16px;border-bottom:1px solid #eee8dc;background:#fcfaf5}
      .bw-notification-head strong{font-size:14px;color:#1b2a4a}
      .bw-notification-head button{border:0;background:transparent;color:#7a2e2e;cursor:pointer;font:600 11px Inter,Arial,sans-serif}
      .bw-notification-head button:hover{text-decoration:underline}
      .bw-notification-list{max-height:440px;overflow-y:auto}
      .bw-notification-item{display:flex;gap:10px;align-items:flex-start;padding:13px 14px;border-bottom:1px solid #f0ece4;transition:background .15s}
      .bw-notification-item.is-unread{background:#fcfaf4}
      .bw-notification-icon{display:flex;align-items:center;justify-content:center;flex:0 0 25px;height:25px;border-radius:50%;font-weight:800;background:#e7f5e9;color:#24723b;margin-top:2px}
      .bw-notification-item.is-error .bw-notification-icon{background:#fee2e2;color:#b91c1c}
      .bw-notification-copy{min-width:0;flex:1}
      .bw-notification-title-row{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:3px}
      .bw-notification-copy strong{display:block;font-size:12.5px;color:#1b2a4a}
      .bw-scope-badge{font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:4px;text-transform:uppercase;letter-spacing:0.4px;white-space:nowrap}
      .bw-scope-badge.admin{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
      .bw-notification-copy p{margin:3px 0 0;color:#334155;font-size:12.5px;line-height:1.45;font-weight:500;overflow-wrap:anywhere}
      .bw-notification-meta{display:flex;align-items:center;justify-content:space-between;margin-top:6px;gap:8px;font-size:11px}
      .bw-notification-actor{color:#7a2e2e;font-weight:600;background:#fdf2f0;padding:2px 7px;border-radius:4px;border:1px solid #f5dedb;font-size:11px}
      .bw-notification-copy time{color:#999184;font-size:10.5px}
      .bw-notification-dismiss{border:0;background:transparent;color:#9b9488;cursor:pointer;font-size:19px;line-height:1;padding:0 2px}
      .bw-notification-dismiss:hover{color:#7a2e2e}
      .bw-notification-empty{padding:28px 18px;text-align:center;color:#8a8377;font-size:12px}
      #bw-notification-toasts{position:fixed;top:74px;right:22px;z-index:100000;display:flex;flex-direction:column;gap:9px;width:min(380px,calc(100vw - 28px));pointer-events:none}
      .bw-toast{display:flex;gap:10px;align-items:flex-start;padding:13px 14px;background:#fff;border:1px solid #d9e9dc;border-left:4px solid #27834a;border-radius:10px;box-shadow:0 10px 30px #12203a24;pointer-events:auto;animation:bwToastIn .18s ease-out}
      .bw-toast.is-error{border-color:#f4d1d1;border-left-color:#dc2626}
      .bw-toast-mark{display:flex;align-items:center;justify-content:center;flex:0 0 22px;height:22px;border-radius:50%;background:#e7f5e9;color:#24723b;font-weight:800;margin-top:1px}
      .bw-toast.is-error .bw-toast-mark{background:#fee2e2;color:#b91c1c}
      .bw-toast-copy{flex:1;min-width:0}
      .bw-toast-copy strong{display:block;color:#1b2a4a;font-size:12.5px}
      .bw-toast-copy p{margin:4px 0 0;color:#334155;font-size:12.5px;line-height:1.4;font-weight:500;overflow-wrap:anywhere}
      .bw-toast-actor{display:inline-block;margin-top:5px;color:#7a2e2e;font-size:11px;font-weight:600;background:#fdf2f0;padding:2px 7px;border-radius:4px;border:1px solid #f5dedb}
      .bw-toast button{border:0;background:transparent;color:#8a8377;cursor:pointer;font-size:18px;line-height:1}
      .bw-toast button:hover{color:#7a2e2e}
      @keyframes bwToastIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
      @media(max-width:600px){#bw-notification-center{top:12px;right:12px}.bw-notification-trigger{width:40px;height:40px}#bw-notification-toasts{top:62px;right:12px}}
      #bw-confirm-modal{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;padding:16px;animation:bwFadeIn .15s ease-out}
      #bw-confirm-modal.bw-confirm-closing{animation:bwFadeOut .15s ease-in forwards}
      .bw-confirm-backdrop{position:absolute;inset:0;background:rgba(18,32,58,0.65);backdrop-filter:blur(3px)}
      .bw-confirm-card{position:relative;z-index:1;background:#fff;border-radius:12px;box-shadow:0 24px 60px rgba(18,32,58,0.32),0 0 0 1px rgba(18,32,58,0.08);max-width:440px;width:100%;padding:24px;font-family:Inter,Arial,sans-serif;color:#12203a;animation:bwScaleIn .18s cubic-bezier(.16,1,.3,1)}
      .bw-confirm-header{display:flex;align-items:center;gap:12px;margin-bottom:12px}
      .bw-confirm-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(18,32,58,0.06);color:#1c355e;flex-shrink:0}
      .bw-confirm-icon.danger{background:#fdf2f0;color:#c93b2b}
      .bw-confirm-title{font-size:16px;font-weight:700;color:#12203a}
      .bw-confirm-body p{margin:0 0 6px;font-size:13.5px;line-height:1.5;color:#475569}
      .bw-confirm-sub{font-size:12px !important;color:#8a99ad !important}
      .bw-confirm-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:20px}
      .bw-confirm-btn{padding:8px 16px;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:all .15s ease;border:1px solid transparent;font-family:inherit}
      .bw-confirm-cancel{background:#f1f4f8;color:#12203a;border-color:#dbe2ea}
      .bw-confirm-cancel:hover{background:#e4e9f0}
      .bw-confirm-ok.danger{background:#c93b2b;color:#fff}
      .bw-confirm-ok.danger:hover{background:#a82e20;box-shadow:0 4px 12px rgba(201,59,43,0.28)}
      .bw-confirm-ok.primary{background:#1c355e;color:#fff}
      .bw-confirm-ok.primary:hover{background:#12203a}
      @keyframes bwScaleIn{from{opacity:0;transform:scale(.95) translateY(6px)}to{opacity:1;transform:scale(1) translateY(0)}}
      @keyframes bwFadeIn{from{opacity:0}to{opacity:1}}
      @keyframes bwFadeOut{from{opacity:1}to{opacity:0}}
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
        items = items.map(item => isVisibleToCurrentRole(item.scope) ? { ...item, read: true } : item);
        persist();
        render();
      }
    });

    center.querySelector('[data-mark-all]').addEventListener('click', () => {
      items = items.map(item => isVisibleToCurrentRole(item.scope) ? { ...item, read: true } : item);
      persist();
      render();
    });

    list.addEventListener('click', event => {
      const btn = event.target.closest('[data-remove-notification]');
      if (!btn) return;
      items = items.filter(item => item.id !== btn.dataset.removeNotification);
      persist();
      render();
    });

    document.addEventListener('click', event => {
      if (!center.contains(event.target)) {
        panel.classList.remove('is-open');
        trigger.setAttribute('aria-expanded', 'false');
      }
    });

    // Re-render when auth establishes the current user role
    window.addEventListener('bookwise:user-ready', () => {
      render();
    });

    render();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildUI);
  else buildUI();
})();