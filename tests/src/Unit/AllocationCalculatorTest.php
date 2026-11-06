<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Unit;

use Drupal\shared_costs\Allocation\AllocationCalculator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests splitting amounts over sharers.
 */
#[CoversClass(AllocationCalculator::class)]
#[Group('shared_costs')]
final class AllocationCalculatorTest extends UnitTestCase {

  /**
   * Tests the amounts, and that they always add up to the total.
   *
   * @param string|int|float $total
   *   The total to split.
   * @param array<array-key, string|int|float|null> $shares
   *   The shares.
   * @param array<array-key, string> $expected
   *   The expected amounts.
   */
  #[DataProvider('providerSplit')]
  public function testSplit(string|int|float $total, array $shares, array $expected): void {
    $amounts = (new AllocationCalculator())->split($total, $shares);
    $this->assertSame($expected, $amounts);
    $this->assertSame(array_keys($shares), array_keys($amounts), 'Keys and order are kept.');
    $cents = array_sum(array_map(static fn (string $amount): int => (int) round((float) $amount * 100), $amounts));
    $this->assertSame((int) round((float) $total * 100), $cents, 'The amounts make up the total.');
  }

  /**
   * Data for testSplit().
   *
   * @return array<string, array{string|int|float, array<array-key, string|int|float|null>, array<array-key, string>}>
   *   Total, shares, expected amounts.
   */
  public static function providerSplit(): array {
    return [
      'equal halves' => ['25.00', [NULL, NULL], ['12.50', '12.50']],
      'odd cent goes to the first' => ['12.35', [NULL, NULL], ['6.18', '6.17']],
      'thirds' => ['100', [NULL, NULL, NULL], ['33.34', '33.33', '33.33']],
      'one sharer' => ['8', [NULL], ['8.00']],
      'custom percentages' => ['140.00', [70, 30], ['98.00', '42.00']],
      'percentages as strings with decimals' => ['10', ['33.33', '66.67'], ['3.33', '6.67']],
      'given share, the rest equally' => ['100', ['50', NULL, NULL], ['50.00', '25.00', '25.00']],
      'rest that does not divide evenly' => ['10', ['10', NULL, NULL], ['1.00', '4.50', '4.50']],
      'rest with a leftover cent' => ['0.05', ['20', NULL, NULL], ['0.01', '0.02', '0.02']],
      'zero share' => ['20', ['0', '100'], ['0.00', '20.00']],
      'string keys are kept' => ['9', ['luna' => NULL, 'bella' => NULL], ['luna' => '4.50', 'bella' => '4.50']],
      'integer keys out of order' => ['3', [12 => NULL, 4 => NULL], [12 => '1.50', 4 => '1.50']],
      'zero total' => ['0.00', [NULL, NULL], ['0.00', '0.00']],
      'negative total (a credit)' => ['-12.35', [NULL, NULL], ['-6.18', '-6.17']],
      'integer total' => [7, [NULL, NULL], ['3.50', '3.50']],
      'float total' => [0.1 + 0.2, [NULL, NULL], ['0.15', '0.15']],
      'unrounded total, half rounds away from zero' => ['10.005', [NULL], ['10.01']],
      'unrounded negative total' => ['-10.005', [NULL], ['-10.01']],
      'unrounded total rounds down' => ['10.004', [NULL], ['10.00']],
      'total without integer digits' => ['.5', [NULL], ['0.50']],
      'surrounding whitespace' => [' 4.20 ', [NULL, NULL], ['2.10', '2.10']],
      'large total' => ['99999999.99', [NULL, NULL, NULL], ['33333333.33', '33333333.33', '33333333.33']],
      'shares that do not add up are scaled' => ['10', ['20', '20'], ['5.00', '5.00']],
      'shares over 100 % leave nothing for open ones' => ['10', ['60', '60', NULL], ['5.00', '5.00', '0.00']],
      'all zero shares split equally' => ['10', ['0', '0'], ['5.00', '5.00']],
      'empty string share counts as open' => ['10', ['', '40'], ['6.00', '4.00']],
    ];
  }

  /**
   * Tests that no sharers give no amounts.
   */
  public function testNoSharers(): void {
    $this->assertSame([], (new AllocationCalculator())->split('10', []));
  }

  /**
   * Tests that invalid input is refused.
   *
   * @param string|int|float $total
   *   The total.
   * @param array<array-key, string|int|float|null> $shares
   *   The shares.
   * @param string $message
   *   Part of the expected exception message.
   */
  #[DataProvider('providerInvalid')]
  public function testInvalid(string|int|float $total, array $shares, string $message): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($message);
    (new AllocationCalculator())->split($total, $shares);
  }

  /**
   * Data for testInvalid().
   *
   * @return array<string, array{string|int|float, array<array-key, string|int|float|null>, string}>
   *   Total, shares, part of the message.
   */
  public static function providerInvalid(): array {
    return [
      'total is not a number' => ['ten', [NULL], 'The total must be a decimal number'],
      'total is empty' => ['', [NULL], 'The total must be a decimal number'],
      'total in exponent notation' => ['1e3', [NULL], 'The total must be a decimal number'],
      'total is infinite' => [INF, [NULL], 'The total must be a finite number'],
      'total is not a number (float)' => [NAN, [NULL], 'The total must be a finite number'],
      'share is not a number' => ['10', ['half'], 'The share must be a decimal number'],
      'negative share' => ['10', ['-1'], 'A share must be from 0 to 100'],
      'share over 100' => ['10', ['100.01'], 'A share must be from 0 to 100'],
      'total too large' => [PHP_INT_MAX, [NULL, NULL], 'The total is too large to split'],
    ];
  }

}
