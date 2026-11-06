/**
 * @file
 * The app shell: moving around with HTMX, the sidebar and the messages.
 */

(function (Drupal, once) {
  const root = document.documentElement;

  /**
   * Stores a choice, where the browser allows it.
   *
   * @param {string} key
   *   The key.
   * @param {string} value
   *   The value.
   */
  function remember(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch {
      // Private mode or storage blocked: the choice lasts for this page.
    }
  }

  /**
   * Hides a message, with its exit animation.
   *
   * @param {HTMLElement} message
   *   The message.
   */
  function dismiss(message) {
    if (message.classList.contains('is-leaving')) {
      return;
    }
    message.classList.add('is-leaving');
    const remove = () => message.remove();
    message.addEventListener('animationend', remove, { once: true });
    // Without animations (reduced motion), animationend never fires.
    setTimeout(remove, 400);
  }

  /**
   * After HTMX brought in a page: focus it and announce it.
   *
   * Only for pages swapped into #main-content, not for live filters (which
   * would take the focus away from the field you type in).
   *
   * @param {CustomEvent} event
   *   The htmx:afterSettle event.
   */
  function onPageSwapped(event) {
    const { target } = event.detail;
    if (!target || target.id !== 'main-content') {
      return;
    }
    const main = document.getElementById('main-content');
    main?.focus({ preventScroll: true });
    Drupal.announce(document.title);
  }

  /**
   * The app shell's behaviors.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.stalManagerApp = {
    attach(context) {
      once('stal-manager-app', 'html', context).forEach(() => {
        document.addEventListener('htmx:afterSettle', onPageSwapped);
      });

      once('sidebar-toggle', '[data-sidebar-toggle]', context).forEach(
        (button) => {
          const label = button.querySelector('.visually-hidden');
          const sync = () => {
            const collapsed = root.dataset.sidebar === 'collapsed';
            button.setAttribute('aria-expanded', String(!collapsed));
            if (label) {
              label.textContent = collapsed
                ? Drupal.t('Expand the sidebar')
                : Drupal.t('Collapse the sidebar');
            }
          };
          sync();
          button.addEventListener('click', () => {
            root.dataset.sidebar =
              root.dataset.sidebar === 'collapsed' ? 'expanded' : 'collapsed';
            remember('stal-manager-sidebar', root.dataset.sidebar);
            sync();
          });
        },
      );

      once('message', '.messages', context).forEach((message) => {
        message
          .querySelector('[data-message-close]')
          ?.addEventListener('click', () => dismiss(message));
        // Status messages go after a while; warnings and errors stay. Not
        // while the pointer or the focus is on them.
        if (message.classList.contains('messages--status')) {
          let timer = setTimeout(() => dismiss(message), 6000);
          const pause = () => clearTimeout(timer);
          const resume = () => {
            timer = setTimeout(() => dismiss(message), 3000);
          };
          message.addEventListener('mouseenter', pause);
          message.addEventListener('focusin', pause);
          message.addEventListener('mouseleave', resume);
          message.addEventListener('focusout', resume);
        }
      });
    },
  };
})(Drupal, once);
