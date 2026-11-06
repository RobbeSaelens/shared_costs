/**
 * @file
 * Draws the Chart.js charts in the theme's colors, light or dark.
 *
 * A Chart.js plugin restyles every chart before it draws: rounded bars in the
 * theme's chart colors (the next color for each chart on the page), and axes,
 * grid and tooltips that follow the theme. When the theme changes, the charts
 * redraw.
 */

(function (Drupal, once) {
  const root = document.documentElement;

  /**
   * Reads the theme's colors from its custom properties, resolved.
   *
   * The properties hold light-dark() pairs, which a canvas can't parse: an
   * element's computed color gives the value for the current scheme.
   *
   * @return {object}
   *   The colors and the font.
   */
  function palette() {
    const probe = document.createElement('span');
    probe.hidden = true;
    document.body.append(probe);
    const resolve = (name) => {
      probe.style.color = `var(${name})`;
      return getComputedStyle(probe).color;
    };
    const colors = {
      series: [1, 2, 3, 4, 5].map((i) => resolve(`--color-chart-${i}`)),
      text: resolve('--color-muted-foreground'),
      grid: resolve('--color-border'),
      card: resolve('--color-card'),
      foreground: resolve('--color-foreground'),
      font: getComputedStyle(root).getPropertyValue('--font-sans').trim(),
    };
    probe.remove();
    return colors;
  }

  /**
   * Restyles a chart.
   *
   * @param {Chart} chart
   *   The chart.
   */
  function restyle(chart) {
    const colors = palette();
    // Each chart on the page gets the next chart color.
    const position = Math.max(
      0,
      [...document.querySelectorAll('canvas')].indexOf(chart.canvas),
    );
    chart.data.datasets.forEach((dataset, index) => {
      const color = colors.series[(position + index) % colors.series.length];
      Object.assign(dataset, {
        backgroundColor: color,
        borderColor: color,
        hoverBackgroundColor: color,
        borderRadius: 8,
        borderSkipped: false,
        maxBarThickness: 64,
      });
    });
    Object.values(chart.options.scales || {}).forEach((scale) => {
      if (scale.grid) {
        scale.grid.color = colors.grid;
      }
      if (scale.border) {
        scale.border.display = false;
      }
      if (scale.ticks) {
        scale.ticks.color = colors.text;
      }
    });
    Object.assign(chart.options.plugins.tooltip, {
      backgroundColor: colors.foreground,
      titleColor: colors.card,
      bodyColor: colors.card,
      padding: 10,
      cornerRadius: 8,
      displayColors: false,
    });
    chart.options.animation = window.matchMedia(
      '(prefers-reduced-motion: reduce)',
    ).matches
      ? false
      : { duration: 900, easing: 'easeOutQuart' };
  }

  /**
   * Registers the plugin, once Chart.js has loaded.
   *
   * This file loads before Chart.js (it extends the Charts library), but its
   * behavior runs before the one that draws the charts.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.stalManagerCharts = {
    attach() {
      const { Chart } = window;
      if (!Chart) {
        return;
      }
      once('stal-manager-charts', 'html').forEach(() => {
        Chart.defaults.font.family = palette().font;
        Chart.register({
          id: 'stalManagerTheme',
          // The chart fills its box (see ChartsHooks), whatever its width.
          beforeInit: (chart) => {
            chart.options.maintainAspectRatio = false;
          },
          beforeUpdate: restyle,
        });
        root.addEventListener('stal:themechange', () => {
          Object.values(Chart.instances).forEach((chart) => chart.update());
        });
      });
    },
  };
})(Drupal, once);
