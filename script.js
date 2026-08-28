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
