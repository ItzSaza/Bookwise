// Global security and validation utility helpers
window.escapeHtml = window.escapeHtml || function(value) {
  return String(value ?? '').replace(/[&<>"']/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));
};

window.clearFieldErrors = function(container) {
  if (!container) return;
  container.querySelectorAll('.field-error').forEach(el => el.remove());
  container.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
  const banner = container.querySelector('.form-error-banner');
  if (banner) banner.remove();
};

window.showFieldErrors = function(container, errors, generalMessage) {
  if (!container) return;
  window.clearFieldErrors(container);

  if (generalMessage && (!errors || Object.keys(errors).length === 0)) {
    const banner = document.createElement('div');
    banner.className = 'form-error-banner';
    banner.textContent = generalMessage;
    container.prepend(banner);
    return;
  }

  if (typeof errors === 'object' && errors !== null) {
    let firstInput = null;
    for (const [key, msg] of Object.entries(errors)) {
      const camel = key.replace(/_([a-z])/g, (_, g) => g.toUpperCase());
      const input = container.querySelector(`#${key}, #${camel}, [name="${key}"], [data-field="${key}"]`);
      if (input) {
        input.classList.add('has-error');
        const errDiv = document.createElement('div');
        errDiv.className = 'field-error';
        errDiv.textContent = msg;
        const parentField = input.closest('.field') || input.parentElement;
        parentField.appendChild(errDiv);
        if (!firstInput) firstInput = input;
      }
    }
    if (firstInput) {
      firstInput.focus();
    }
  }
};

document.addEventListener('DOMContentLoaded', () => {
  // Highlight active link based on current page URL
  const currentPath = window.location.pathname.split('/').pop() || 'user-management.html';
  const navLinks = document.querySelectorAll('#nav a');
  
  navLinks.forEach(link => {
    const href = link.getAttribute('href');
    if (currentPath === href) {
      link.parentElement.classList.add('active');
    } else {
      link.parentElement.classList.remove('active');
    }
  });

  const inventoryView = document.getElementById('v2');
  const inventoryFilters = inventoryView ? inventoryView.querySelectorAll('.tag-row [data-filter]') : [];
  const inventoryTable = inventoryView ? inventoryView.querySelector('.panel table') : null;
  const inventoryCount = inventoryView ? inventoryView.querySelector('#inventoryCount') : null;
  const inventorySearch = inventoryView ? inventoryView.querySelector('#supplierSearch') : null;

  if (inventoryFilters.length && inventoryTable) {
    const inventoryRows = inventoryTable.querySelectorAll('tbody tr');
    let activeFilter = 'All Categories';

    function applyInventoryFilters() {
      const searchTerm = inventorySearch ? inventorySearch.value.trim().toLowerCase() : '';
      let visibleCount = 0;

      inventoryRows.forEach(row => {
        const category = row.cells[1].textContent.trim();
        const productName = row.cells[0].textContent.trim().toLowerCase();
        const matchesCategory = activeFilter === 'All Categories' || category === activeFilter;
        const matchesSearch = !searchTerm || productName.includes(searchTerm);
        const isVisible = matchesCategory && matchesSearch;

        row.style.display = isVisible ? '' : 'none';
        if (isVisible) visibleCount += 1;
      });

      if (inventoryCount) {
        inventoryCount.textContent = `${visibleCount} items · sorted by stock level`;
      }
    }

    inventoryFilters.forEach(filter => {
      filter.addEventListener('click', () => {
        activeFilter = filter.dataset.filter;
        inventoryFilters.forEach(item => item.classList.toggle('active', item === filter));
        applyInventoryFilters();
      });
    });

    if (inventorySearch) inventorySearch.addEventListener('input', applyInventoryFilters);
  }

  /* =========================================
     USER MANAGEMENT MODULE
     ========================================= */

  const userSearch = document.getElementById('userSearch');
  const roleFilter = document.getElementById('roleFilter');
  const statusFilter = document.getElementById('statusFilter');
  const usersTable = document.getElementById('usersTable');

  const addUserBtn = document.getElementById('addUserBtn');
  const userFormPanel = document.getElementById('userFormPanel');
  const cancelUserBtn = document.getElementById('cancelUserBtn');
  const saveUserBtn = document.getElementById('saveUserBtn');

  if (document.body.dataset.backendUsers === 'true') return;

  function filterUsers() {

    if (!usersTable) return;

    const rows = usersTable.querySelectorAll('tbody tr');

    const searchTerm = userSearch
      ? userSearch.value.trim().toLowerCase()
      : '';

    const selectedRole = roleFilter
      ? roleFilter.value
      : 'All';

    const selectedStatus = statusFilter
      ? statusFilter.value
      : 'All';

    rows.forEach(row => {

      const name = row.cells[0].textContent.toLowerCase();
      const role = row.cells[1].textContent.trim();
      const status = row.cells[3].textContent.trim();

      const matchesSearch =
        !searchTerm ||
        name.includes(searchTerm);

      const matchesRole =
        selectedRole === 'All' ||
        role === selectedRole;

      const matchesStatus =
        selectedStatus === 'All' ||
        status === selectedStatus;

      row.style.display =
        matchesSearch && matchesRole && matchesStatus
          ? ''
          : 'none';

    });
  }

  if (userSearch) {
    userSearch.addEventListener('input', filterUsers);
  }

  if (roleFilter) {
    roleFilter.addEventListener('change', filterUsers);
  }

  if (statusFilter) {
    statusFilter.addEventListener('change', filterUsers);
  }


  /* Show Add User Form */

  if (addUserBtn && userFormPanel) {

    addUserBtn.addEventListener('click', () => {

      userFormPanel.classList.add('show');

      userFormPanel.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });

    });

  }


  /* Hide Add User Form */

  if (cancelUserBtn && userFormPanel) {

    cancelUserBtn.addEventListener('click', () => {

      userFormPanel.classList.remove('show');

    });

  }


  /* Add New User */

  if (saveUserBtn && usersTable) {

    saveUserBtn.addEventListener('click', () => {

      const name =
        document.getElementById('newUserName').value.trim();

      const email =
        document.getElementById('newUserEmail').value.trim();

      const role =
        document.getElementById('newUserRole').value;

      const status =
        document.getElementById('newUserStatus').value;

      if (!name || !email) {

        alert('Please enter the user name and email.');

        return;

      }

      const tbody = usersTable.querySelector('tbody');

      const row = document.createElement('tr');

      let roleClass = 'gold';

      if (role === 'Admin') {
        roleClass = 'navy';
      }

      if (role === 'Cashier') {
        roleClass = 'red';
      }

      const statusClass =
        status === 'Active'
          ? 'green'
          : 'grey';

      const actionText =
        status === 'Active'
          ? 'Deactivate'
          : 'Activate';

      row.innerHTML = `
        <td class="cell-strong">${name}</td>

        <td>
          <span class="badge ${roleClass}">
            ${role}
          </span>
        </td>

        <td class="cell-mono">
          ${email}
        </td>

        <td>
          <span class="badge ${statusClass}">
            ${status}
          </span>
        </td>

        <td>
          <div class="row-actions">
            <button class="icon-btn edit-user">
              Edit
            </button>

            <button class="icon-btn toggle-user">
              ${actionText}
            </button>
          </div>
        </td>
      `;

      tbody.appendChild(row);

      document.getElementById('newUserName').value = '';
      document.getElementById('newUserEmail').value = '';

      userFormPanel.classList.remove('show');

      updateUserStats();

      alert('User added successfully.');

    });

  }


  /* Activate / Deactivate User */

  if (usersTable) {

    usersTable.addEventListener('click', (event) => {

      const button =
        event.target.closest('.toggle-user');

      if (!button) return;

      const row = button.closest('tr');

      const statusCell = row.cells[3];

      const badge = statusCell.querySelector('.badge');

      if (badge.classList.contains('green')) {

        badge.classList.remove('green');
        badge.classList.add('grey');

        badge.textContent = 'Inactive';

        button.textContent = 'Activate';

      } else {

        badge.classList.remove('grey');
        badge.classList.add('green');

        badge.textContent = 'Active';

        button.textContent = 'Deactivate';

      }

      updateUserStats();

    });

  }


  /* Edit User */

  if (usersTable) {

    usersTable.addEventListener('click', (event) => {

      const button =
        event.target.closest('.edit-user');

      if (!button) return;

      const row = button.closest('tr');

      const name = row.cells[0].textContent.trim();
      const email = row.cells[2].textContent.trim();

      alert(
        'Edit User\n\n' +
        'Name: ' + name + '\n' +
        'Email: ' + email +
        '\n\nEdit form can be connected to the database later.'
      );

    });

  }


  /* Update User Statistics */

  function updateUserStats() {

    if (!usersTable) return;

    const rows =
      usersTable.querySelectorAll('tbody tr');

    let active = 0;
    let inactive = 0;
    let admins = 0;
    let staff = 0;

    rows.forEach(row => {

      const role =
        row.cells[1].textContent.trim();

      const status =
        row.cells[3].textContent.trim();

      if (status === 'Active') {
        active++;
      } else {
        inactive++;
      }

      if (role === 'Admin') {
        admins++;
      }

      if (role === 'Staff') {
        staff++;
      }

    });

    const activeCount =
      document.getElementById('activeUserCount');

    const adminCount =
      document.getElementById('adminCount');

    const staffCount =
      document.getElementById('staffCount');

    const inactiveCount =
      document.getElementById('inactiveUserCount');

    if (activeCount) {
      activeCount.textContent = active;
    }

    if (adminCount) {
      adminCount.textContent = admins;
    }

    if (staffCount) {
      staffCount.textContent = staff;
    }

    if (inactiveCount) {
      inactiveCount.textContent = inactive;
    }

  }

  updateUserStats();

});

