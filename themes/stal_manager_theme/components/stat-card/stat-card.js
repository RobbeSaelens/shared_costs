/**
 * @file
 * Counts a stat card's number up to its value when it appears.
 */

(function (Drupal, once) {
  /**
   * Formats a number like the server does: prefix, marks and decimals.
   *
   * @param {number} value
   *   The number.
   * @param {HTMLElement} element
   *   The element with the format in its data attributes.
   *
   * @return {string}
   *   The formatted number.
   */
  function format(value, element) {
    const { decimals, decimalMark, thousandsMark, prefix, suffix } =
      element.dataset;
    const [whole, fraction] = Math.abs(value)
      .toFixed(Number(decimals))
      .split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, thousandsMark);
    const sign = value < 0 ? '-' : '';
    return `${prefix}${sign}${grouped}${fraction ? decimalMark + fraction : ''}${suffix}`;
  }

  /**
   * Counts the stat cards in a context up from zero.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.stalManagerStatCard = {
    attach(context) {
      const reduce = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
      ).matches;
      once('stat-card-count-up', '[data-count-up]', context).forEach(
        (element) => {
          const target = Number(element.dataset.countUp);
          if (reduce || !Number.isFinite(target) || target === 0) {
            return;
          }
          const final = element.innerHTML;
          // Screen readers get the final value right away.
          element.setAttribute('aria-label', element.textContent.trim());
          const duration = 900;
          const start = performance.now();
          const step = (now) => {
            const progress = Math.min(1, (now - start) / duration);
            const eased = 1 - (1 - progress) ** 3;
            if (progress < 1) {
              element.textContent = format(target * eased, element);
              requestAnimationFrame(step);
            } else {
              element.innerHTML = final;
              element.removeAttribute('aria-label');
            }
          };
          requestAnimationFrame(step);
        },
      );
    },
  };
})(Drupal, once);
