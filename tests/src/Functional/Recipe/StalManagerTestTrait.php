<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Functional\Recipe;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Helpers for the Stal Manager recipe tests.
 */
trait StalManagerTestTrait {

  /**
   * Returns the path of one of the module's recipes.
   */
  protected function recipe(string $name): string {
    return dirname(__DIR__, 4) . '/recipes/' . $name;
  }

  /**
   * Creates a horse.
   */
  protected function createHorse(string $name, string $type = 'own'): NodeInterface {
    $horse = Node::create(['type' => 'horse', 'title' => $name, 'field_horse_type' => $type]);
    $horse->save();
    return $horse;
  }

  /**
   * Creates an expense.
   *
   * @param string $date
   *   The date, as Y-m-d.
   * @param string $title
   *   What it was for.
   * @param string $category
   *   The category's key.
   * @param string $total
   *   The total.
   * @param array<int, array{0: \Drupal\node\NodeInterface, 1?: string}> $sharers
   *   The horses sharing it, each with an optional percentage.
   */
  protected function createExpense(string $date, string $title, string $category, string $total, array $sharers): NodeInterface {
    $expense = Node::create([
      'type' => 'expense',
      'title' => $title,
      'field_expense_date' => $date,
      'field_cost_category' => $category,
      'field_total' => $total,
      'field_shared_with' => array_map(static fn (array $sharer): array => [
        'target_id' => $sharer[0]->id(),
        'share' => $sharer[1] ?? NULL,
      ], $sharers),
    ]);
    $expense->save();
    return $expense;
  }

  /**
   * Creates a user with the recipe's stable manager role.
   */
  protected function createStableManager(): UserInterface {
    return $this->drupalCreateUser([], NULL, FALSE, ['roles' => ['stable_manager']]);
  }

  /**
   * Returns the data of the charts on the page.
   *
   * @return list<array{labels: list<string>, values: list<float>}>
   *   Per chart: its labels and its numbers.
   */
  protected function charts(): array {
    $charts = [];
    foreach ($this->getSession()->getPage()->findAll('css', '[data-chart]') as $element) {
      $chart = json_decode((string) $element->getAttribute('data-chart'), TRUE, flags: JSON_THROW_ON_ERROR);
      $charts[] = [
        'labels' => array_values(array_map('strval', $chart['data']['labels'])),
        'values' => array_values(array_map('floatval', $chart['data']['datasets'][0]['data'] ?? [])),
      ];
    }
    return $charts;
  }

  /**
   * Returns the rows of the CSV file that was downloaded.
   *
   * @return list<list<string>>
   *   The cells of each row, the header first.
   */
  protected function csv(): array {
    $content = $this->getSession()->getPage()->getContent();
    // Spreadsheets need the byte order mark to read UTF-8.
    $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
    $lines = preg_split('/\R/', trim(substr($content, 3))) ?: [];
    return array_map(static fn (string $line): array => array_map('strval', str_getcsv($line, escape: '')), $lines);
  }

  /**
   * Returns the cells of the tables on the page, row by row.
   *
   * @return list<list<string>>
   *   The text of each cell, per body row.
   */
  protected function tableRows(string $selector = 'table'): array {
    $rows = [];
    foreach ($this->getSession()->getPage()->findAll('css', "$selector tbody tr") as $row) {
      $rows[] = array_values(array_map(static fn ($cell): string => trim(preg_replace('/\s+/', ' ', $cell->getText()) ?? ''), $row->findAll('css', 'td')));
    }
    return $rows;
  }

}
