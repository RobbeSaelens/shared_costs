<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Functional\Theme;

use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\shared_costs\Functional\Recipe\StalManagerTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Stal Manager theme's pages, as they are served (without JS).
 *
 * What JavaScript adds (HTMX navigation, live filters, the theme toggle...) is
 * tested in the FunctionalJavascript test of the same name.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class StalManagerThemeTest extends BrowserTestBase {

  use RecipeTestTrait;
  use StalManagerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stal_manager_theme';

  /**
   * The first of this month and of last month, in the site's time zone.
   *
   * @var array<string, \DateTimeImmutable>
   */
  private array $months;

  /**
   * An expense of this month.
   */
  private NodeInterface $expense;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->applyRecipe($this->recipe('stal_manager'));
    $luna = $this->createHorse('Luna');
    $bella = $this->createHorse('Bella', 'livery');
    $now = new \DateTimeImmutable('now', new \DateTimeZone($this->config('system.date')->get('timezone.default')));
    $this->months['this'] = $now->modify('first day of this month');
    $this->months['last'] = $this->months['this']->modify('-1 month');
    $this->createExpense($this->months['last']->format('Y-m-d'), 'Vet check-up', 'vet', '12.35', [[$luna], [$bella]]);
    $this->createExpense($this->months['this']->format('Y-m-d'), 'Hay', 'feed', '100.00', [[$luna], [$bella]]);
    $date = $this->months['this']->format('Y-m-d');
    $this->expense = $this->createExpense($date, 'Riding lesson', 'lesson', '30.00', [[$luna, '100']]);
  }

  /**
   * Tests the app shell: landmarks, navigation, actions and the head.
   */
  public function testAppShell(): void {
    $this->drupalLogin($this->createStableManager());
    $this->drupalGet('stable');
    $assert = $this->assertSession();

    // Landmarks, and a way to skip to the content.
    $assert->elementAttributeContains('css', 'a.skip-link', 'href', '#main-content');
    $assert->elementExists('css', 'main#main-content.app-main[tabindex="-1"]');
    $assert->elementExists('css', 'aside.app-sidebar nav#block-stal-manager-theme-main-menu');
    $assert->elementExists('css', '#app-progress[aria-hidden="true"]');
    $assert->elementExists('css', '#app-toasts');
    $assert->elementExists('css', 'svg.icon-sprite symbol#icon-dashboard');

    // The branding links home, the logo is decorative next to the name.
    $assert->elementExists('css', 'a.site-branding[rel="home"] img.site-branding__logo[alt=""]');
    $assert->elementTextEquals('css', '.site-branding__name', $this->config('system.site')->get('name'));

    // The main navigation: boosted, an icon per link, the current page marked.
    $assert->elementExists('css', '#app-nav ul.menu[data-hx-boost="true"][data-hx-target="#main-content"]');
    $links = $this->getSession()->getPage()->findAll('css', '#app-nav a');
    $this->assertSame(['Dashboard', 'Expenses', 'Overview', 'Horses', 'Inventory'], array_map(static fn ($link): string => $link->getText(), $links));
    $this->assertCount(5, $this->getSession()->getPage()->findAll('css', '#app-nav a svg.icon use'));
    $assert->elementTextEquals('css', '#app-nav a[aria-current="page"]', 'Dashboard');

    // Another month's overview: still the overview's link.
    $this->drupalGet('stable/overview/' . $this->months['last']->format('Ym'));
    $assert->elementTextEquals('css', '#app-nav a[aria-current="page"]', 'Overview');
    $assert->elementTextEquals('css', 'h1.page-title', $this->months['last']->format('F Y'));

    // The sidebar: adding an expense, the theme toggle, the account links.
    $assert->elementAttributeContains('css', 'a.app-add', 'href', '/node/add/expense');
    $assert->elementExists('css', 'button.theme-toggle[data-theme-toggle][aria-pressed="false"]');
    $assert->elementExists('css', 'button.app-sidebar__collapse[data-sidebar-toggle][aria-expanded="true"]');
    $assert->elementExists('css', '.app-sidebar__bottom a use[href="#icon-logout"]');

    // The head sets the theme before the page paints.
    $html = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString("localStorage.getItem('stal-manager-theme')", $html);
    $assert->elementExists('css', 'meta[name="theme-color"][media="(prefers-color-scheme: light)"]');
    $assert->elementExists('css', 'meta[name="theme-color"][media="(prefers-color-scheme: dark)"]');
    $assert->elementExists('css', 'body.is-authenticated');
  }

  /**
   * Tests the dashboard and the overview: stat cards and cards.
   */
  public function testSummaries(): void {
    $this->drupalLogin($this->createStableManager());
    $this->drupalGet('stable');
    $assert = $this->assertSession();
    $assert->elementAttributeContains('css', '.stat-card .stat-card__value', 'data-count-up', '130');
    $assert->elementTextEquals('css', '.stat-card .stat-card__value', '€ 130.00');
    $assert->elementTextEquals('css', '.stat-card__label', 'Costs this month');
    $this->assertCount(3, $this->getSession()->getPage()->findAll('css', '.card-grid .card'));
    $assert->elementTextEquals('css', '.card--flush .card__header h2', 'Recently added');
    $assert->elementAttributeContains('css', '.page-actions a', 'href', '/node/add/expense');

    $this->drupalGet('stable/overview');
    $assert->elementTextEquals('css', '.stat-card__label', 'Total');
    $assert->elementTextEquals('css', '.stat-card__value', '€ 130.00');
    $assert->elementExists('css', 'ul.views-summary[data-hx-boost="true"] a[href$="/stable/overview/' . $this->months['last']->format('Ym') . '"]');
  }

  /**
   * Tests the filters without JavaScript: the form still works.
   */
  public function testFilters(): void {
    $this->drupalLogin($this->createStableManager());
    $this->drupalGet('stable/expenses');
    $assert = $this->assertSession();
    $assert->elementExists('css', 'form.stable-filters[data-hx-get][data-hx-target="#stable-results"]');
    $assert->elementExists('css', '#stable-results .card--flush table.stable-expenses');

    $this->submitForm(['category' => 'lesson'], 'Apply');
    $this->assertSame(['Riding lesson'], array_map(
      static fn ($cell): string => $cell->getText(),
      $this->getSession()->getPage()->findAll('css', '#stable-results td.views-field-title'),
    ));
  }

  /**
   * Tests an expense's page, and the messages after saving one.
   */
  public function testExpense(): void {
    $this->drupalLogin($this->createStableManager());
    $this->drupalGet($this->expense->toUrl());
    $assert = $this->assertSession();
    $assert->elementExists('css', 'article.node--view-mode-full');
    $assert->elementTextEquals('css', '.tabs .is-active > a', 'View');
    $assert->pageTextContains($this->months['this']->format('j F Y'));
    $assert->pageTextContains('Luna · 100 % · € 30.00');

    // Messages float in a corner, and can be closed.
    $this->drupalGet($this->expense->toUrl('edit-form'));
    $this->submitForm([], 'Save');
    $assert->elementTextContains('css', '#app-toasts .messages--status', 'Expense Riding lesson has been updated.');
    $assert->elementExists('css', '#app-toasts .messages--status button.messages__close[data-message-close]');
  }

  /**
   * Tests the page for visitors: the login form, without the app.
   */
  public function testAnonymous(): void {
    $this->drupalGet('stable');
    $assert = $this->assertSession();
    $assert->statusCodeEquals(403);
    $assert->elementExists('css', 'body.is-anonymous');
    $assert->elementNotExists('css', 'a.app-add');
    $assert->elementNotExists('css', '#app-nav a');

    $this->drupalGet('user/login');
    $assert->elementExists('css', 'body.is-anonymous form#user-login-form');
    $assert->elementExists('css', 'a.site-branding img.site-branding__logo');
  }

}
