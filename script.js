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
});
