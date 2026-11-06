<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Plugin\Field\FieldType;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\shared_costs\Allocation\AllocationCalculator;

/**
 * The sharers of an entity's total.
 *
 * Works out every sharer's amount when the host entity is saved, from the
 * total field in the field settings, so the amounts always add up to the
 * total to the cent.
 *
 * @extends \Drupal\Core\Field\EntityReferenceFieldItemList<\Drupal\Core\Entity\EntityInterface>
 */
class AllocationItemList extends EntityReferenceFieldItemList {

  /**
   * {@inheritdoc}
   *
   * @return \Symfony\Component\Validator\Constraint[]
   *   The constraints.
   */
  public function getConstraints(): array {
    $constraints = parent::getConstraints();
    $constraints[] = $this->getTypedDataManager()->getValidationConstraintManager()->create('SharedCostsAllocationShares', []);
    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(): void {
    parent::preSave();
    $total = $this->total();
    $items = $this->allocations();
    $shares = array_map(static fn (AllocationItem $item): string|int|float|null => $item->share, $items);
    $amounts = $total === NULL ? [] : (new AllocationCalculator())->split($total, $shares);
    foreach ($items as $delta => $item) {
      $item->amount = $amounts[$delta] ?? NULL;
    }
  }

  /**
   * Returns the items, typed.
   *
   * @return array<int, \Drupal\shared_costs\Plugin\Field\FieldType\AllocationItem>
   *   The items, by delta.
   */
  public function allocations(): array {
    return array_filter($this->list, static fn ($item): bool => $item instanceof AllocationItem);
  }

  /**
   * Reads the total from the host entity.
   *
   * @return string|int|float|null
   *   The total, or NULL when there is none (no total field, or it's empty).
   */
  protected function total(): string|int|float|null {
    $name = $this->getSetting('total_field');
    $entity = $this->getEntity();
    if (!is_string($name) || !$entity->hasField($name)) {
      return NULL;
    }
    $value = $entity->get($name)->value;
    return is_string($value) && $value !== '' || is_int($value) || is_float($value) ? $value : NULL;
  }

}
