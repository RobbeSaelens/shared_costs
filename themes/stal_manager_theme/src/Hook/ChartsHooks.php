<?php

declare(strict_types=1);

namespace Drupal\stal_manager_theme\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Charts (the Charts module): sized by their card, not by their width.
 */
final class ChartsHooks {

  /**
   * Implements hook_preprocess_charts_chart().
   *
   * Puts the chart in a box of its own. Chart.js sizes a chart to its parent:
   * in this box, the chart takes the box's height (which the theme sets, or
   * which the dashboard stretches to fill the screen), not the height of the
   * figure with its "Show the numbers" button and table.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_charts_chart')]
  public function preprocessChartsChart(array &$variables): void {
    if (isset($variables['content']['chart'])) {
      $variables['content']['chart']['#prefix'] = '<div class="chart-box">';
      $variables['content']['chart']['#suffix'] = '</div>';
    }
  }

}
