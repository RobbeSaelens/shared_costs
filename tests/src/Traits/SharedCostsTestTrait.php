<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Traits;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItemList;

/**
 * Sets up a host entity with a total and a shared costs field.
 *
 * The host and the sharers are entity_test entities: costs on a test entity,
 * shared by other test entities.
 */
trait SharedCostsTestTrait {

  /**
   * Adds a decimal total field and a shared costs field to entity_test.
   *
   * @param string $total_type
   *   The total field's type: decimal, float or integer.
   *
   * @return \Drupal\field\Entity\FieldConfig
   *   The shared costs field.
   */
  protected function createSharedCostsFields(string $total_type = 'decimal'): FieldConfig {
    FieldStorageConfig::create([
      'field_name' => 'field_total',
      'entity_type' => 'entity_test',
      'type' => $total_type,
      'settings' => $total_type === 'decimal' ? ['precision' => 10, 'scale' => 2] : [],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_total',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Total',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_shared_with',
      'entity_type' => 'entity_test',
      'type' => 'shared_costs_allocation',
      'cardinality' => -1,
      'settings' => ['target_type' => 'entity_test'],
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_shared_with',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Shared with',
      'settings' => [
        'total_field' => 'field_total',
        'handler' => 'default',
        'handler_settings' => ['target_bundles' => NULL],
      ],
    ]);
    $field->save();
    return $field;
  }

  /**
   * Adds a date field (field_date) to entity_test.
   *
   * @param string $datetime_type
   *   'date' for a date-only field, 'datetime' for date and time.
   */
  protected function createDateField(string $datetime_type = 'date'): void {
    FieldStorageConfig::create([
      'field_name' => 'field_date',
      'entity_type' => 'entity_test',
      'type' => 'datetime',
      'settings' => ['datetime_type' => $datetime_type],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_date',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Date',
    ])->save();
  }

  /**
   * Creates a sharer.
   */
  protected function createSharer(string $name): EntityTest {
    $sharer = EntityTest::create(['name' => $name]);
    $sharer->save();
    return $sharer;
  }

  /**
   * Creates (unsaved) costs on a host entity.
   *
   * @param string|int|float|null $total
   *   The total.
   * @param array<int, array{0: \Drupal\Core\Entity\ContentEntityInterface, 1?: string|int|float|null}> $sharers
   *   The sharers, each with an optional share.
   */
  protected function createCosts(string|int|float|null $total, array $sharers): EntityTest {
    $items = [];
    foreach ($sharers as $sharer) {
      $items[] = ['target_id' => $sharer[0]->id(), 'share' => $sharer[1] ?? NULL];
    }
    return EntityTest::create([
      'name' => 'Costs',
      'field_total' => $total,
      'field_shared_with' => $items,
    ]);
  }

  /**
   * Loads a host entity again from storage.
   */
  protected function reload(EntityTest $entity): EntityTest {
    \Drupal::entityTypeManager()->getStorage('entity_test')->resetCache();
    $reloaded = EntityTest::load($entity->id());
    $this->assertInstanceOf(EntityTest::class, $reloaded);
    return $reloaded;
  }

  /**
   * Returns the shares field of a host entity.
   */
  protected function allocations(ContentEntityInterface $host): AllocationItemList {
    $items = $host->get('field_shared_with');
    $this->assertInstanceOf(AllocationItemList::class, $items);
    return $items;
  }

  /**
   * Returns the stored amounts of a host entity, keyed by sharer label.
   *
   * @return array<string, string|null>
   *   Amounts as stored (decimal strings), keyed by the sharer's label.
   */
  protected function amounts(ContentEntityInterface $host): array {
    $amounts = [];
    foreach ($this->allocations($host)->allocations() as $item) {
      $amounts[(string) $item->entity?->label()] = $item->amount === NULL ? NULL : sprintf('%.2F', $item->amount);
    }
    return $amounts;
  }

}
