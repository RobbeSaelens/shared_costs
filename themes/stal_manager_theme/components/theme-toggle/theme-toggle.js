/**
 * @file
 * Switches between the light and dark theme, remembered in this browser.
 *
 * The theme starts from the system setting; an inline script in the page's
 * head sets data-theme before the page paints, so it doesn't flash.
 */

(function (Drupal, once) {
  const root = document.documentElement;

  /**
   * Stores the choice, where the browser allows it.
   *
   * @param {string} theme
   *   'light' or 'dark'.
   */
  function remember(theme) {
    try {
      localStorage.setItem('stal-manager-theme', theme);
    } catch {
      // Private mode or storage blocked: the choice lasts for this page.
    }
  }

  /**
   * Sets the theme and tells everything that draws in color.
   *
   * @param {string} theme
   *   'light' or 'dark'.
   */
  function apply(theme) {
    const update = () => {
      root.dataset.theme = theme;
      document
        .querySelectorAll('[data-theme-toggle]')
        .forEach((button) =>
          button.setAttribute('aria-pressed', String(theme === 'dark')),
        );
      root.dispatchEvent(
        new CustomEvent('stal:themechange', { detail: { theme } }),
      );
    };
    // A smooth cross-fade between the themes, where the browser can.
    if (
      document.startViewTransition &&
      !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
      // A plain cross-fade, not the page's own "rise in" (see animations.css).
      root.classList.add('is-switching-theme');
      document
        .startViewTransition(update)
        .finished.finally(() => root.classList.remove('is-switching-theme'));
    } else {
      update();
    }
  }

  /**
   * Wires up the theme toggles.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.stalManagerThemeToggle = {
    attach(context) {
      once('theme-toggle', '[data-theme-toggle]', context).forEach((button) => {
        button.setAttribute(
          'aria-pressed',
          String(root.dataset.theme === 'dark'),
        );
        button.addEventListener('click', () => {
          const theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
          remember(theme);
          apply(theme);
        });
      });
    },
  };
})(Drupal, once);
