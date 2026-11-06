<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Functional\Recipe;

use Drupal\node\Entity\Node;
use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Stal Manager recipe: its pages, numbers, form and role.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class StalManagerRecipeTest extends GenericRecipeTestBase {

  use StalManagerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function getRecipePath(): string {
    return $this->recipe('stal_manager');
  }

  /**
   * Tests the dashboard, overview and expenses on costs of this month and last.
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
    $this->createExpense($this_month->format('Y-m-d'), 'Hay', 'feed', '100.00', [[$luna], [$bella]]);
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
    $this->assertEquals([
      ['labels' => ['Bella', 'Luna'], 'values' => $same_year ? [56.17, 86.18] : [50.0, 80.0]],
      ['labels' => array_keys($per_month), 'values' => array_values($per_month)],
    ], $this->charts());

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

    // Expenses, newest first, filtered by category.
    $this->drupalGet('stable/expenses', ['query' => ['category' => 'lesson']]);
    $this->assertSame(['Riding lesson'], array_column($this->tableRows('.stable-expenses'), 1));
    $this->assertSession()->pageTextContains('Luna · € 30.00');

    // Horses and inventory.
    $this->drupalGet('stable/horses');
    $this->assertSame([
      ['Bella', 'Livery horse', 'In the stable', 'Edit'],
      ['Luna', 'Own horse', 'In the stable', 'Edit'],
    ], $this->tableRows('.stable-horses'));
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
    $expense = Node::load(3);
    $this->assertInstanceOf(Node::class, $expense);
    $this->drupalGet($expense->toUrl('delete-form'));
    $this->assertSession()->statusCodeEquals(200);
  }

}
