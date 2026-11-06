<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\FunctionalJavascript;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\node\NodeInterface;
use Drupal\Tests\shared_costs\Functional\Recipe\StableManagerTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Stable Manager theme in a browser: HTMX, motion, themes and a11y.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class StableManagerThemeTest extends WebDriverTestBase {

  use RecipeTestTrait;
  use StableManagerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stable_manager_theme';

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
   * A horse, with costs this month.
   */
  private NodeInterface $horse;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->applyRecipe($this->recipe('stable_manager'));
    $luna = $this->createHorse('Luna');
    $bella = $this->createHorse('Bella', 'livery');
    $now = new \DateTimeImmutable('now', new \DateTimeZone($this->config('system.date')->get('timezone.default')));
    $this->months['this'] = $now->modify('first day of this month');
    $this->months['last'] = $this->months['this']->modify('-1 month');
    $this->createExpense($this->months['last']->format('Y-m-d'), 'Vet check-up', 'vet', '12.35', [[$luna], [$bella]]);
    $this->createExpense($this->months['this']->format('Y-m-d'), 'Hay', 'feed', '100.00', [[$luna], [$bella]]);
    $date = $this->months['this']->format('Y-m-d');
    $this->expense = $this->createExpense($date, 'Riding lesson', 'lesson', '30.00', [[$luna]]);
    $this->horse = $luna;
    $this->drupalCreateNode([
      'type' => 'inventory_item',
      'title' => 'Straw',
      'field_cost_category' => 'bedding',
      'field_default_price' => '6.50',
      'field_unit' => 'bale',
    ]);
    $this->drupalLogin($this->createStableManager());
  }

  /**
   * Tests moving around: pages swap in with HTMX, history works.
   */
  public function testNavigation(): void {
    $this->drupalGet('stable');
    $this->markPage();
    $overview = $this->months['this']->format('F Y');

    // The menu: only the main content swaps, the active link follows.
    $this->clickNav('Overview');
    $this->assertSession()->waitForElement('css', '#app-nav a[aria-current="page"][href$="/stable/overview"]');
    $this->assertSession()->elementTextEquals('css', 'h1.page-title', $overview);
    $this->assertStringEndsWith('/stable/overview', $this->getUrl());
    $this->assertStringStartsWith($overview, (string) $this->getSession()->evaluateScript('document.title'));
    $this->assertPageNotReloaded();
    // Focus moves to the new content, for keyboard and screen reader users.
    $this->assertSame('main-content', $this->getSession()->evaluateScript('document.activeElement.id'));

    // A month from the list.
    $last = $this->months['last'];
    $this->getSession()->getPage()->clickLink($last->format('F Y'));
    $this->assertSession()->waitForElement('css', 'h1.page-title:not(:empty)');
    $this->waitFor(sprintf("document.querySelector('h1.page-title').textContent.trim() === %s", json_encode($last->format('F Y'))));
    $this->assertStringEndsWith('/stable/overview/' . $last->format('Ym'), $this->getUrl());
    // The stat card counts up to the month's total.
    $this->assertSession()->elementAttributeContains('css', '.stat-card__value', 'data-count-up', '12.35');
    $this->waitFor("document.querySelector('.stat-card__value').textContent.trim() === '€ 12.35'");
    $this->assertPageNotReloaded();

    // Back: HTMX restores the previous page.
    $this->getSession()->back();
    $this->waitFor(sprintf("document.querySelector('h1.page-title').textContent.trim() === %s", json_encode($overview)));
    $this->assertStringEndsWith('/stable/overview', $this->getUrl());
  }

  /**
   * Tests the live filters: results update as you type, the field keeps focus.
   */
  public function testLiveFilters(): void {
    $this->drupalGet('stable/expenses');
    $this->markPage();
    $field = $this->assertSession()->fieldExists('description');
    $field->focus();
    $field->setValue('lesson');
    $this->waitFor("document.querySelectorAll('#stable-results td.views-field-title').length === 1");
    $this->assertSame(['Riding lesson'], $this->expenseTitles());
    $this->assertStringContainsString('description=lesson', $this->getUrl());
    $this->assertSame('description', $this->getSession()->evaluateScript('document.activeElement.name'));
    $this->assertPageNotReloaded();

    // Picking a category.
    $field->setValue('');
    $this->getSession()->getPage()->selectFieldOption('category', 'vet');
    $this->waitFor("[...document.querySelectorAll('#stable-results td.views-field-title')].map((td) => td.textContent.trim()).join() === 'Vet check-up'");
    $this->assertStringContainsString('category=vet', $this->getUrl());
    $this->assertPageNotReloaded();
  }

  /**
   * Tests the theme toggle: dark and light, remembered, charts follow.
   */
  public function testThemeToggle(): void {
    $this->drupalGet('stable');
    $this->waitFor('window.Chart && Object.keys(window.Chart.instances).length === 2');
    $before = $this->chartColor();
    $start = $this->getSession()->evaluateScript('document.documentElement.dataset.theme');
    $other = $start === 'dark' ? 'light' : 'dark';

    $this->getSession()->getPage()->find('css', '[data-theme-toggle]')?->click();
    $this->waitFor(sprintf("document.documentElement.dataset.theme === '%s'", $other));
    $this->assertSession()->elementAttributeContains('css', '[data-theme-toggle]', 'aria-pressed', $other === 'dark' ? 'true' : 'false');
    $this->assertSame($other, $this->getSession()->evaluateScript("localStorage.getItem('stable-manager-theme')"));
    // The charts are redrawn in the other theme's colors.
    $this->waitFor(sprintf('%s !== %s', $this->chartColorScript(), json_encode($before)));

    // Remembered: the next page starts in it, before any JavaScript runs.
    $this->drupalGet('stable/expenses');
    $this->assertSame($other, $this->getSession()->evaluateScript('document.documentElement.dataset.theme'));
  }

  /**
   * Tests the charts: drawn in the theme's colors, with their numbers.
   */
  public function testCharts(): void {
    $this->drupalGet('stable');
    $this->waitFor('window.Chart && Object.keys(window.Chart.instances).length === 2');
    $charts = $this->getSession()->evaluateScript(<<<'JS'
      Object.values(window.Chart.instances)
        .sort((a, b) => a.id - b.id)
        .map((chart) => ({
          labels: chart.data.labels,
          values: chart.data.datasets[0].data,
          color: chart.data.datasets[0].backgroundColor,
          radius: chart.data.datasets[0].borderRadius,
        }))
      JS);
    $this->assertSame(['Bella', 'Luna'], $charts[0]['labels']);
    // Two colors, one per chart, resolved from the theme's custom properties.
    $this->assertNotSame($charts[0]['color'], $charts[1]['color']);
    foreach ($charts as $chart) {
      $this->assertMatchesRegularExpression('/^(oklch|rgb|color)\(/', $chart['color']);
      $this->assertSame(8, $chart['radius']);
    }
  }

  /**
   * Tests the stat card: it counts up to this month's costs.
   */
  public function testCountUp(): void {
    $this->drupalGet('stable');
    $this->assertSession()->elementAttributeContains('css', '.stat-card__value', 'data-count-up', '130');
    // While it counts, screen readers get the final value; after, the text.
    $this->waitFor("!document.querySelector('.stat-card__value').hasAttribute('aria-label')");
    $this->assertSession()->elementTextEquals('css', '.stat-card__value', '€ 130.00');
  }

  /**
   * Tests that the dashboard fits a desktop screen, and scrolls on a phone.
   */
  public function testDashboardFitsScreen(): void {
    // The window, not the page: the browser's own bars take some height.
    $this->getSession()->resizeWindow(1440, 1050);
    $this->drupalGet('stable');
    $this->assertGreaterThanOrEqual(768, $this->getSession()->evaluateScript('window.innerHeight'));
    $this->waitFor('window.Chart && Object.keys(window.Chart.instances).length === 2');
    $this->waitFor('document.documentElement.scrollHeight <= window.innerHeight');
    // The charts take the room the first row leaves.
    $heights = $this->getSession()->evaluateScript("[...document.querySelectorAll('.chart-box')].map((box) => box.getBoundingClientRect().height)");
    $this->assertCount(2, $heights);
    foreach ($heights as $height) {
      $this->assertGreaterThan(180, $height);
    }
    // Chart.js fills the box.
    $this->waitFor("[...document.querySelectorAll('.chart-box')].every((box) => Math.abs(box.querySelector('canvas').getBoundingClientRect().height - box.getBoundingClientRect().height) < 2)");

    // A phone: the page scrolls, the charts keep their height.
    $this->getSession()->resizeWindow(390, 844);
    $this->drupalGet('stable');
    $this->waitFor('window.Chart && Object.keys(window.Chart.instances).length === 2');
    $this->assertTrue($this->getSession()->evaluateScript('document.documentElement.scrollHeight > window.innerHeight'));
    $this->assertEqualsWithDelta(288, $this->getSession()->evaluateScript("document.querySelector('.chart-box').getBoundingClientRect().height"), 1);
  }

  /**
   * Tests the sidebar: it collapses to its icons, and stays collapsed.
   */
  public function testSidebar(): void {
    $this->drupalGet('stable');
    $toggle = $this->assertSession()->elementExists('css', '[data-sidebar-toggle]');
    $toggle->click();
    $this->assertSame('collapsed', $this->getSession()->evaluateScript('document.documentElement.dataset.sidebar'));
    $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
    $this->assertSession()->elementTextEquals('css', '[data-sidebar-toggle] .visually-hidden', 'Expand the sidebar');

    $this->drupalGet('stable/horses');
    $this->assertSame('collapsed', $this->getSession()->evaluateScript('document.documentElement.dataset.sidebar'));
    $this->assertSession()->elementAttributeContains('css', '[data-sidebar-toggle]', 'aria-expanded', 'false');

    // Logged out, the login form gets the whole page all the same.
    $this->logOut();
    $this->drupalGet('user/login');
    $this->assertSame('collapsed', $this->getSession()->evaluateScript('document.documentElement.dataset.sidebar'));
    $width = $this->getSession()->evaluateScript("document.querySelector('.app-main').getBoundingClientRect().width / window.innerWidth");
    $this->assertGreaterThan(0.9, $width);
  }

  /**
   * Tests the messages: they can be closed, and status messages go.
   */
  public function testMessages(): void {
    $this->drupalGet($this->expense->toUrl('edit-form'));
    $this->getSession()->getPage()->pressButton('Save');
    $message = $this->assertSession()->waitForElementVisible('css', '#app-toasts .messages--status');
    $this->assertNotNull($message);
    $message->find('css', '[data-message-close]')?->click();
    $this->assertTrue($this->assertSession()->waitForElementRemoved('css', '#app-toasts .messages--status'));

    // Untouched, a status message goes by itself.
    $this->drupalGet($this->expense->toUrl('edit-form'));
    $this->getSession()->getPage()->pressButton('Save');
    $this->assertNotNull($this->assertSession()->waitForElementVisible('css', '#app-toasts .messages--status'));
    $this->assertTrue($this->assertSession()->waitForElementRemoved('css', '#app-toasts .messages--status', 9000));
  }

  /**
   * Tests a horse's statement: its print button prints it, without the app.
   */
  public function testStatement(): void {
    $path = 'stable/statement/' . $this->horse->id();
    $this->drupalGet($path);
    $this->assertSession()->elementTextEquals('css', 'h1', 'Luna: ' . $this->months['this']->format('F Y'));
    $this->getSession()->executeScript('window.printed = 0; window.print = () => { window.printed += 1; };');
    $this->getSession()->getPage()->clickLink('Print');
    $this->assertSame(1, $this->getSession()->evaluateScript('window.printed'));
    $this->assertStringEndsWith($path, $this->getUrl());
    // On paper, the sidebar and the buttons stay off.
    $hidden = $this->getSession()->evaluateScript(<<<'JS'
      [...document.styleSheets]
        .filter((sheet) => sheet.media.mediaText === 'print')
        .flatMap((sheet) => [...sheet.cssRules])
        .filter((rule) => rule.style?.display === 'none')
        .flatMap((rule) => rule.selectorText.split(/,\s*/))
    JS);
    $this->assertContains('.app-sidebar', $hidden);
    $this->assertContains('.page-actions', $hidden);
  }

  /**
   * Tests every page with axe: no serious WCAG 2.1 A/AA issues, in both themes.
   */
  public function testAccessibility(): void {
    $axe = DRUPAL_ROOT . '/core/node_modules/axe-core/axe.min.js';
    $this->assertFileExists($axe, "axe-core comes with core's JavaScript tools: run yarn install in core.");
    $pages = [
      'stable',
      'stable/expenses',
      'stable/overview',
      'stable/horses',
      'stable/inventory',
      'stable/statement/' . $this->horse->id(),
      $this->expense->toUrl()->getInternalPath(),
    ];
    $problems = [];
    foreach (['light', 'dark'] as $theme) {
      foreach ($pages as $path) {
        $this->drupalGet($path);
        $this->getSession()->executeScript(sprintf("localStorage.setItem('stable-manager-theme', '%s'); document.documentElement.dataset.theme = '%s';", $theme, $theme));
        // Let the entrance animations finish: axe checks what is shown.
        $this->getSession()->wait(1500);
        $problems = array_merge($problems, $this->axe($axe, "$path ($theme)"));
      }
    }
    // Visitors' login page.
    $this->logOut();
    $this->drupalGet('user/login');
    $this->getSession()->wait(800);
    $problems = array_merge($problems, $this->axe($axe, 'user/login'));

    $this->assertSame([], $problems);
  }

  /**
   * Runs axe on the page.
   *
   * @return list<string>
   *   The serious and critical violations, described.
   */
  private function axe(string $axe, string $page): array {
    $this->getSession()->executeScript((string) file_get_contents($axe));
    $this->getSession()->executeScript(<<<'JS'
      window.axeResult = null;
      window.axe
        .run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } })
        .then((result) => { window.axeResult = result.violations; });
      JS);
    $this->waitFor('window.axeResult !== null', 30000);
    $violations = $this->getSession()->evaluateScript('window.axeResult');
    $problems = [];
    foreach ($violations as $violation) {
      if (in_array($violation['impact'], ['serious', 'critical'], TRUE)) {
        $targets = array_map(static fn (array $node): string => implode(' ', $node['target']), $violation['nodes']);
        $problems[] = sprintf('%s: %s (%s)', $page, $violation['id'], implode(', ', array_slice($targets, 0, 3)));
      }
    }
    return $problems;
  }

  /**
   * Logs out by dropping the session cookie (this browser's storage stays).
   *
   * Core's drupalLogout() checks the login form without waiting for the page
   * after "Log out", which can be too soon in a real browser.
   */
  private function logOut(): void {
    $this->getSession()->setCookie($this->getSessionName());
    $this->loggedInUser = FALSE;
    \Drupal::currentUser()->setAccount(new AnonymousUserSession());
  }

  /**
   * Clicks a link in the main navigation.
   */
  private function clickNav(string $label): void {
    $this->assertSession()->elementExists('named', ['link', $label], $this->assertSession()->elementExists('css', '#app-nav'))->click();
  }

  /**
   * Marks the page, to tell later whether it was loaded again.
   */
  private function markPage(): void {
    $this->getSession()->executeScript('window.stableManagerMarker = true;');
  }

  /**
   * Asserts that the marked page wasn't loaded again (HTMX swapped it).
   */
  private function assertPageNotReloaded(): void {
    $this->assertTrue($this->getSession()->evaluateScript('window.stableManagerMarker === true'), 'The page was not loaded again.');
  }

  /**
   * Waits for a JavaScript condition, and asserts it came true.
   */
  private function waitFor(string $condition, int $timeout = 10000): void {
    $this->assertTrue($this->getSession()->wait($timeout, $condition), "Waited for: $condition");
  }

  /**
   * Returns the titles of the expenses shown.
   *
   * @return list<string>
   *   The titles.
   */
  private function expenseTitles(): array {
    return array_values(array_map(
      static fn ($cell): string => $cell->getText(),
      $this->getSession()->getPage()->findAll('css', '#stable-results td.views-field-title'),
    ));
  }

  /**
   * Returns the script that reads the first chart's color.
   */
  private function chartColorScript(): string {
    return 'Object.values(window.Chart.instances).sort((a, b) => a.id - b.id)[0].data.datasets[0].backgroundColor';
  }

  /**
   * Returns the first chart's color.
   */
  private function chartColor(): string {
    return (string) $this->getSession()->evaluateScript($this->chartColorScript());
  }

}
