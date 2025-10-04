document.addEventListener('DOMContentLoaded', () => {
  const sidebar = document.querySelector('.sidebar');
  if (!sidebar) {
    return;
  }

  const toggles = sidebar.querySelectorAll('.menu-toggle[data-bs-target]');
  toggles.forEach((toggle) => {
    const targetSelector = toggle.getAttribute('data-bs-target');
    if (!targetSelector) {
      return;
    }

    const target = document.querySelector(targetSelector);
    if (!target) {
      return;
    }

    target.addEventListener('show.bs.collapse', () => {
      toggle.classList.add('active');
      toggle.setAttribute('aria-expanded', 'true');
    });

    target.addEventListener('hide.bs.collapse', () => {
      toggle.classList.remove('active');
      toggle.setAttribute('aria-expanded', 'false');
    });
  });
});
