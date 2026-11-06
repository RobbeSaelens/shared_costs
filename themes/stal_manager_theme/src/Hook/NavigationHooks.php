<?php

declare(strict_types=1);

namespace Drupal\stal_manager_theme\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Template\AttributeHelper;
use Drupal\Core\Url;
use Drupal\stal_manager_theme\Htmx\AppNavigation;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Navigation: the app's menus, the month list and the pager, with HTMX.
 */
final class NavigationHooks {

  /**
   * Icons for the Stal Manager pages and the account links, by route.
   */
  private const ICONS = [
    'view.stable_dashboard.page_1' => 'dashboard',
    'view.stable_expenses.page_1' => 'receipt',
    'view.stable_overview.page_1' => 'chart',
    'view.stable_horses.page_1' => 'horseshoe',
    'view.stable_inventory.page_1' => 'box',
    'user.page' => 'user',
    'user.logout' => 'logout',
    'user.login' => 'user',
  ];

  /**
   * Builds the hooks.
   *
   * @param \Drupal\Core\Routing\AdminContext $adminContext
   *   Tells admin routes (shown in the admin theme) apart.
   * @param \Drupal\Core\Routing\RouteProviderInterface $routeProvider
   *   The route provider.
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route, to mark its link.
   */
  public function __construct(
    private readonly AdminContext $adminContext,
    private readonly RouteProviderInterface $routeProvider,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_preprocess_menu__main().
   *
   * The app's pages swap in without a page load; each link gets an icon, and
   * the current page is marked for assistive technology.
   *
   * The current page's link is the one to its route, whatever its
   * parameters: the active trail would miss the overview of another month.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_menu__main')]
  public function preprocessMenuMain(array &$variables): void {
    $variables['attributes'] = AttributeHelper::mergeCollections($variables['attributes'] ?? [], AppNavigation::boost()->getAttributes()->toArray());
    $variables['#cache']['contexts'][] = 'route.name';
    $this->decorateItems($variables['items']);
  }

  /**
   * Implements hook_preprocess_menu__account().
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_menu__account')]
  public function preprocessMenuAccount(array &$variables): void {
    $this->decorateItems($variables['items']);
  }

  /**
   * Implements hook_preprocess_views_view_summary().
   *
   * A view's list of months (or other summary) swaps the page in place.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_views_view_summary')]
  public function preprocessViewsViewSummary(array &$variables): void {
    $variables['list_attributes'] = new Attribute(AppNavigation::boost()->getAttributes()->toArray());
    $variables['list_attributes']->addClass('views-summary');
  }

  /**
   * Implements hook_preprocess_pager().
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_pager')]
  public function preprocessPager(array &$variables): void {
    $variables['pager_attributes'] = new Attribute(AppNavigation::boost()->getAttributes()->toArray());
    $variables['pager_attributes']->addClass('pager');
  }

  /**
   * Adds icons and the current page to menu items, and unboosts admin links.
   *
   * @param array<string|int, array<string, mixed>> $items
   *   The menu items, with their children.
   */
  private function decorateItems(array &$items): void {
    foreach ($items as &$item) {
      $url = $item['url'] ?? NULL;
      if (!$url instanceof Url) {
        continue;
      }
      $route = $url->isRouted() ? $url->getRouteName() : NULL;
      $item['icon'] = self::ICONS[$route] ?? 'dot';
      $attributes = $url->getOption('attributes') ?? [];
      if (!empty($item['in_active_trail']) || ($route !== NULL && $route === $this->routeMatch->getRouteName())) {
        $attributes['aria-current'] = 'page';
      }
      if ($route !== NULL && $this->isAdminRoute($route)) {
        $attributes += AppNavigation::unboost()->getAttributes()->toArray();
      }
      $url->setOption('attributes', $attributes);
      if (!empty($item['below'])) {
        $this->decorateItems($item['below']);
      }
    }
  }

  /**
   * Whether a route is shown in the admin theme (so not in the app shell).
   */
  private function isAdminRoute(string $route_name): bool {
    try {
      return $this->adminContext->isAdminRoute($this->routeProvider->getRouteByName($route_name));
    }
    catch (RouteNotFoundException) {
      return FALSE;
    }
  }

}
