<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Functional\Recipe;

use Drupal\node\Entity\Node;
use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Stal Manager recipe: its pages, numbers, form and role.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class StalManagerRecipeTest extends GenericRecipeTestBase {

  use StalManagerTestTrait;
  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected function getRecipePath(): string {
    return $this->recipe('stal_manager');
  }

  /**
   * Tests the pages, on costs of this month and last.
   *
   * The dashboard, the overview, the statements, the expenses (and their CSV)
   * and the horses.
   */
  public function testPages(): void {
    $this->doApply();
    $luna = $this->createHorse('Luna');
    $bella = $this->createHorse('Bella', 'livery');
    // Dates in the site's time zone, as the views work them out.
    $now = new \DateTimeImmutable('now', new \DateTimeZone($this->config('system.date')->get('timezone.default')));
    $this_month = $now->modify('first day of this month');
    $last_month = $this_month->modify('-1 month');
    $this->createExpense($last_month->format('Y-m-15'), 'Vet check-up', 'vet', '12.35', [[$luna], [$bella]]);
    $hay = $this->createExpense($this_month->format('Y-m-d'), 'Hay', 'feed', '100.00', [[$luna], [$bella]]);
    $this->createExpense($this_month->format('Y-m-d'), 'Riding lesson', 'lesson', '30.00', [[$luna]]);

    // Only for stable managers.
    $this->drupalGet('stable');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->createStableManager());

    // Dashboard: this month's total, the latest, and the two charts.
    $this->drupalGet('stable');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextEquals('css', '.stable-total', '€ 130.00');
    $this->assertSame(['Riding lesson', 'Hay', 'Vet check-up'], array_column($this->tableRows('.stable-latest'), 0));
    // This year: last month's costs count too, unless that was last year.
    $same_year = $last_month->format('Y') === $this_month->format('Y');
    $per_month = [$this_month->format('M') => 130.0];
    if ($same_year) {
      $per_month = [$last_month->format('M') => 12.35] + $per_month;
    }
    $charts = [
      'expected' => [
        ['labels' => ['Bella', 'Luna'], 'values' => $same_year ? [56.17, 86.18] : [50.0, 80.0]],
        ['labels' => array_keys($per_month), 'values' => array_values($per_month)],
      ],
      'actual' => $this->charts(),
    ];

    // Overview of this month: per category and per horse.
    $this->drupalGet('stable/overview');
    $this->assertSession()->elementTextEquals('css', 'h1', $this_month->format('F Y'));
    $this->assertSession()->linkExists($last_month->format('F Y'));
    $this->assertSession()->pageTextContains('€ 130.00');
    $this->assertSame([['Feed', '1', '€ 100.00'], ['Lesson', '1', '€ 30.00']], $this->tableRows('.stable-per-category'));
    $this->assertSame([['Bella', '€ 50.00'], ['Luna', '€ 80.00']], $this->tableRows('.stable-per-horse'));

    // Last month, picked from the list: an equal split, to the cent.
    $this->clickLink($last_month->format('F Y'));
    $this->assertSession()->addressEquals('stable/overview/' . $last_month->format('Ym'));
    $this->assertSame([['Vet', '1', '€ 12.35']], $this->tableRows('.stable-per-category'));
    $this->assertSame([['Bella', '€ 6.17'], ['Luna', '€ 6.18']], $this->tableRows('.stable-per-horse'));

    // A horse's statement, from the overview: its part of each expense of the
    // month, its total, and the other months to pick from.
    $this->clickLink('Luna');
    $this->assertSession()->addressEquals('stable/statement/' . $luna->id() . '/' . $last_month->format('Ym'));
    $this->assertSession()->elementTextEquals('css', 'h1', 'Luna: ' . $last_month->format('F Y'));
    $this->assertSession()->elementTextEquals('css', '.stable-total', '€ 6.18');
    $this->assertSame([
      [$last_month->format('15 M Y'), 'Vet check-up', 'Vet', '€ 12.35', '€ 6.18'],
    ], $this->tableRows('.stable-statement'));
    $this->clickLink($this_month->format('F Y'));
    $this->assertSession()->addressEquals('stable/statement/' . $luna->id() . '/' . $this_month->format('Ym'));
    $this->assertSession()->elementTextEquals('css', '.stable-total', '€ 80.00');
    $first = $this_month->format('j M Y');
    $this->assertSame([
      [$first, 'Hay', 'Feed', '€ 100.00', '€ 50.00'],
      [$first, 'Riding lesson', 'Lesson', '€ 30.00', '€ 30.00'],
    ], $this->tableRows('.stable-statement'));
    // A month without costs for the horse, and something that isn't a horse.
    $this->drupalGet('stable/statement/' . $bella->id() . '/' . $this_month->modify('-2 years')->format('Ym'));
    $this->assertSession()->elementTextEquals('css', '.stable-total', '€ 0.00');
    $this->assertSession()->pageTextContains('No costs for this horse in this month.');
    $this->drupalGet('stable/statement/' . $hay->id());
    $this->assertSession()->statusCodeEquals(404);

    // Expenses, newest first, filtered by category.
    $this->drupalGet('stable/expenses', ['query' => ['category' => 'lesson']]);
    $this->assertSame(['Riding lesson'], array_column($this->tableRows('.stable-expenses'), 1));
    $this->assertSession()->pageTextContains('Luna · € 30.00');

    // The same expenses as CSV: plain numbers, for a spreadsheet.
    $this->clickLink('Download CSV');
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
    $this->assertSession()->responseHeaderContains('Content-Disposition', 'expenses-' . $now->format('Y-m-d') . '.csv');
    $this->assertSame([
      ['Date', 'Expense', 'Category', 'Total', 'Shared by'],
      [$this_month->format('Y-m-d'), 'Riding lesson', 'Lesson', '30.00', 'Luna · 30.00'],
    ], $this->csv());
    $this->drupalGet('stable/expenses/csv');
    $this->assertSame(['Riding lesson', 'Hay', 'Vet check-up'], array_column(array_slice($this->csv(), 1), 1));
    $this->assertContains('Luna · 6.18; Bella · 6.17', array_column($this->csv(), 4));

    // Horses, with their statement of this month, and inventory.
    $this->drupalGet('stable/horses');
    $this->assertSame([
      ['Bella', 'Livery horse', 'In the stable', 'Statement', 'Edit'],
      ['Luna', 'Own horse', 'In the stable', 'Statement', 'Edit'],
    ], $this->tableRows('.stable-horses'));
    $this->clickLink('Statement');
    $this->assertSession()->elementTextEquals('css', 'h1', 'Bella: ' . $this_month->format('F Y'));

    // The dashboard's charts, checked last: Charts 5.2.4 draws none on
    // Drupal 12 yet, and the rest is worth checking there.
    $this->assertEquals($charts['expected'], $charts['actual']);
    $this->drupalGet('stable/inventory');
    $this->assertSession()->pageTextContains('Nothing in the inventory yet.');
  }

  /**
   * Tests adding an expense as a stable manager, and what the role may do.
   */
  public function testStableManager(): void {
    $this->doApply();
    $luna = $this->createHorse('Luna');
    $bella = $this->createHorse('Bella', 'livery');
    $straw = $this->drupalCreateNode([
      'type' => 'inventory_item',
      'title' => 'Straw',
      'field_cost_category' => 'bedding',
      'field_default_price' => '6.50',
      'field_unit' => 'bale',
    ]);
    $this->drupalLogin($this->createStableManager());

    // From the inventory: the expense form, filled in with the item.
    $this->drupalGet('stable/inventory');
    $this->clickLink('Add as expense');
    $this->assertSession()->fieldValueEquals('title[0][value]', 'Straw');
    $this->assertSession()->fieldValueEquals('field_inventory_item', (string) $straw->id());
    $this->assertSession()->fieldValueEquals('field_cost_category', 'bedding');
    $this->assertSession()->fieldValueEquals('field_quantity[0][value]', '1');
    $this->assertSession()->fieldValueEquals('field_unit_price[0][value]', '6.50');
    $this->assertSession()->fieldValueEquals('field_total[0][value]', '6.50');
    // With a receipt, which only those who can see the expense can open.
    /** @var \stdClass $receipt */
    $receipt = $this->getTestFiles('image')[0];
    $this->submitForm([
      'field_expense_date[0][value][date]' => '2026-11-06',
      'field_quantity[0][value]' => '4',
      'field_total[0][value]' => '26.00',
      "field_shared_with[{$luna->id()}][selected]" => TRUE,
      'files[field_receipt_0]' => (string) \Drupal::service('file_system')->realpath($receipt->uri),
    ], 'Save');
    $this->assertSession()->pageTextContains('Expense Straw has been created.');
    $this->assertSession()->pageTextContains('Luna · 100 % · € 26.00');
    $link = $this->assertSession()->elementExists('css', 'a[href*="/system/files/receipts/"]');
    $href = (string) $link->getAttribute('href');
    $this->assertStringContainsString('/system/files/receipts/' . date('Y-m') . '/', $href);
    $this->drupalGet($href);
    $this->assertSession()->statusCodeEquals(200);
    // Visitors who can't see the costs (as on the demo site) can't open it.
    user_role_revoke_permissions(RoleInterface::ANONYMOUS_ID, ['access content']);
    $this->drupalLogout();
    $this->drupalGet($href);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->createStableManager());

    $this->drupalGet('stable');
    $this->clickLink('Add an expense');
    $this->submitForm([
      'field_expense_date[0][value][date]' => '2026-11-06',
      'title[0][value]' => 'Hay',
      'field_cost_category' => 'feed',
      'field_total[0][value]' => '45.00',
      "field_shared_with[{$luna->id()}][selected]" => TRUE,
      "field_shared_with[{$luna->id()}][share]" => '60',
      "field_shared_with[{$bella->id()}][selected]" => TRUE,
    ], 'Save');
    $this->assertSession()->pageTextContains('Expense Hay has been created.');
    $this->assertSession()->pageTextContains('Luna · 60 % · € 27.00');
    $this->assertSession()->pageTextContains('Bella · 40 % · € 18.00');

    // Horses that leave are unpublished, never deleted: their costs stay.
    $this->drupalGet($luna->toUrl('edit-form'));
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet($luna->toUrl('delete-form'));
    $this->assertSession()->statusCodeEquals(403);
    $expense = Node::load(4);
    $this->assertInstanceOf(Node::class, $expense);
    $this->drupalGet($expense->toUrl('delete-form'));
    $this->assertSession()->statusCodeEquals(200);
  }

}
