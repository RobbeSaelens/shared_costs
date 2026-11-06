<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Plugin\Validation\Constraint;

use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItemList;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates the shares of a cost.
 */
final class AllocationSharesConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (!$constraint instanceof AllocationSharesConstraint) {
      throw new UnexpectedTypeException($constraint, AllocationSharesConstraint::class);
    }
    if (!$value instanceof AllocationItemList) {
      return;
    }

    // In hundredths of a percent, so the sum is exact.
    $sum = 0;
    $open = FALSE;
    $seen = [];
    foreach ($value->allocations() as $delta => $item) {
      if ($item->isEmpty()) {
        continue;
      }
      $target = (string) $item->target_id;
      if (isset($seen[$target])) {
        $this->context->buildViolation($constraint->duplicateMessage, ['%label' => $item->entity?->label() ?? $target])
          ->atPath((string) $delta . '.target_id')
          ->addViolation();
      }
      $seen[$target] = TRUE;
      if ($item->share === NULL || $item->share === '') {
        // An open share: this sharer gets an equal part of the rest.
        $open = TRUE;
      }
      elseif (is_numeric($item->share)) {
        $sum += (int) round((float) $item->share * 100);
      }
      // Anything else is reported by the item's own constraints.
    }

    if ($sum > 10000) {
      $this->context->addViolation($constraint->tooMuchMessage, ['@sum' => $this->percentage($sum)]);
    }
    elseif (!$open && $seen !== [] && $sum !== 10000) {
      $this->context->addViolation($constraint->notWholeMessage, ['@sum' => $this->percentage($sum)]);
    }
  }

  /**
   * Formats hundredths of a percent, without needless decimals.
   */
  private function percentage(int $hundredths): string {
    return rtrim(rtrim(sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100), '0'), '.');
  }

}
