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
});
