<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Controller;

use Drupal\Component\Utility\Html;
use Drupal\shared_costs\Allocation\AllocationCalculator;
use Drupal\shared_costs\Plugin\Field\FieldWidget\AllocationTableWidget;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out the amounts while a form is being filled in (HTMX).
 *
 * The table widget sends its rows and the total here as the user types; this
 * answers with the amount per row as out-of-band swaps for the widget's
 * <output> elements. It only does arithmetic on the numbers it gets: no
 * entities are loaded and nothing is saved, so the form itself (and its Save
 * button) is never involved.
 *
 * @see \Drupal\shared_costs\Plugin\Field\FieldWidget\AllocationTableWidget
 */
final class SplitPreviewController {

  /**
   * Answers a preview request.
   */
  public function __invoke(Request $request): Response {
    $field = (string) $request->query->get('field', '');
    $total_field = (string) $request->query->get('total', '');
    if (!preg_match('/^[a-z0-9_]+$/', $field) || !preg_match('/^[a-z0-9_]+$/', $total_field)) {
      return new Response('', Response::HTTP_BAD_REQUEST);
    }
    $decimal = $request->query->get('decimal') === ',' ? ',' : '.';

    // Every row sends its share (checked or not), ticked rows also send
    // "selected"; rows that can't be changed come along in "locked".
    $rows = $request->query->all($field);
    $locked = json_decode((string) $request->query->get('locked', '[]'), TRUE);
    $shares = [];
    foreach ($rows as $id => $row) {
      if (is_array($row) && !empty($row['selected'])) {
        $shares[(string) $id] = self::share($row['share'] ?? NULL);
      }
    }
    foreach (is_array($locked) ? $locked : [] as $id => $share) {
      $shares[(string) $id] = self::share($share);
    }

    $total = $request->query->all($total_field)[0]['value'] ?? '';
    $amounts = [];
    if (is_string($total) && is_numeric(str_replace(',', '.', $total)) && self::valid($shares)) {
      try {
        $amounts = (new AllocationCalculator())->split(str_replace(',', '.', $total), $shares);
      }
      catch (\InvalidArgumentException) {
        // Shares that can't be worked out yet (say "120"): show nothing.
      }
    }

    $html = '';
    foreach (array_unique(array_merge(array_map('strval', array_keys($rows)), array_keys($shares))) as $id) {
      $text = isset($amounts[$id]) ? number_format((float) $amounts[$id], 2, $decimal, '') : '';
      $html .= sprintf('<output id="%s" hx-swap-oob="true">%s</output>', Html::escape(AllocationTableWidget::outputId($field, $id)), Html::escape($text));
    }
    return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
  }

  /**
   * Checks the shares like the field's validation does.
   *
   * Only a split that can be saved is shown: given shares may not add up to
   * more than 100 %, and to exactly 100 % when no share is left open.
   *
   * @param array<string, string|null> $shares
   *   The shares of the ticked rows.
   *
   * @see \Drupal\shared_costs\Plugin\Validation\Constraint\AllocationSharesConstraintValidator
   */
  private static function valid(array $shares): bool {
    if ($shares === []) {
      return FALSE;
    }
    $given = array_filter($shares, static fn (?string $share): bool => $share !== NULL);
    $sum = array_sum(array_map(static fn (string $share): int => (int) round((float) $share * 100), $given));
    return $sum <= 10000 && (count($given) < count($shares) || $sum === 10000);
  }

  /**
   * Reads a submitted share: a number, or NULL for an equal part.
   */
  private static function share(mixed $share): ?string {
    if (!is_scalar($share)) {
      return NULL;
    }
    $share = str_replace(',', '.', trim((string) $share));
    return is_numeric($share) ? $share : NULL;
  }

}
