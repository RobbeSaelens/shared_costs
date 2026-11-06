<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\shared_costs\Controller\SplitPreviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the live amounts the table widget asks for (HTMX).
 */
#[CoversClass(SplitPreviewController::class)]
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class SplitPreviewTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['shared_costs', 'system'];

  /**
   * Tests the amounts per row, as out-of-band swaps for the outputs.
   *
   * @param array<string, mixed> $query
   *   The request: rows, total and options, as the widget sends them.
   * @param array<string, string> $expected
   *   The text per output ID.
   */
  #[DataProvider('providerPreview')]
  public function testPreview(array $query, array $expected): void {
    $base = ['field' => 'field_shared_with', 'total' => 'field_total'];
    $response = (new SplitPreviewController())(Request::create('/shared-costs/split-preview', 'GET', $query + $base));
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));

    preg_match_all('#<output id="([^"]+)" hx-swap-oob="true">([^<]*)</output>#', (string) $response->getContent(), $matches);
    $this->assertSame($expected, array_combine($matches[1], $matches[2]));
  }

  /**
   * Data for testPreview().
   *
   * @return array<string, array{array<string, mixed>, array<string, string>}>
   *   The request, and the expected text per output.
   */
  public static function providerPreview(): array {
    $total = static fn (string $value): array => ['field_total' => [['value' => $value]]];
    // The rows by sharer ID: a share ('' for an equal part), or NULL for a
    // row that isn't ticked.
    $row = static fn (?string $share): array => $share === NULL
      ? ['share' => '']
      : ['selected' => '1', 'share' => $share];
    $rows = static fn (array $shares): array => ['field_shared_with' => array_map($row, $shares)];
    // The expected text of each row's output, by sharer ID.
    $amounts = static fn (array $expected): array => array_combine(
      array_map(static fn (int $id): string => "shared-costs-field-shared-with-$id", array_keys($expected)),
      $expected,
    );
    return [
      'equal halves' => [
        $rows([1 => '', 2 => '']) + $total('12.35'),
        $amounts([1 => '6.18', 2 => '6.17']),
      ],
      'percentages, with a decimal comma' => [
        $rows([1 => '70', 2 => '30']) + ['decimal' => ','] + $total('140,00'),
        $amounts([1 => '98,00', 2 => '42,00']),
      ],
      'a row that is not ticked gets nothing' => [
        $rows([1 => '', 2 => NULL]) + $total('10'),
        $amounts([1 => '10.00', 2 => '']),
      ],
      'a read-only row comes along in "locked"' => [
        $rows([1 => '']) + ['locked' => '{"7":"40"}'] + $total('10'),
        $amounts([1 => '6.00', 7 => '4.00']),
      ],
      'shares that do not add up: nothing yet' => [
        $rows([1 => '50', 2 => '30']) + $total('140'),
        $amounts([1 => '', 2 => '']),
      ],
      'more than 100 %: nothing yet' => [
        $rows([1 => '120', 2 => '']) + $total('140'),
        $amounts([1 => '', 2 => '']),
      ],
      'a share that is not a number is open' => [
        $rows([1 => 'half', 2 => '25']) + $total('8'),
        $amounts([1 => '6.00', 2 => '2.00']),
      ],
      'no total yet' => [
        $rows([1 => '']) + $total(''),
        $amounts([1 => '']),
      ],
      'nothing ticked' => [
        $rows([1 => NULL]) + $total('10'),
        $amounts([1 => '']),
      ],
    ];
  }

  /**
   * Tests that field names are checked before they are used.
   */
  public function testInvalidFieldName(): void {
    $request = Request::create('/shared-costs/split-preview', 'GET', [
      'field' => 'field"><script>',
      'total' => 'field_total',
    ]);
    $this->assertSame(400, (new SplitPreviewController())($request)->getStatusCode());
  }

}
