<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Allocation;

/**
 * Splits an amount over sharers, cent-exact.
 *
 * Works in integer cents and basis points (hundredths of a percent), so float
 * rounding can never make the parts drift from the total: the largest
 * remainder method hands out the cents that rounding leaves over, to the
 * sharers that lost the most to it.
 */
final readonly class AllocationCalculator {

  /**
   * Basis points in 100 %.
   */
  private const WHOLE = 10000;

  /**
   * Splits a total over sharers.
   *
   * A share is a percentage (0 to 100, two decimals), or NULL for an equal
   * part of what the given percentages leave. Shares that don't add up to
   * 100 % (only possible without validation) are scaled to fit; if none
   * carries weight, the total is split equally.
   *
   * @param string|int|float $total
   *   The amount to split; rounded half away from zero to cents. Negative
   *   amounts (credits, refunds) split the same way.
   * @param array<array-key, string|int|float|null> $shares
   *   The share per sharer, keyed as the caller likes.
   *
   * @return array<array-key, string>
   *   The amount per sharer as a decimal string with two decimals, under the
   *   same keys and in the same order. Together they make up the total.
   *
   * @throws \InvalidArgumentException
   *   When the total isn't a number, or a share isn't a number from 0 to 100.
   */
  public function split(string|int|float $total, array $shares): array {
    if ($shares === []) {
      return [];
    }
    $cents = self::toHundredths($total, 'total');
    $weights = self::weights($shares);
    $denominator = array_sum($weights);
    if (abs($cents) > intdiv(PHP_INT_MAX, max($weights))) {
      throw new \InvalidArgumentException('The total is too large to split.');
    }

    // Each sharer's exact part is |cents| * weight / denominator: hand out the
    // whole cents, then the leftover ones to the largest remainders.
    $absolute = abs($cents);
    $parts = [];
    $remainders = [];
    foreach ($weights as $key => $weight) {
      $parts[$key] = intdiv($absolute * $weight, $denominator);
      $remainders[$key] = ($absolute * $weight) % $denominator;
    }
    $leftover = $absolute - array_sum($parts);
    if ($leftover > 0) {
      // arsort() keeps the original order between equal remainders.
      arsort($remainders);
      foreach (array_slice(array_keys($remainders), 0, $leftover) as $key) {
        $parts[$key]++;
      }
    }

    $sign = $cents < 0 ? -1 : 1;
    return array_map(static fn (int $part): string => self::format($sign * $part), $parts);
  }

  /**
   * Turns shares into integer weights over a common denominator.
   *
   * @param non-empty-array<array-key, string|int|float|null> $shares
   *   The shares, as given to split().
   *
   * @return non-empty-array<array-key, int>
   *   A weight per sharer; their sum is never zero.
   */
  private static function weights(array $shares): array {
    $points = [];
    $open = 0;
    foreach ($shares as $key => $share) {
      if ($share === NULL || $share === '') {
        $points[$key] = NULL;
        $open++;
        continue;
      }
      $points[$key] = self::toHundredths($share, 'share');
      if ($points[$key] < 0 || $points[$key] > self::WHOLE) {
        throw new \InvalidArgumentException(sprintf('A share must be from 0 to 100, got %s.', var_export($share, TRUE)));
      }
    }
    $given = array_sum(array_filter($points, static fn (?int $point): bool => $point !== NULL));
    $rest = max(0, self::WHOLE - $given);

    if ($open > 0 && $rest > 0) {
      // Open shares split the rest equally: multiply everything by the number
      // of open shares, so their equal part is a whole number.
      return array_map(static fn (?int $point): int => $point === NULL ? $rest : $point * $open, $points);
    }
    if ($given > 0) {
      // Nothing left for open shares: the given percentages carry the total.
      return array_map(static fn (?int $point): int => $point ?? 0, $points);
    }
    // No weight anywhere (all zero): split equally.
    return array_map(static fn (): int => 1, $points);
  }

  /**
   * Parses a decimal number into hundredths, rounded half away from zero.
   *
   * @param string|int|float $number
   *   The number: an integer, a float, or a decimal string like "-12.345".
   * @param string $what
   *   What the number is, for the exception message.
   *
   * @return int
   *   The number times 100.
   *
   * @throws \InvalidArgumentException
   *   When it isn't a finite decimal number.
   */
  private static function toHundredths(string|int|float $number, string $what): int {
    $limit = intdiv(PHP_INT_MAX, 1000);
    if (is_int($number)) {
      if (abs($number) > $limit) {
        throw new \InvalidArgumentException(sprintf('The %s is too large to split.', $what));
      }
      return $number * 100;
    }
    if (is_float($number)) {
      if (!is_finite($number)) {
        throw new \InvalidArgumentException(sprintf('The %s must be a finite number.', $what));
      }
      // Enough digits to round correctly, without float noise from * 100.
      $number = sprintf('%.6F', $number);
    }
    if (!preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?\s*$/', $number, $match) || ($match[2] === '' && ($match[3] ?? '') === '')) {
      throw new \InvalidArgumentException(sprintf('The %s must be a decimal number, got "%s".', $what, $number));
    }
    if ((int) $match[2] > $limit || strlen(ltrim($match[2], '0')) > strlen((string) $limit)) {
      throw new \InvalidArgumentException(sprintf('The %s is too large to split.', $what));
    }
    $decimals = str_pad($match[3] ?? '', 3, '0');
    $hundredths = (int) $match[2] * 100 + (int) substr($decimals, 0, 2);
    if ($decimals[2] >= '5') {
      $hundredths++;
    }
    return $match[1] === '-' ? -$hundredths : $hundredths;
  }

  /**
   * Formats cents as a decimal string with two decimals.
   */
  private static function format(int $cents): string {
    $absolute = abs($cents);
    return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv($absolute, 100), $absolute % 100);
  }

}
