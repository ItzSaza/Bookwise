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

  const products = [
    {id:"BK001", title:"The Silent Patient", author:"Alex Michaelides", category:"Fiction", price:1850, stock:12, cover:"TSP"},
    {id:"BK002", title:"Atomic Habits", author:"James Clear", category:"Business", price:3200, stock:8, cover:"AH", color:"sage"},
    {id:"BK003", title:"The Psychology of Money", author:"Morgan Housel", category:"Business", price:2850, stock:15, cover:"PM", color:"red"},
    {id:"BK004", title:"Rich Dad Poor Dad", author:"Robert Kiyosaki", category:"Business", price:2400, stock:6, cover:"RD", color:"gold"},
    {id:"BK005", title:"English Grammar in Use", author:"Raymond Murphy", category:"Education", price:2950, stock:9, cover:"EG"},
    {id:"BK006", title:"Clean Code", author:"Robert C. Martin", category:"Education", price:4200, stock:5, cover:"CC", color:"sage"},
    {id:"BK007", title:"Harry Potter and the Philosopher's Stone", author:"J. K. Rowling", category:"Fiction", price:3500, stock:11, cover:"HP", color:"gold"},
    {id:"BK008", title:"The Alchemist", author:"Paulo Coelho", category:"Fiction", price:2100, stock:20, cover:"TA", color:"red"},
    {id:"BK009", title:"Charlotte's Web", author:"E. B. White", category:"Children", price:1650, stock:14, cover:"CW", color:"sage"},
    {id:"BK010", title:"The Very Hungry Caterpillar", author:"Eric Carle", category:"Children", price:1450, stock:4, cover:"VC", color:"gold"},
    {id:"BK011", title:"Introduction to Algorithms", author:"Thomas H. Cormen", category:"Education", price:5200, stock:3, cover:"IA", color:"red"},
    {id:"BK012", title:"Deep Work", author:"Cal Newport", category:"Business", price:2750, stock:10, cover:"DW"}
  ];

  let cart = [];
  let selectedCategory = "All";
  let selectedPayment = "Cash";
  let discountPercent = 0;

  let orders = [
    {ref:"ORD-2291", customer:"Walk-in Customer", items:3, payment:"Cash", amount:4550, status:"Paid", date:"19 Sep 2026, 17:42"},
    {ref:"ORD-2290", customer:"N. Perera", items:2, payment:"Card", amount:6050, status:"Paid", date:"19 Sep 2026, 16:58"},
    {ref:"ORD-2289", customer:"S. Fernando", items:4, payment:"Cash", amount:8750, status:"Pending", date:"19 Sep 2026, 15:31"},
    {ref:"ORD-2288", customer:"Walk-in Customer", items:1, payment:"Card", amount:2100, status:"Paid", date:"19 Sep 2026, 14:46"},
    {ref:"ORD-2287", customer:"K. Silva", items:2, payment:"Cash", amount:5200, status:"Refunded", date:"19 Sep 2026, 13:19"}
  ];

  async function loadSavedOrders() {
    const response = await fetch("api/index.php?resource=sales");
    const result = await response.json();
    if (response.ok && result.success && result.data.length) {
      orders = result.data;
    }
  }

  const $ = id => document.getElementById(id);

  const money = value => {
    return "Rs. " + Math.round(value).toLocaleString("en-LK");
  };

  const toast = message => {
    const el = $("toast");
    el.textContent = message;
    el.classList.add("show");
    clearTimeout(window.bookwiseToastTimer);
    window.bookwiseToastTimer = setTimeout(() => el.classList.remove("show"), 2200);
  };

  function renderProducts() {
    const search = ($("productSearch").value || "").trim().toLowerCase();

    const filtered = products.filter(p => {
      const matchesCategory = selectedCategory === "All" || p.category === selectedCategory;
      const matchesSearch =
        !search ||
        p.title.toLowerCase().includes(search) ||
        p.author.toLowerCase().includes(search) ||
        p.id.toLowerCase().includes(search);

      return matchesCategory && matchesSearch;
    });

    if (!filtered.length) {
      productGrid.innerHTML = `<div class="no-results">No books match your search.</div>`;
      return;
    }

    productGrid.innerHTML = filtered.map(p => `
      <article class="product-card" data-product-id="${p.id}">
        <div class="product-cover ${p.color || ""}">${p.cover}</div>
        <div class="product-title">${escapeHtml(p.title)}</div>
        <div class="product-author">${escapeHtml(p.author)}</div>
        <div class="product-bottom">
          <div>
            <div class="product-price">${money(p.price)}</div>
            <div class="stock-text ${p.stock <= 5 ? "low" : ""}">
              ${p.stock <= 5 ? "Low stock" : p.stock + " in stock"}
            </div>
          </div>
          <button class="add-product" data-add="${p.id}">Add</button>
        </div>
      </article>
    `).join("");
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

    const existing = cart.find(item => item.id === id);

    if (existing) {
      if (existing.qty >= product.stock) {
        toast("No more stock available for this book.");
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
          payment_method: selectedPayment,
          subtotal,
          discount_amount: discount,
          tax_amount: tax,
          items: cart.map(item => ({
            name: item.title,
            quantity: item.qty,
            unit_price: item.price
          }))
        })
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        toast(result.message || "Could not save the sale.");
        return;
      }
      newOrder.ref = result.order_ref;
      orders.unshift(newOrder);
    } catch (error) {
      toast("Could not connect to the sales database.");
      return;
    }

    // Reduce demo stock.
    cart.forEach(item => {
      const product = products.find(p => p.id === item.id);
      if (product) product.stock = Math.max(0, product.stock - item.qty);
    });

    $("orderRef").textContent = ref;
    showReceipt(newOrder, subtotal, discount, tax);

    cart = [];
    discountPercent = 0;
    $("discountInput").value = "";

    renderProducts();
    renderCart();
    renderOrders();
    updateStats(newOrder);
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
      const text = `${order.ref} ${order.customer} ${order.payment}`.toLowerCase();
      return matchesStatus && (!search || text.includes(search));
    });

    $("ordersTableBody").innerHTML = filtered.length
      ? filtered.map(order => `
        <tr>
          <td class="cell-strong">${order.ref}</td>
          <td>${escapeHtml(order.customer)}</td>
          <td>${order.items}</td>
          <td>${escapeHtml(order.payment)}</td>
          <td class="cell-mono">${money(order.amount)}</td>
          <td><span class="status-pill ${order.status.toLowerCase()}">${order.status}</span></td>
          <td>${escapeHtml(order.date)}</td>
        </tr>
      `).join("")
      : `<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px">No orders found.</td></tr>`;
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

  loadSavedOrders().then(renderOrders).catch(() => renderOrders());

  // Initial render.
  renderProducts();
  renderCart();
  renderOrders();
});
