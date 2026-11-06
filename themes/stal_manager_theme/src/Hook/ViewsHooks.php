<?php

declare(strict_types=1);

namespace Drupal\stal_manager_theme\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\Template\AttributeHelper;
use Drupal\Core\Url;
use Drupal\stal_manager_theme\Htmx\AppNavigation;
use Drupal\views\Plugin\views\field\NumericField;
use Drupal\views\ViewExecutable;

/**
 * The Stal Manager views: cards, stat cards and live filters.
 */
final class ViewsHooks {

  /**
   * The element that holds a view's results, for the live filters.
   */
  public const RESULTS_ID = 'stable-results';

  /**
   * Implements hook_preprocess_views_view().
   *
   * Gives the Stal Manager views (stable_*) a layout:
   * - summary: the page of the dashboard and the overview. Its number (this
   *   month's costs, the month's total) becomes a stat card, its attachments
   *   a grid of cards.
   * - list: the other pages. Actions above, the results in a card.
   * - card: attachments. The whole view is one card.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_views_view')]
  public function preprocessViewsView(array &$variables): void {
    $view = $variables['view'];
    if (!$view instanceof ViewExecutable || !str_starts_with((string) $view->id(), 'stable_')) {
      return;
    }
    $is_page = $view->display_handler->getPluginId() === 'page';
    $layout = match (TRUE) {
      $is_page && in_array($view->id(), ['stable_dashboard', 'stable_overview'], TRUE) => 'summary',
      $is_page => 'list',
      default => 'card',
    };
    $style = $view->style_plugin?->getPluginId();
    $variables['stable'] = [
      'layout' => $layout,
      // Tables run to the card's edges.
      'flush' => $style === 'table',
      'stat' => $layout === 'summary' ? $this->stat($view) : NULL,
      'results_id' => !empty($variables['exposed']) ? self::RESULTS_ID : NULL,
    ];
  }

  /**
   * Implements hook_form_FORM_ID_alter() for views_exposed_form.
   *
   * The Stal Manager filters update the results while you type or pick, and
   * keep the filters in the URL. Without JavaScript, "Apply" still works.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_views_exposed_form_alter')]
  public function formViewsExposedFormAlter(array &$form, FormStateInterface $form_state): void {
    $view = $form_state->get('view');
    if (!$view instanceof ViewExecutable || !str_starts_with((string) $view->id(), 'stable_')) {
      return;
    }
    $url = $view->hasUrl() ? $view->getUrl() : NULL;
    if (!$url instanceof Url) {
      return;
    }
    $results = '#' . self::RESULTS_ID;
    $htmx = (new Htmx())
      ->get($url)
      // Typing and picking both fire "input", which bubbles up to the form.
      ->trigger('submit, input delay:350ms')
      ->select($results)
      ->target($results)
      ->swap('outerHTML', 'transition:true')
      ->pushUrl(TRUE)
      ->sync('this:replace')
      ->indicator(AppNavigation::PROGRESS);
    $form['#attributes'] = AttributeHelper::mergeCollections($form['#attributes'] ?? [], $htmx->getAttributes()->toArray());
    $form['#attached']['library'][] = 'core/drupal.htmx';
    $form['#attributes']['class'][] = 'stable-filters';
  }

  /**
   * Finds the number of a summary page: its first amount of money.
   *
   * @return array<string, mixed>|null
   *   The label, the formatted value, and what the stat card needs to count
   *   up to it; NULL without one.
   */
  private function stat(ViewExecutable $view): ?array {
    if (!isset($view->result[0])) {
      return NULL;
    }
    foreach ($view->field as $id => $field) {
      if (!$field instanceof NumericField || empty($field->options['prefix'])) {
        continue;
      }
      $value = $field->getValue($view->result[0]);
      return [
        'label' => $field->label(),
        'value' => $view->style_plugin->getField(0, (string) $id),
        'amount' => is_numeric($value) ? (float) $value : 0.0,
        'decimals' => !empty($field->options['set_precision']) ? (int) $field->options['precision'] : 2,
        'decimal_mark' => (string) $field->options['decimal'],
        'thousands_mark' => (string) $field->options['separator'],
        'prefix' => (string) $field->options['prefix'],
        'suffix' => (string) $field->options['suffix'],
      ];
    }
    return NULL;
  }

}