document.addEventListener("DOMContentLoaded", () => {
  const productGrid = document.getElementById("productGrid");
  const cartItems = document.getElementById("cartItems");

  // If this is another BOOKWISE page, do not run POS code.
  if (!productGrid || !cartItems) return;

  let products = [];
  let categories = [];
  let isProductsLoaded = false;
  let cart = [];
  let selectedCategory = "All";
  let selectedPayment = "Cash";
  let discountPercent = 0;
  let orders = [];

  function renderSalesStats(stats) {
    if (!stats) return;
    const salesVal = Number(stats.today_sales) || 0;
    const ordersVal = Number(stats.today_orders) || 0;
    const itemsVal = Number(stats.today_items) || 0;
    const avgVal = ordersVal > 0 ? (salesVal / ordersVal) : 0;

    if ($("todaySales")) $("todaySales").textContent = money(salesVal);
    if ($("todayOrders")) $("todayOrders").textContent = String(ordersVal);
    if ($("itemsSold")) $("itemsSold").textContent = String(itemsVal);
    if ($("averageOrder")) $("averageOrder").textContent = money(avgVal);
  }

  async function loadSavedOrders() {
    try {
      const response = await fetch("api/index.php?resource=sales");
      const result = await response.json();
      if (response.ok && result.success && Array.isArray(result.data)) {
        orders = result.data;
        renderOrders();
        if (result.stats) {
          renderSalesStats(result.stats);
        }
      }
    } catch (_) {}
  }

  async function loadDbCategories() {
    const categoryRow = document.getElementById("categoryRow");
    if (!categoryRow) return;
    try {
      const response = await fetch("api/index.php?resource=categories");
      const result = await response.json();
      if (response.ok && result.success && Array.isArray(result.data)) {
        categories = result.data;
        const counts = {};
        products.forEach(p => {
          counts[p.category] = (counts[p.category] || 0) + 1;
        });

        const allActive = selectedCategory === "All" ? "active" : "";
        let html = `<button class="pos-category ${allActive}" data-category="All">All (${products.length})</button>`;
        categories.forEach(c => {
          const count = counts[c.name] || 0;
          const active = selectedCategory === c.name ? "active" : "";
          html += `<button class="pos-category ${active}" data-category="${escapeHtml(c.name)}">${escapeHtml(c.name)} (${count})</button>`;
        });
        categoryRow.innerHTML = html;

        categoryRow.querySelectorAll(".pos-category").forEach(button => {
          button.addEventListener("click", () => {
            categoryRow.querySelectorAll(".pos-category").forEach(b => b.classList.remove("active"));
            button.classList.add("active");
            selectedCategory = button.dataset.category;
            renderProducts();
          });
        });
      }
    } catch (err) {
      console.error("Error loading categories:", err);
    }
  }

  async function loadDbProducts() {
    try {
      const response = await fetch("api/index.php?resource=products");
      const result = await response.json();
      if (response.ok && result.success && Array.isArray(result.data)) {
        const colors = ["", "sage", "red", "gold"];
        products = result.data.map((p, idx) => {
          const nameWords = (p.name || 'BK').trim().split(/\s+/).slice(0, 2);
          const initials = nameWords.map(w => w[0] || '').join('').toUpperCase() || 'BK';
          return {
            id: String(p.id),
            sku: p.sku || `BK${p.id}`,
            title: p.name,
            author: p.author || p.isbn_barcode || p.supplier_name || 'Bookwise Inventory',
            category: p.category_name || 'General',
            price: Number(p.unit_price) || 0,
            stock: Number(p.stock_quantity) || 0,
            reorder_level: Number(p.reorder_level) || 0,
            cover: initials,
            color: colors[idx % colors.length]
          };
        });
        isProductsLoaded = true;
        renderProducts();
        await loadDbCategories();
      }
    } catch (err) {
      console.error("Error loading products:", err);
      isProductsLoaded = true;
      renderProducts();
    }
  }

  const $ = id => document.getElementById(id);

  const money = value => {
    return "Rs. " + Math.round(value).toLocaleString("en-LK");
  };

  const toast = message => {
    const el = $("toast");
    if (!el) return;
    el.textContent = message;
    el.classList.add("show");
    clearTimeout(window.bookwiseToastTimer);
    window.bookwiseToastTimer = setTimeout(() => el.classList.remove("show"), 2200);
  };

  function renderProducts() {
    const search = ($("productSearch") ? $("productSearch").value : "").trim().toLowerCase();

    if (!isProductsLoaded) {
      productGrid.innerHTML = `<div class="no-results">Syncing products from inventory…</div>`;
      return;
    }

    const filtered = products.filter(p => {
      const matchesCategory = selectedCategory === "All" || p.category === selectedCategory;
      const matchesSearch =
        !search ||
        p.title.toLowerCase().includes(search) ||
        p.author.toLowerCase().includes(search) ||
        p.category.toLowerCase().includes(search) ||
        p.sku.toLowerCase().includes(search) ||
        p.id.toLowerCase().includes(search);

      return matchesCategory && matchesSearch;
    });

    if (!products.length) {
      productGrid.innerHTML = `
        <div class="no-results">
          <strong style="display:block;font-size:16px;color:var(--navy-deep);margin-bottom:6px">No products in inventory</strong>
          There are currently no active products in the inventory catalog.<br>
          <a href="inventory.html" class="btn btn-primary btn-sm" style="margin-top:14px;display:inline-block">Go to Inventory Management</a>
        </div>
      `;
      return;
    }

    if (!filtered.length) {
      productGrid.innerHTML = `<div class="no-results">No inventory items match your search.</div>`;
      return;
    }

    productGrid.innerHTML = filtered.map(p => {
      const isOutOfStock = p.stock <= 0;
      const isLowStock = !isOutOfStock && p.stock <= (p.reorder_level || 5);
      const stockBadge = isOutOfStock
        ? '<div class="stock-text low" style="color:var(--burgundy);font-weight:700">Out of stock</div>'
        : (isLowStock
          ? `<div class="stock-text low">Low stock (${p.stock} left)</div>`
          : `<div class="stock-text">${p.stock} in stock</div>`);

      return `
        <article class="product-card" data-product-id="${p.id}" style="${isOutOfStock ? 'opacity:0.6;' : ''}">
          <div class="product-cover ${p.color || ""}">${escapeHtml(p.cover)}</div>
          <div class="product-title" title="${escapeHtml(p.title)}">${escapeHtml(p.title)}</div>
          <div class="product-author">${escapeHtml(p.author)}</div>
          <div class="product-bottom">
            <div>
              <div class="product-price">${money(p.price)}</div>
              ${stockBadge}
            </div>
            <button class="add-product" data-add="${p.id}" ${isOutOfStock ? 'disabled style="opacity:0.4;cursor:not-allowed"' : ''}>
              ${isOutOfStock ? 'Sold Out' : 'Add'}
            </button>
          </div>
        </article>
      `;
    }).join("");
  }

  function renderCart() {
    const itemCount = cart.reduce((sum, item) => sum + item.qty, 0);
    $("cartCount").textContent = `${itemCount} ${itemCount === 1 ? "item" : "items"}`;

    if (!cart.length) {
      cartItems.innerHTML = `
        <div class="cart-empty">
          <strong>Your order is empty</strong>
          Select a book from the product list to begin.
        </div>
      `;
    } else {
      cartItems.innerHTML = cart.map(item => `
        <div class="cart-item">
          <div>
            <div class="cart-item-name">${escapeHtml(item.title)}</div>
            <div class="cart-item-price">${money(item.price)} each</div>
            <div class="qty-controls">
              <button class="qty-btn" data-qty-minus="${item.id}">−</button>
              <span class="qty-number">${item.qty}</span>
              <button class="qty-btn" data-qty-plus="${item.id}">+</button>
              <button class="remove-item" data-remove="${item.id}">Remove</button>
            </div>
          </div>
          <div class="cart-item-total">${money(item.price * item.qty)}</div>
        </div>
      `).join("");
    }

    updateSummary();
  }

  function updateSummary() {
    const subtotal = cart.reduce((sum, item) => sum + item.price * item.qty, 0);
    const discount = subtotal * discountPercent / 100;
    const taxable = Math.max(0, subtotal - discount);
    const tax = taxable * 0.05;
    const total = taxable + tax;

    $("subtotal").textContent = money(subtotal);
    $("tax").textContent = money(tax);
    $("discountAmount").textContent = "- " + money(discount);
    $("grandTotal").textContent = money(total);
    $("completeSaleBtn").disabled = cart.length === 0;
  }

  function addToCart(id) {
    const product = products.find(p => p.id === id);
    if (!product) return;

    if (product.stock <= 0) {
      toast("This item is currently out of stock in inventory.");
      return;
    }

    const existing = cart.find(item => item.id === id);

    if (existing) {
      if (existing.qty >= product.stock) {
        toast(`Only ${product.stock} ${product.stock === 1 ? 'unit' : 'units'} available in inventory.`);
        return;
      }
      existing.qty++;
    } else {
      cart.push({
        id: product.id,
        title: product.title,
        price: product.price,
        qty: 1,
        stock: product.stock
      });
    }

    renderCart();
    toast(`${product.title} added to order.`);
  }

  function changeQty(id, amount) {
    const item = cart.find(i => i.id === id);
    if (!item) return;

    item.qty += amount;

    if (item.qty <= 0) {
      cart = cart.filter(i => i.id !== id);
    } else if (item.qty > item.stock) {
      item.qty = item.stock;
      toast("Maximum available stock reached.");
    }

    renderCart();
  }

  function clearCart(showMessage = true) {
    cart = [];
    discountPercent = 0;
    $("discountInput").value = "";
    $("orderRef").textContent = "NEW ORDER";
    renderCart();
    if (showMessage) toast("Current order cleared.");
  }

  function applyDiscount() {
    let value = Number($("discountInput").value);

    if (!Number.isFinite(value)) value = 0;
    value = Math.min(100, Math.max(0, value));

    discountPercent = value;
    $("discountInput").value = value ? value : "";
    updateSummary();

    toast(value ? `${value}% discount applied.` : "Discount removed.");
  }

  async function completeSale() {
    if (!cart.length) {
      toast("Add at least one item before completing the sale.");
      return;
    }

    const saleBtn = $("completeSaleBtn");
    if (saleBtn) {
      saleBtn.disabled = true;
      saleBtn.textContent = "Processing Sale…";
    }

    const subtotal = cart.reduce((sum, item) => sum + item.price * item.qty, 0);
    const discount = subtotal * discountPercent / 100;
    const tax = Math.max(0, subtotal - discount) * 0.05;
    const total = Math.round(subtotal - discount + tax);
    const ref = "ORD-" + (2292 + orders.length - 5);

    const newOrder = {
      ref,
      customer: "Walk-in Customer",
      items: cart.reduce((sum, item) => sum + item.qty, 0),
      payment: selectedPayment,
      amount: total,
      status: "Paid",
      date: new Date().toLocaleString("en-GB", {
        day:"2-digit", month:"short", year:"numeric",
        hour:"2-digit", minute:"2-digit"
      })
    };

    try {
      const response = await fetch("api/index.php?resource=sales", {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify({
          customer_name: "Walk-in Customer",
          payment_method: selectedPayment,
          discount_percent: discountPercent,
          discount_amount: discount,
          items: cart.map(item => ({
            product_id: Number.isInteger(Number(item.id)) ? Number(item.id) : null,
            name: item.title,
            quantity: item.qty,
            unit_price: item.price
          }))
        })
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        toast(result.message || "Could not save the sale.");
        if (saleBtn) {
          saleBtn.disabled = cart.length === 0;
          saleBtn.textContent = "Complete Sale";
        }
        return;
      }
      newOrder.ref = result.order_ref;
      if (result.id) newOrder.id = result.id;
      if (result.total_amount !== undefined) newOrder.amount = result.total_amount;
      orders.unshift(newOrder);
    } catch (error) {
      toast("Could not connect to the sales database.");
      if (saleBtn) {
        saleBtn.disabled = cart.length === 0;
        saleBtn.textContent = "Complete Sale";
      }
      return;
    } finally {
      if (saleBtn) {
        saleBtn.disabled = cart.length === 0;
        saleBtn.textContent = "Complete Sale";
      }
    }

    $("orderRef").textContent = ref;
    showReceipt(newOrder, subtotal, discount, tax);

    cart = [];
    discountPercent = 0;
    $("discountInput").value = "";

    renderCart();
    await Promise.all([loadDbProducts(), loadSavedOrders()]);
  }

  function showReceipt(order, subtotal, discount, tax) {
    $("receiptBody").innerHTML = `
      <div class="receipt-meta">
        <strong>${order.ref}</strong><br>
        Customer: ${escapeHtml(order.customer)}<br>
        Payment: ${escapeHtml(order.payment)}<br>
        ${escapeHtml(order.date)}
      </div>

      <div class="receipt-items">
        ${cart.map(item => `
          <div class="receipt-row">
            <span>${escapeHtml(item.title)} × ${item.qty}</span>
            <strong>${money(item.price * item.qty)}</strong>
          </div>
        `).join("")}
      </div>

      <div class="summary-line"><span>Subtotal</span><strong>${money(subtotal)}</strong></div>
      <div class="summary-line"><span>Discount</span><strong>- ${money(discount)}</strong></div>
      <div class="summary-line"><span>Tax</span><strong>${money(tax)}</strong></div>
      <div class="receipt-total"><span>Total</span><span>${money(order.amount)}</span></div>
    `;

    $("receiptModal").classList.add("show");
  }

  function closeReceipt() {
    $("receiptModal").classList.remove("show");
  }

  function renderOrders() {
    const search = ($("orderSearch").value || "").trim().toLowerCase();
    const status = $("orderStatus").value;

    const filtered = orders.filter(order => {
      const matchesStatus = status === "All" || order.status === status;
      const text = `${order.ref || order.order_ref} ${order.customer || order.customer_name} ${order.payment || order.payment_method}`.toLowerCase();
      return matchesStatus && (!search || text.includes(search));
    });

    $("ordersTableBody").innerHTML = filtered.length
      ? filtered.map(order => `
        <tr>
          <td class="cell-strong">${escapeHtml(order.ref || order.order_ref)}</td>
          <td>${escapeHtml(order.customer || order.customer_name)}</td>
          <td>${order.items || 1}</td>
          <td>${escapeHtml(order.payment || order.payment_method)}</td>
          <td class="cell-mono">${money(order.amount || order.total_amount)}</td>
          <td><span class="status-pill ${(order.status || 'paid').toLowerCase()}">${escapeHtml(order.status || 'Paid')}</span></td>
          <td>${escapeHtml(order.date || order.created_at || '—')}</td>
          <td style="text-align:right;white-space:nowrap">
            <div style="display:inline-flex;gap:6px">
              <button class="icon-btn" type="button" data-view-order="${order.id || order.ref || order.order_ref}" title="View Receipt">Receipt</button>
              <button class="icon-btn" type="button" data-edit-order="${order.id || order.ref || order.order_ref}" title="Edit Order">Edit</button>
              <button class="icon-btn danger" type="button" data-delete-order="${order.id || order.ref || order.order_ref}" title="Delete Order">Delete</button>
            </div>
          </td>
        </tr>
      `).join("")
      : `<tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px">No orders found.</td></tr>`;
  }

  async function viewOrderReceipt(identifier) {
    let order = orders.find(o => String(o.id) === String(identifier) || o.ref === identifier || o.order_ref === identifier);
    if (!order) return;
    let items = [];
    if (order.id) {
      try {
        const res = await fetch(`api/index.php?resource=sales&id=${order.id}`);
        const data = await res.json();
        if (data.success && data.data) {
          order = { ...order, ...data.data };
          items = data.data.items || [];
        }
      } catch (_) {}
    }

    const itemsHtml = items.length
      ? items.map(item => `
        <div class="receipt-row">
          <span>${escapeHtml(item.product_name)} × ${item.quantity}</span>
          <strong>${money(item.line_total)}</strong>
        </div>
      `).join("")
      : `<div class="receipt-row"><span>Items: ${order.items || 1}</span><strong>${money(order.amount || order.total_amount)}</strong></div>`;

    const subtotalVal = order.subtotal || (order.amount || order.total_amount);
    const totalVal = order.amount || order.total_amount;

    $("receiptBody").innerHTML = `
      <div class="receipt-meta">
        <strong>${escapeHtml(order.ref || order.order_ref)}</strong><br>
        Customer: ${escapeHtml(order.customer || order.customer_name)}<br>
        Payment: ${escapeHtml(order.payment || order.payment_method)} · Status: <strong>${escapeHtml(order.status)}</strong><br>
        ${escapeHtml(order.date || order.created_at || new Date().toLocaleDateString())}
      </div>
      <div class="receipt-items">
        ${itemsHtml}
      </div>
      ${order.subtotal ? `<div class="summary-line"><span>Subtotal</span><strong>${money(subtotalVal)}</strong></div>` : ''}
      ${order.discount_amount ? `<div class="summary-line"><span>Discount</span><strong>- ${money(order.discount_amount)}</strong></div>` : ''}
      ${order.tax_amount ? `<div class="summary-line"><span>Tax</span><strong>${money(order.tax_amount)}</strong></div>` : ''}
      <div class="receipt-total"><span>Total</span><span>${money(totalVal)}</span></div>
    `;
    $("receiptModal").classList.add("show");
  }

  function openEditOrder(identifier) {
    const order = orders.find(o => String(o.id) === String(identifier) || o.ref === identifier || o.order_ref === identifier);
    if (!order) return;
    $("editOrderId").value = order.id || '';
    $("editOrderRef").value = order.ref || order.order_ref || '';
    $("editCustomerName").value = order.customer || order.customer_name || 'Walk-in Customer';
    $("editPaymentMethod").value = (order.payment || order.payment_method) === 'Card' ? 'Card' : 'Cash';
    $("editOrderStatus").value = order.status || 'Paid';
    $("editOrderModal").classList.add("show");
  }

  function closeEditOrder() {
    $("editOrderModal").classList.remove("show");
  }

  async function deleteOrder(identifier) {
    const order = orders.find(o => String(o.id) === String(identifier) || o.ref === identifier || o.order_ref === identifier);
    const ref = order ? (order.ref || order.order_ref) : identifier;
    const confirmed = typeof window.bookwiseConfirm === 'function'
      ? await window.bookwiseConfirm(`Are you sure you want to permanently delete order "${ref}"?`, { title: 'Delete Order', confirmText: 'Delete' })
      : true;
    if (!confirmed) return;

    if (order && order.id) {
      try {
        const response = await fetch(`api/index.php?resource=sales&id=${order.id}&name=${encodeURIComponent(ref || '')}`, { method: "DELETE" });
        const result = await response.json();
        if (!response.ok || !result.success) {
          toast(result.message || "Failed to delete order.");
          return;
        }
        toast(result.message || "Order deleted successfully.");
      } catch (err) {
        toast("Error deleting order: " + err.message);
        return;
      }
    }
    orders = orders.filter(o => String(o.id) !== String(identifier) && o.ref !== identifier && o.order_ref !== identifier);
    renderOrders();
  }

  function updateStats(newOrder) {
    const salesEl = $("todaySales");
    const ordersEl = $("todayOrders");
    const itemsEl = $("itemsSold");
    const avgEl = $("averageOrder");

    const currentSales = Number(salesEl.dataset.value || 48550) + newOrder.amount;
    const currentOrders = Number(ordersEl.dataset.value || 18) + 1;
    const currentItems = Number(itemsEl.dataset.value || 64) + newOrder.items;

    salesEl.dataset.value = currentSales;
    ordersEl.dataset.value = currentOrders;
    itemsEl.dataset.value = currentItems;

    salesEl.textContent = money(currentSales);
    ordersEl.textContent = currentOrders;
    itemsEl.textContent = currentItems;
    avgEl.textContent = money(currentSales / currentOrders);
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
      "&":"&amp;",
      "<":"&lt;",
      ">":"&gt;",
      '"':"&quot;",
      "'":"&#039;"
    }[char]));
  }

  // Product buttons.
  productGrid.addEventListener("click", event => {
    const button = event.target.closest("[data-add]");
    if (button) {
      addToCart(button.dataset.add);
      return;
    }

    const card = event.target.closest(".product-card");
    if (card) addToCart(card.dataset.productId);
  });

  // Cart controls.
  cartItems.addEventListener("click", event => {
    const plus = event.target.closest("[data-qty-plus]");
    const minus = event.target.closest("[data-qty-minus]");
    const remove = event.target.closest("[data-remove]");

    if (plus) changeQty(plus.dataset.qtyPlus, 1);
    if (minus) changeQty(minus.dataset.qtyMinus, -1);

    if (remove) {
      cart = cart.filter(item => item.id !== remove.dataset.remove);
      renderCart();
      toast("Item removed from order.");
    }
  });

  // Search.
  $("productSearch").addEventListener("input", renderProducts);

  // Categories.
  document.querySelectorAll(".pos-category").forEach(button => {
    button.addEventListener("click", () => {
      document.querySelectorAll(".pos-category").forEach(b => b.classList.remove("active"));
      button.classList.add("active");
      selectedCategory = button.dataset.category;
      renderProducts();
    });
  });

  // Payment method.
  document.querySelectorAll(".payment-option").forEach(button => {
    button.addEventListener("click", () => {
      document.querySelectorAll(".payment-option").forEach(b => b.classList.remove("active"));
      button.classList.add("active");
      selectedPayment = button.dataset.payment;
    });
  });

  $("applyDiscountBtn").addEventListener("click", applyDiscount);
  $("discountInput").addEventListener("keydown", event => {
    if (event.key === "Enter") applyDiscount();
  });

  $("clearCartBtn").addEventListener("click", () => clearCart(true));
  $("newOrderBtn").addEventListener("click", () => {
    clearCart(false);
    toast("New order started.");
  });

  $("completeSaleBtn").addEventListener("click", completeSale);

  $("orderSearch").addEventListener("input", renderOrders);
  $("orderStatus").addEventListener("change", renderOrders);

  $("closeReceiptBtn").addEventListener("click", closeReceipt);
  $("closeReceiptAction").addEventListener("click", closeReceipt);

  $("receiptModal").addEventListener("click", event => {
    if (event.target === $("receiptModal")) closeReceipt();
  });

  $("printReceiptBtn").addEventListener("click", () => {
    const receipt = $("receiptBody").innerHTML;
    const printWindow = window.open("", "_blank", "width=500,height=700");

    if (!printWindow) {
      toast("Please allow pop-ups to print the receipt.");
      return;
    }

    printWindow.document.write(`
      <!DOCTYPE html>
      <html>
      <head>
        <title>BOOKWISE Receipt</title>
        <style>
          body{font-family:Arial,sans-serif;padding:30px;color:#222;max-width:430px;margin:auto}
          h2{font-family:Georgia,serif;color:#1B2A4A}
          .receipt-row,.summary-line,.receipt-total{display:flex;justify-content:space-between;padding:7px 0}
          .receipt-items{border-top:1px dashed #aaa;border-bottom:1px dashed #aaa;margin:15px 0;padding:8px 0}
          .receipt-total{font-size:20px;font-weight:bold;border-top:1px solid #aaa;margin-top:10px}
          .receipt-meta{line-height:1.7;color:#666}
        </style>
      </head>
      <body>
        <h2>BOOKWISE</h2>
        <p>Minre Book Shop</p>
        ${receipt}
        <p style="text-align:center;margin-top:25px">Thank you for your purchase.</p>
      </body>
      </html>
    `);

    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  });

  // Table actions delegation (Receipt, Edit, Delete).
  const ordersTableBody = $("ordersTableBody");
  if (ordersTableBody) {
    ordersTableBody.addEventListener("click", event => {
      const viewBtn = event.target.closest("[data-view-order]");
      if (viewBtn) {
        viewOrderReceipt(viewBtn.dataset.viewOrder);
        return;
      }
      const editBtn = event.target.closest("[data-edit-order]");
      if (editBtn) {
        openEditOrder(editBtn.dataset.editOrder);
        return;
      }
      const delBtn = event.target.closest("[data-delete-order]");
      if (delBtn) {
        deleteOrder(delBtn.dataset.deleteOrder);
        return;
      }
    });
  }

  // Edit Order Form Submission
  const editOrderForm = $("editOrderForm");
  if (editOrderForm) {
    editOrderForm.addEventListener("submit", async event => {
      event.preventDefault();
      const id = $("editOrderId").value;
      const payload = {
        customer_name: $("editCustomerName").value.trim(),
        payment_method: $("editPaymentMethod").value,
        status: $("editOrderStatus").value
      };
      try {
        const res = await fetch(`api/index.php?resource=sales&id=${id}`, {
          method: "PUT",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
          toast(data.message || "Failed to update order.");
          return;
        }
        toast("Order updated successfully.");
        closeEditOrder();
        await loadSavedOrders();
      } catch (err) {
        toast("Error updating order: " + err.message);
      }
    });
  }

  $("closeEditOrderBtn")?.addEventListener("click", closeEditOrder);
  $("cancelEditOrderBtn")?.addEventListener("click", closeEditOrder);
  $("editOrderModal")?.addEventListener("click", event => {
    if (event.target === $("editOrderModal")) closeEditOrder();
  });

  $("refreshPosBtn")?.addEventListener("click", async () => {
    toast("Syncing with inventory…");
    try {
      await Promise.all([loadDbProducts(), loadSavedOrders()]);
      toast("POS catalog synced with live inventory.");
    } catch (_) {
      toast("Sync failed.");
    }
  });

  window.addEventListener("focus", () => {
    loadDbProducts().catch(() => {});
    loadSavedOrders().catch(() => {});
  });

  loadDbProducts().catch(() => {});
  loadSavedOrders().catch(() => {});

  // Initial render.
  renderProducts();
  renderCart();
  renderOrders();
});
