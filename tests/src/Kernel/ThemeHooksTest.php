<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Kernel;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\stable_manager_theme\Hook\ChartsHooks;
use Drupal\stable_manager_theme\Hook\NavigationHooks;
use Drupal\stable_manager_theme\Hook\PageHooks;
use Drupal\stable_manager_theme\Hook\StableManagerThemeHooks;
use Drupal\stable_manager_theme\Hook\ViewsHooks;
use Drupal\stable_manager_theme\Htmx\AppNavigation;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Stable Manager theme's hooks: HTMX navigation, cards, stat cards.
 */
#[CoversClass(ChartsHooks::class)]
#[CoversClass(NavigationHooks::class)]
#[CoversClass(PageHooks::class)]
#[CoversClass(ViewsHooks::class)]
#[CoversClass(StableManagerThemeHooks::class)]
#[CoversClass(AppNavigation::class)]
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class ThemeHooksTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The modules that every site has (path_alias is required).
   */
  protected static $modules = ['system', 'user', 'path_alias'];

  /**
   * The horses.
   *
   * @var array<string, \Drupal\node\NodeInterface>
   */
  private array $horses = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    RecipeRunner::processRecipe(Recipe::createFromDirectory(dirname(__DIR__, 3) . '/recipes/stable_manager'));
    \Drupal::service('module_installer')->install(['block']);
    \Drupal::service('theme_installer')->install(['stable_manager_theme']);
    $this->config('system.theme')->set('default', 'stable_manager_theme')->save();
    $this->setUpCurrentUser([], ['access content', 'create expense content']);
    foreach (['Bella' => 'livery', 'Luna' => 'own'] as $name => $type) {
      $this->horses[$name] = Node::create(['type' => 'horse', 'title' => $name, 'field_horse_type' => $type]);
      $this->horses[$name]->save();
    }
  }

  /**
   * Tests the main menu: boosted for HTMX, with icons and the current page.
   */
  public function testMainMenu(): void {
    $this->goTo('/stable/overview/202611');
    $html = $this->renderBlock('system_menu_block:main');

    $boost = AppNavigation::boost()->getAttributes()->toArray();
    $this->assertSame('true', $boost['data-hx-boost']);
    $this->assertSame('#main-content', $boost['data-hx-select']);
    $this->assertSame('#main-content', $boost['data-hx-target']);
    $this->assertSame('#app-nav,#app-toasts', $boost['data-hx-select-oob']);
    $this->assertSame('outerHTML transition:true show:window:top', $boost['data-hx-swap']);
    $this->assertSame('#app-progress', $boost['data-hx-indicator']);
    foreach ($boost as $name => $value) {
      $this->assertStringContainsString(sprintf('%s="%s"', $name, $value), $html);
    }

    foreach (['dashboard', 'receipt', 'chart', 'horseshoe', 'box'] as $icon) {
      $this->assertStringContainsString('<use href="#icon-' . $icon . '">', $html);
    }
    // The overview of another month: its link is the current page anyway.
    $this->assertMatchesRegularExpression('#<a href="/stable/overview" aria-current="page"[^>]*>\s*<svg[^>]*>.*?</svg>\s*<span class="nav-label">Overview</span>#s', $html);
    $this->assertSame(1, substr_count($html, 'aria-current="page"'));
  }

  /**
   * Tests that admin links in a menu aren't boosted, and get icons.
   */
  public function testMenuItems(): void {
    $hooks = \Drupal::service(NavigationHooks::class);
    $this->assertInstanceOf(NavigationHooks::class, $hooks);
    $variables = [
      'items' => [
        'admin' => ['url' => Url::fromRoute('system.admin'), 'below' => []],
        'account' => [
          'url' => Url::fromRoute('user.page'),
          'in_active_trail' => TRUE,
          'below' => [
            'logout' => ['url' => Url::fromRoute('user.logout')],
          ],
        ],
        'external' => ['url' => Url::fromUri('https://example.com')],
        'gone' => ['url' => Url::fromRoute('<nolink>')],
        'none' => ['title' => 'No link'],
      ],
    ];
    $hooks->preprocessMenuAccount($variables);
    $items = $variables['items'];
    $this->assertSame('false', $items['admin']['url']->getOption('attributes')['data-hx-boost']);
    $this->assertSame('dot', $items['admin']['icon']);
    $this->assertSame('user', $items['account']['icon']);
    $this->assertSame('page', $items['account']['url']->getOption('attributes')['aria-current']);
    $this->assertArrayNotHasKey('data-hx-boost', $items['account']['url']->getOption('attributes'));
    $this->assertSame('logout', $items['account']['below']['logout']['icon']);
    $this->assertSame('dot', $items['external']['icon']);
    $this->assertSame('dot', $items['gone']['icon']);
    $this->assertArrayNotHasKey('icon', $items['none']);
  }

  /**
   * Tests the dashboard: a stat card that counts up, and cards.
   */
  public function testDashboard(): void {
    $this->createExpense(date('Y-m-01'), 'Hay', '1234.5');
    $this->goTo('/stable');
    $html = $this->renderView('stable_dashboard');

    $this->assertStringContainsString('stable-view stable-view--summary', $html);
    $this->assertMatchesRegularExpression('#<section data-component-id="stable_manager_theme:stat-card" class="stat-card">\s*<div class="stat-card__label"><h2>Costs this month</h2>#', $html);
    $this->assertMatchesRegularExpression('#<p class="stat-card__value" data-count-up="1234\.5" data-decimals="2" data-decimal-mark="\." data-thousands-mark="," data-prefix="€ " data-suffix="">.*€ 1,234\.50#s', $html);
    // The latest expenses and the two charts are cards; the table is flush.
    $this->assertSame(3, substr_count($html, 'data-component-id="stable_manager_theme:card"'));
    $this->assertStringContainsString('card card--flush', $html);
    $this->assertStringContainsString('<h2>Recently added</h2>', $html);
    $this->assertStringContainsString('<h2>Costs per horse this year</h2>', $html);
    // Each chart in a box of its own, which the theme sizes.
    $this->assertSame(2, preg_match_all('#<div class="chart-box"><div[^>]* class="charts-chartjs#', $html));
  }

  /**
   * Tests the overview: its stat card, cards and the boosted list of months.
   */
  public function testOverview(): void {
    $this->createExpense('2026-10-05', 'Hay', '48');
    $this->createExpense('2026-11-03', 'Hay', '32');
    $this->goTo('/stable/overview/202611');
    $html = $this->renderView('stable_overview', ['202611']);

    $this->assertMatchesRegularExpression('#<div class="stat-card__label">Total</div>\s*<p class="stat-card__value" data-count-up="32"#', $html);
    $this->assertMatchesRegularExpression('#<ul data-hx-boost="true"[^>]* class="views-summary">#', $html);
    $this->assertStringContainsString('href="/stable/overview/202610"', $html);
  }

  /**
   * Tests a horse's statement: its total, a print button and cards.
   */
  public function testStatement(): void {
    $this->createExpense('2026-10-05', 'Hay', '48');
    $this->createExpense('2026-11-03', 'Hay', '32');
    $luna = (string) $this->horses['Luna']->id();
    $this->goTo("/stable/statement/$luna/202611");
    $html = $this->renderView('stable_statement', [$luna, '202611']);

    $this->assertStringContainsString('stable-view stable-view--summary', $html);
    $this->assertMatchesRegularExpression('#<div class="page-actions"><p><a href="\#print" class="button" role="button" data-stable-print>Print</a></p>#', $html);
    $this->assertMatchesRegularExpression('#<div class="stat-card__label">Total</div>\s*<p class="stat-card__value" data-count-up="16"#', $html);
    $this->assertStringContainsString('<h2>Expenses</h2>', $html);
    $this->assertStringContainsString("href=\"/stable/statement/$luna/202610\"", $html);
  }

  /**
   * Tests a list page: actions, live filters and the results in a card.
   */
  public function testExpenses(): void {
    // More than a page (50), for the pager.
    for ($i = 1; $i <= 51; $i++) {
      $this->createExpense(sprintf('2026-10-%02d', 1 + $i % 28), "Expense $i", '10');
    }
    $this->goTo('/stable/expenses');
    $html = $this->renderView('stable_expenses');

    $this->assertStringContainsString('stable-view stable-view--list', $html);
    $this->assertMatchesRegularExpression('#<div class="page-actions">.*Add an expense#s', $html);
    // The CSV download goes with the results, so live filters update it too.
    $this->assertMatchesRegularExpression('#<div class="stable-results" id="stable-results">\s*<div class="stable-downloads">.*<a href="/stable/expenses/csv\?[^"]*_format=csv" title="Expenses" class="button" download>\s*Download CSV\s*</a>.*?</div>\s*</div>\s*<section[^>]* class="card card--flush">#s', $html);
    // The filters update the results as you type, and keep them in the URL.
    preg_match('#<form ([^>]*)>#', $html, $form);
    $this->assertNotEmpty($form);
    foreach ([
      'data-hx-get="/stable/expenses"',
      'data-hx-trigger="submit, input delay:350ms"',
      'data-hx-select="#stable-results"',
      'data-hx-target="#stable-results"',
      'data-hx-swap="outerHTML transition:true ignoreTitle:true"',
      'data-hx-push-url="true"',
      'data-hx-sync="this:replace"',
      'data-hx-indicator="#app-progress"',
      'stable-filters',
    ] as $attribute) {
      $this->assertStringContainsString($attribute, $form[1]);
    }
    // The pager moves through the pages without loading them.
    $this->assertMatchesRegularExpression('#<nav data-hx-boost="true"[^>]* class="pager"#', $html);
  }

  /**
   * Tests the parts of the page: the head, the classes and "Add an expense".
   */
  public function testPage(): void {
    $hooks = \Drupal::service(PageHooks::class);
    $this->assertInstanceOf(PageHooks::class, $hooks);

    $variables = ['attributes' => []];
    $hooks->preprocessHtml($variables);
    $head = array_column($variables['#attached']['html_head'], 1);
    $this->assertSame([
      'stable_manager_theme_preferences',
      'stable_manager_theme_color_light',
      'stable_manager_theme_color_dark',
    ], $head);
    $this->assertStringContainsString("localStorage.getItem('stable-manager-theme')", (string) $variables['#attached']['html_head'][0][0]['#value']);
    $this->assertSame(['is-authenticated'], $variables['attributes']['class']);

    $variables = [];
    $hooks->preprocessPage($variables);
    $this->assertSame('node.add', $variables['add_expense']['#url']->getRouteName());
    $this->assertContains('user.permissions', $variables['add_expense']['#cache']['contexts']);

    // Without the permission: nothing to add.
    $this->setUpCurrentUser([], ['access content']);
    $hooks = \Drupal::service(PageHooks::class);
    $this->assertInstanceOf(PageHooks::class, $hooks);
    $variables = [];
    $hooks->preprocessPage($variables);
    $this->assertArrayNotHasKey('#type', $variables['add_expense']);
    $this->assertContains('user.permissions', $variables['add_expense']['#cache']['contexts']);
  }

  /**
   * Tests that other views are left alone.
   */
  public function testOtherViews(): void {
    $hooks = \Drupal::service(ViewsHooks::class);
    $this->assertInstanceOf(ViewsHooks::class, $hooks);
    $view = \Drupal::service('entity_type.manager')->getStorage('view')->load('stable_horses')?->getExecutable();
    $this->assertNotNull($view);
    $view->storage->set('id', 'content');
    $variables = ['view' => $view];
    $hooks->preprocessViewsView($variables);
    $this->assertArrayNotHasKey('stable', $variables);
  }

  /**
   * Tests the Starterkit hook: image previews only for those who may see them.
   */
  public function testImageWidget(): void {
    $hooks = new StableManagerThemeHooks();
    $variables = ['data' => ['preview' => ['#access' => FALSE], 'file' => []]];
    $hooks->preprocessImageWidget($variables);
    $this->assertSame(['file' => []], $variables['data']);
    $variables = ['data' => ['preview' => ['#access' => TRUE]]];
    $hooks->preprocessImageWidget($variables);
    $this->assertArrayHasKey('preview', $variables['data']);
  }

  /**
   * Makes a path the current page, as the router matches it.
   */
  private function goTo(string $path): void {
    $request = Request::create($path);
    $request->attributes->add(\Drupal::service('router.no_access_checks')->matchRequest($request));
    $request->setSession(\Drupal::request()->getSession());
    \Drupal::requestStack()->push($request);
  }

  /**
   * Renders a block, in the theme.
   */
  private function renderBlock(string $plugin_id): string {
    $block = \Drupal::service('plugin.manager.block')->createInstance($plugin_id, []);
    $this->assertInstanceOf(BlockPluginInterface::class, $block);
    $build = $block->build();
    return (string) \Drupal::service('renderer')->renderInIsolation($build);
  }

  /**
   * Renders a view's page display, in the theme.
   *
   * @param string $id
   *   The view.
   * @param string[] $arguments
   *   Its arguments.
   */
  private function renderView(string $id, array $arguments = []): string {
    $build = ['#type' => 'view', '#name' => $id, '#display_id' => 'page_1', '#arguments' => $arguments];
    return (string) \Drupal::service('renderer')->renderInIsolation($build);
  }

  /**
   * Creates an expense shared by both horses.
   */
  private function createExpense(string $date, string $title, string $total): NodeInterface {
    $expense = Node::create([
      'type' => 'expense',
      'title' => $title,
      'field_expense_date' => $date,
      'field_cost_category' => 'feed',
      'field_total' => $total,
      'field_shared_with' => [
        ['target_id' => $this->horses['Luna']->id()],
        ['target_id' => $this->horses['Bella']->id()],
      ],
    ]);
    $expense->save();
    return $expense;
  }

}
