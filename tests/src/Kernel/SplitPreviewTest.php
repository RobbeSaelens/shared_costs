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
    return [
      'equal halves' => [
        ['field_shared_with' => [1 => ['selected' => '1', 'share' => ''], 2 => ['selected' => '1', 'share' => '']]] + $total('12.35'),
        ['shared-costs-field-shared-with-1' => '6.18', 'shared-costs-field-shared-with-2' => '6.17'],
      ],
      'percentages, with a decimal comma' => [
        ['field_shared_with' => [1 => ['selected' => '1', 'share' => '70'], 2 => ['selected' => '1', 'share' => '30']], 'decimal' => ','] + $total('140,00'),
        ['shared-costs-field-shared-with-1' => '98,00', 'shared-costs-field-shared-with-2' => '42,00'],
      ],
      'a row that is not ticked gets nothing' => [
        ['field_shared_with' => [1 => ['selected' => '1', 'share' => ''], 2 => ['share' => '']]] + $total('10'),
        ['shared-costs-field-shared-with-1' => '10.00', 'shared-costs-field-shared-with-2' => ''],
      ],
      'a read-only row comes along in "locked"' => [
        ['field_shared_with' => [1 => ['selected' => '1', 'share' => '']], 'locked' => '{"7":"40"}'] + $total('10'),
        ['shared-costs-field-shared-with-1' => '6.00', 'shared-costs-field-shared-with-7' => '4.00'],
      ],
      'shares that do not add up: nothing yet' => [
        ['field_shared_with' => [1 => ['selected' => '1', 'share' => '50'], 2 => ['selected' => '1', 'share' => '30']]] + $total('140'),
        ['shared-costs-field-shared-with-1' => '', 'shared-costs-field-shared-with-2' => ''],
      ],
      'more than 100 %: nothing yet' => [
        ['field_shared_with' => [1 => ['selected' => '1', 'share' => '120'], 2 => ['selected' => '1', 'share' => '']]] + $total('140'),
        ['shared-costs-field-shared-with-1' => '', 'shared-costs-field-shared-with-2' => ''],
      ],
      'a share that is not a number is open' => [
        ['field_shared_with' => [1 => ['selected' => '1', 'share' => 'half'], 2 => ['selected' => '1', 'share' => '25']]] + $total('8'),
        ['shared-costs-field-shared-with-1' => '6.00', 'shared-costs-field-shared-with-2' => '2.00'],
      ],
      'no total yet' => [
        ['field_shared_with' => [1 => ['selected' => '1', 'share' => '']]] + $total(''),
        ['shared-costs-field-shared-with-1' => ''],
      ],
      'nothing ticked' => [
        ['field_shared_with' => [1 => ['share' => '']]] + $total('10'),
        ['shared-costs-field-shared-with-1' => ''],
      ],
    ];
  }

  /**
   * Tests that field names are checked before they are used.
   */
  public function testInvalidFieldName(): void {
    $request = Request::create('/shared-costs/split-preview', 'GET', ['field' => 'field"><script>', 'total' => 'field_total']);
    $this->assertSame(400, (new SplitPreviewController())($request)->getStatusCode());
  }

}
