    function initMobileMenu() {
      const mobileMenuButton = document.getElementById('mobile-menu-button');
      const closeMenuButton = document.getElementById('close-menu-button');
      const mobileMenu = document.getElementById('mobile-menu');
      const menuBackdrop = document.getElementById('menu-backdrop');

      function openMenu() {
        mobileMenu.classList.add('show');
        menuBackdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
      }

      function closeMenu() {
        mobileMenu.classList.remove('show');
        menuBackdrop.classList.remove('show');
        document.body.style.overflow = '';
      }

      mobileMenuButton.addEventListener('click', openMenu);
      closeMenuButton.addEventListener('click', closeMenu);
      menuBackdrop.addEventListener('click', closeMenu);

      // Fechar menu ao clicar em um link
      const mobileMenuLinks = mobileMenu.getElementsByTagName('a');
      Array.from(mobileMenuLinks).forEach(link => {
        link.addEventListener('click', closeMenu);
      });

      // Fechar menu ao redimensionar para desktop
      window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024) {
          closeMenu();
        }
      });
    }

    document.addEventListener('DOMContentLoaded', function() {
      initMobileMenu();
    });