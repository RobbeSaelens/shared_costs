<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Plugin\views\argument;

use Drupal\datetime\Plugin\views\argument\YearMonthDate;
use Drupal\views\Attribute\ViewsArgument;

/**
 * A month of a date field (YYYYMM), shown as "November 2026".
 *
 * Core's datetime argument for a year and month filters fine, but shows the
 * raw "202611" as the page title and in the list of months (the summary).
 * This one shows the month's name, so a view can be a month picker.
 */
#[ViewsArgument(
  id: 'shared_costs_year_month',
)]
final class YearMonth extends YearMonthDate {

  /**
   * {@inheritdoc}
   *
   * @param mixed $data
   *   A summary row, with the month (YYYYMM) under the argument's name alias.
   */
  public function summaryName($data): string {
    return $this->monthName(is_object($data) ? (string) ($data->{$this->name_alias} ?? '') : '');
  }

  /**
   * {@inheritdoc}
   */
  public function title(): string {
    return $this->monthName((string) $this->argument);
  }

  /**
   * Formats YYYYMM as the month's name and year, in the current language.
   */
  private function monthName(string $value): string {
    if (!preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $value, $match)) {
      return $value;
    }
    $month = new \DateTimeImmutable("$match[1]-$match[2]-15 12:00", new \DateTimeZone('UTC'));
    return $this->dateFormatter->format($month->getTimestamp(), 'custom', 'F Y', 'UTC');
  }

}
