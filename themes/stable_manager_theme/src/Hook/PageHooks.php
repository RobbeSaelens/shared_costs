<?php

declare(strict_types=1);

namespace Drupal\stable_manager_theme\Hook;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * The page around the content: the head, the app shell and its actions.
 */
final class PageHooks {

  use StringTranslationTrait;

  /**
   * Sets the theme (light or dark) and the sidebar before the page paints.
   *
   * Reads the choices this browser remembers; without one, the system's
   * color scheme decides. Inline, so the page doesn't flash in the wrong
   * colors while the theme's JavaScript loads.
   */
  private const PREFERENCES_SCRIPT = <<<'JS'
(() => {
  const root = document.documentElement;
  const dark = window.matchMedia('(prefers-color-scheme: dark)').matches;
  let theme = null;
  let sidebar = null;
  try {
    theme = localStorage.getItem('stable-manager-theme');
    sidebar = localStorage.getItem('stable-manager-sidebar');
  } catch {}
  root.dataset.theme = theme || (dark ? 'dark' : 'light');
  root.dataset.sidebar = sidebar || 'expanded';
})();
JS;

  /**
   * Builds the hooks.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, to find the expense content type.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Implements hook_preprocess_html().
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    $variables['#attached']['html_head'][] = [
      ['#tag' => 'script', '#value' => Markup::create(self::PREFERENCES_SCRIPT)],
      'stable_manager_theme_preferences',
    ];
    foreach (['light' => '#f7f2ea', 'dark' => '#231a12'] as $scheme => $color) {
      $variables['#attached']['html_head'][] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'name' => 'theme-color',
            'content' => $color,
            'media' => "(prefers-color-scheme: $scheme)",
          ],
        ],
        "stable_manager_theme_color_$scheme",
      ];
    }
    $variables['attributes']['class'][] = $this->currentUser->isAuthenticated() ? 'is-authenticated' : 'is-anonymous';
  }

  /**
   * Implements hook_preprocess_page().
   *
   * Offers "Add an expense" in the app shell, to those who may add one.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['user.permissions']);
    $cacheability->addCacheTags(['config:node_type_list']);
    $variables['add_expense'] = [];

    if ($this->entityTypeManager->hasDefinition('node_type') && $this->entityTypeManager->getStorage('node_type')->load('expense')) {
      $url = Url::fromRoute('node.add', ['node_type' => 'expense']);
      $access = $url->access($this->currentUser, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access instanceof AccessResultInterface && $access->isAllowed()) {
        $variables['add_expense'] = [
          '#type' => 'link',
          '#title' => [
            'icon' => [
              '#type' => 'component',
              '#component' => 'stable_manager_theme:icon',
              '#props' => ['name' => 'plus'],
            ],
            'label' => [
              '#markup' => $this->t('Add an expense'),
              '#prefix' => '<span class="nav-label">',
              '#suffix' => '</span>',
            ],
          ],
          '#url' => $url,
          '#attributes' => ['class' => ['app-add']],
        ];
      }
    }
    $cacheability->applyTo($variables['add_expense']);
  }

}
