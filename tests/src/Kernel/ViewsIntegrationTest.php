<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\shared_costs\Hook\SharedCostsHooks;
use Drupal\shared_costs\Plugin\views\argument\YearMonth as YearMonthArgument;
use Drupal\shared_costs\Plugin\views\field\YearMonth;
use Drupal\Tests\shared_costs\Traits\SharedCostsTestTrait;
use Drupal\Tests\views\Kernel\ViewsKernelTestBase;
use Drupal\views\Tests\ViewTestData;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests adding costs up in Views: per sharer, and per month.
 *
 * Runs on every database in CI: grouping is where MySQL, PostgreSQL and SQLite
 * differ.
 */
#[CoversClass(SharedCostsHooks::class)]
#[CoversClass(YearMonth::class)]
#[CoversClass(YearMonthArgument::class)]
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class ViewsIntegrationTest extends ViewsKernelTestBase {

  use SharedCostsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'datetime',
    'entity_test',
    'field',
    'shared_costs',
    'shared_costs_test',
    'user',
  ];

  /**
   * Views to import from the shared_costs_test module.
   *
   * @var string[]
   */
  public static $testViews = ['test_shared_costs_per_sharer', 'test_shared_costs_per_month'];

  /**
   * {@inheritdoc}
   */
  protected function setUp($import_test_views = TRUE): void {
    // The fields first: the test views use them.
    parent::setUp(FALSE);
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
  }

  /**
   * Sets up the fields, content and test views.
   *
   * @param string $datetime_type
   *   The date field's type: 'date' or 'datetime'.
   */
  protected function createCostsAndViews(string $datetime_type = 'date'): void {
    $this->createSharedCostsFields();
    $this->createDateField($datetime_type);
    $luna = $this->createSharer('Luna');
    $bella = $this->createSharer('Bella');
    $time = $datetime_type === 'date' ? '' : 'T12:00:00';
    $rows = [
      ['2026-09-03', '100.00', [[$luna], [$bella]]],
      ['2026-09-20', '12.35', [[$luna], [$bella]]],
      ['2026-10-05', '140.00', [[$luna, 70], [$bella, 30]]],
      ['2026-10-31', '35.00', [[$luna]]],
      ['2026-11-01', '8.00', [[$bella]]],
    ];
    foreach ($rows as [$date, $total, $sharers]) {
      $costs = $this->createCosts($total, $sharers);
      $costs->set('field_date', $date . $time);
      $costs->save();
    }
    ViewTestData::createTestViews(static::class, ['shared_costs_test']);
  }

  /**
   * Tests the Views data: relationships, argument and number fields.
   */
  public function testViewsData(): void {
    $this->createCostsAndViews();
    $data = \Drupal::service('views.views_data');

    $field = $data->get('entity_test__field_shared_with');
    $this->assertSame('standard', $field['field_shared_with']['relationship']['id']);
    $this->assertSame('entity_test', $field['field_shared_with']['relationship']['base']);
    $this->assertSame('field_shared_with_target_id', $field['field_shared_with']['relationship']['relationship field']);
    $this->assertSame('entity_target_id', $field['field_shared_with_target_id']['argument']['id']);
    $this->assertSame('numeric', $field['field_shared_with_amount']['field']['id']);
    $this->assertSame('numeric', $field['field_shared_with_share']['field']['id']);

    $reverse = $data->get('entity_test')['reverse__entity_test__field_shared_with']['relationship'];
    $this->assertSame('entity_reverse', $reverse['id']);
    $this->assertSame('entity_test__field_shared_with', $reverse['field table']);

    $this->assertSame('numeric', $data->get('entity_test__field_total')['field_total_value']['field']['id']);
    $this->assertSame('shared_costs_year_month', $data->get('entity_test__field_date')['field_date_year_month']['field']['id']);
    $this->assertSame('shared_costs_year_month', $data->get('entity_test__field_date')['field_date_year_month']['argument']['id']);
    $this->assertSame('field_date_value', $data->get('entity_test__field_date')['field_date_year_month']['argument']['field']);
  }

  /**
   * Tests adding up the amounts per sharer, through the relationship.
   */
  public function testPerSharer(): void {
    $this->createCostsAndViews();
    $view = $this->runView('test_shared_costs_per_sharer');
    $sums = [];
    foreach ($view->result as $row) {
      $sums[(string) $view->field['name']->getValue($row)] = $this->cents($view->field['field_shared_with_amount']->getValue($row));
    }
    // Luna: 50 + 6.18 + 98 + 35; Bella: 50 + 6.17 + 42 + 8. Together: all.
    $this->assertSame(['Bella' => 10617, 'Luna' => 18918], $sums);
    $this->assertSame(29535, array_sum($sums), 'The parts add up to the total of all costs.');
  }

  /**
   * Tests adding up the totals per month, oldest first, with month names.
   */
  #[DataProvider('providerDateTypes')]
  public function testPerMonth(string $datetime_type): void {
    $this->createCostsAndViews($datetime_type);
    $view = $this->runView('test_shared_costs_per_month');
    $renderer = \Drupal::service('renderer');
    $months = $renderer->executeInRenderContext(new RenderContext(), function () use ($view): array {
      $months = [];
      foreach ($view->result as $row) {
        $label = (string) $view->field['field_date_year_month']->render($row);
        $months[$label] = $this->cents($view->field['field_total_value']->getValue($row));
      }
      return $months;
    });
    $this->assertSame(['Sep 2026' => 11235, 'Oct 2026' => 17500, 'Nov 2026' => 800], $months);
  }

  /**
   * Data for testPerMonth().
   *
   * @return array<string, array{string}>
   *   The date field's type.
   */
  public static function providerDateTypes(): array {
    return [
      'date only' => ['date'],
      'date and time' => ['datetime'],
    ];
  }

  /**
   * Tests the month field's formats, sort orders and click sorting.
   */
  public function testYearMonthOptions(): void {
    $this->createCostsAndViews();

    // Newest first, as stored (Y-m).
    $view = Views::getView('test_shared_costs_per_month');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $view->setDisplay();
    $display = $view->displayHandlers->get('default');
    $fields = $display->getOption('fields');
    $fields['field_date_year_month'] = ['format' => 'Y-m', 'order' => 'DESC'] + $fields['field_date_year_month'];
    $display->overrideOption('fields', $fields);
    $this->runView($view);
    $this->assertSame(['2026-11', '2026-10', '2026-09'], $this->renderedMonths($view));

    // No sort of its own: a table sorted by the month (click sorting).
    $view = Views::getView('test_shared_costs_per_month');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $view->setDisplay();
    $display = $view->displayHandlers->get('default');
    $fields = $display->getOption('fields');
    $fields['field_date_year_month'] = ['format' => 'Y-m', 'order' => ''] + $fields['field_date_year_month'];
    $display->overrideOption('fields', $fields);
    $display->overrideOption('style', [
      'type' => 'table',
      'options' => [
        'columns' => ['field_date_year_month' => 'field_date_year_month', 'field_total_value' => 'field_total_value'],
        'info' => ['field_date_year_month' => ['sortable' => TRUE, 'default_sort_order' => 'desc']],
        'default' => 'field_date_year_month',
        'order' => 'desc',
      ],
    ]);
    $this->runView($view);
    $this->assertSame(['2026-11', '2026-10', '2026-09'], $this->renderedMonths($view));
  }

  /**
   * Tests that the month field shows nothing for an unexpected value.
   */
  public function testYearMonthUnexpectedValue(): void {
    $this->createCostsAndViews();
    $view = $this->runView('test_shared_costs_per_month');
    $row = $view->result[0];
    $row->{$view->field['field_date_year_month']->field_alias} = NULL;
    $this->assertSame('', $view->field['field_date_year_month']->render($row));
  }

  /**
   * Tests the month argument: one month's costs, titled by the month's name.
   */
  #[DataProvider('providerDateTypes')]
  public function testMonthArgument(string $datetime_type): void {
    $this->createCostsAndViews($datetime_type);

    // One month: only its costs, and its name as the title.
    $view = $this->monthView();
    $view->setArguments(['202610']);
    $this->runView($view);
    $this->assertSame(['Oct 2026'], $this->renderedMonths($view));
    $this->assertSame(17500, $this->cents($view->field['field_total_value']->getValue($view->result[0])));
    $this->assertSame('October 2026', (string) $view->argument['field_date_year_month']->getTitle());

    // No month: the months to pick from, by name.
    $view = $this->monthView();
    $this->runView($view);
    $argument = $view->argument['field_date_year_month'];
    $this->assertInstanceOf(YearMonthArgument::class, $argument);
    $this->assertSame(['September 2026', 'October 2026', 'November 2026'], array_map(static fn ($row): string => (string) $argument->summaryName($row), $view->result));

    // Something that isn't a month is shown as it is.
    $argument->argument = '2026';
    $this->assertSame('2026', (string) $argument->title());
  }

  /**
   * Returns the per-month test view, with a month argument.
   */
  protected function monthView(): ViewExecutable {
    $view = Views::getView('test_shared_costs_per_month');
    $this->assertInstanceOf(ViewExecutable::class, $view);
    $view->setDisplay();
    $view->displayHandlers->get('default')->overrideOption('arguments', [
      'field_date_year_month' => [
        'id' => 'field_date_year_month',
        'table' => 'entity_test__field_date',
        'field' => 'field_date_year_month',
        'plugin_id' => 'shared_costs_year_month',
        'default_action' => 'summary',
        'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
      ],
    ]);
    return $view;
  }

  /**
   * Executes a test view.
   */
  protected function runView(string|ViewExecutable $view): ViewExecutable {
    if (is_string($view)) {
      $view = Views::getView($view);
      $this->assertInstanceOf(ViewExecutable::class, $view);
    }
    $view->execute();
    return $view;
  }

  /**
   * Renders the month of every result row.
   *
   * @return string[]
   *   The months, in result order.
   */
  protected function renderedMonths(ViewExecutable $view): array {
    return array_map(static fn ($row): string => (string) $view->field['field_date_year_month']->render($row), $view->result);
  }

  /**
   * Turns a summed decimal (string or float, per database) into cents.
   */
  protected function cents(mixed $value): int {
    $this->assertIsNumeric($value);
    return (int) round((float) $value * 100);
  }

}
