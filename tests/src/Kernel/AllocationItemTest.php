<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItem;
use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItemList;
use Drupal\shared_costs\Plugin\Validation\Constraint\AllocationSharesConstraint;
use Drupal\shared_costs\Plugin\Validation\Constraint\AllocationSharesConstraintValidator;
use Drupal\Tests\shared_costs\Traits\SharedCostsTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shared costs field type: storage, amounts, validation, config.
 */
#[CoversClass(AllocationItem::class)]
#[CoversClass(AllocationItemList::class)]
#[CoversClass(AllocationSharesConstraint::class)]
#[CoversClass(AllocationSharesConstraintValidator::class)]
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class AllocationItemTest extends KernelTestBase {

  use SharedCostsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_test', 'field', 'shared_costs', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
  }

  /**
   * Tests that amounts are stored on save, and add up to the total.
   */
  public function testAmountsOnSave(): void {
    $this->createSharedCostsFields();
    $luna = $this->createSharer('Luna');
    $bella = $this->createSharer('Bella');

    $costs = $this->createCosts('12.35', [[$luna], [$bella]]);
    $costs->save();
    $this->assertSame(['Luna' => '6.18', 'Bella' => '6.17'], $this->amounts($this->reload($costs)));

    // A new total, and new shares, give new amounts.
    $costs->set('field_total', '140');
    $costs->save();
    $this->assertSame(['Luna' => '70.00', 'Bella' => '70.00'], $this->amounts($this->reload($costs)));

    $costs->set('field_shared_with', [
      ['target_id' => $luna->id(), 'share' => 70],
      ['target_id' => $bella->id(), 'share' => 30],
    ]);
    $costs->save();
    $reloaded = $this->reload($costs);
    $this->assertSame(['Luna' => '98.00', 'Bella' => '42.00'], $this->amounts($reloaded));
    // Shares are stored as given.
    $this->assertEquals([70, 30], array_map(static fn ($item) => $item->share, $this->allocations($reloaded)->allocations()));

    // Removing a sharer gives the rest to the others.
    $costs->set('field_shared_with', [['target_id' => $luna->id()]]);
    $costs->save();
    $this->assertSame(['Luna' => '140.00'], $this->amounts($this->reload($costs)));
  }

  /**
   * Tests the total from integer and float fields.
   */
  #[DataProvider('providerTotalTypes')]
  public function testTotalFieldTypes(string $type, int|float $total, string $expected): void {
    $this->createSharedCostsFields($type);
    $costs = $this->createCosts($total, [[$this->createSharer('Luna')], [$this->createSharer('Bella')]]);
    $costs->save();
    $this->assertSame(['Luna' => $expected, 'Bella' => $expected], $this->amounts($this->reload($costs)));
  }

  /**
   * Data for testTotalFieldTypes().
   *
   * @return array<string, array{string, int|float, string}>
   *   Total field type, total, expected amount per sharer.
   */
  public static function providerTotalTypes(): array {
    return [
      'integer' => ['integer', 9, '4.50'],
      'float' => ['float', 0.5, '0.25'],
    ];
  }

  /**
   * Tests that there are no amounts without a total.
   */
  public function testNoTotal(): void {
    $field = $this->createSharedCostsFields();
    $luna = $this->createSharer('Luna');

    $costs = $this->createCosts(NULL, [[$luna]]);
    $costs->save();
    $this->assertSame(['Luna' => NULL], $this->amounts($this->reload($costs)));

    // Without a total field setting, nothing is worked out either.
    $field->setSetting('total_field', NULL)->save();
    $costs = $this->createCosts('10', [[$luna]]);
    $costs->save();
    $this->assertSame(['Luna' => NULL], $this->amounts($this->reload($costs)));

    // Nor when the total field doesn't exist.
    $field->setSetting('total_field', 'field_missing')->save();
    $costs = $this->createCosts('10', [[$luna]]);
    $costs->save();
    $this->assertSame(['Luna' => NULL], $this->amounts($this->reload($costs)));
  }

  /**
   * Tests validation of the shares.
   *
   * @param array<int, string|int|null> $shares
   *   The share per sharer (one sharer per entry).
   * @param string[] $messages
   *   The expected violation messages.
   * @param bool $duplicate
   *   Whether to list the first sharer twice.
   */
  #[DataProvider('providerValidation')]
  public function testValidation(array $shares, array $messages, bool $duplicate = FALSE): void {
    $this->createSharedCostsFields();
    $sharers = [];
    foreach ($shares as $i => $share) {
      $sharer = $duplicate && $i > 0 ? $sharers[0][0] : $this->createSharer("Sharer $i");
      $sharers[] = [$sharer, $share];
    }
    $violations = $this->createCosts('100', $sharers)->get('field_shared_with')->validate();
    $this->assertEqualsCanonicalizing($messages, array_map(static fn ($violation): string => strip_tags((string) $violation->getMessage()), iterator_to_array($violations)));
  }

  /**
   * Data for testValidation().
   *
   * @return array<string, array{0: array<int, string|int|null>, 1: string[], 2?: bool}>
   *   Shares, expected messages, whether the first sharer is listed twice.
   */
  public static function providerValidation(): array {
    $too_much = static fn (string $sum): string => "The shares add up to $sum %, which is more than 100 %.";
    $not_whole = static fn (string $sum): string => "The shares add up to $sum %. Make them add up to 100 %, or leave a share empty to give that sharer the rest.";
    $out_of_range = 'Shared with: a share must be from 0 to 100 %.';
    return [
      'all equal' => [[NULL, NULL], []],
      'adds up to 100' => [['70', '30'], []],
      'with decimals' => [['33.33', '33.33', '33.34'], []],
      'given and open' => [['60', NULL], []],
      'given with nothing left for the open one' => [['100', NULL], []],
      'more than 100' => [['60', '50'], [$too_much('110')]],
      'more than 100 with an open one' => [['60', '50', NULL], [$too_much('110')]],
      'not whole' => [['60', '30'], [$not_whole('90')]],
      'not whole with decimals' => [['33.33', '33.33'], [$not_whole('66.66')]],
      'share out of range' => [['120', NULL], [$out_of_range, $too_much('120')]],
      'negative share' => [['-5', NULL], [$out_of_range]],
      'listed twice' => [[NULL, NULL], ['Sharer 0 is listed more than once.'], TRUE],
    ];
  }

  /**
   * Tests the field's config dependency on the total field.
   */
  public function testTotalFieldDependency(): void {
    $field = $this->createSharedCostsFields();
    $this->assertContains('field.field.entity_test.entity_test.field_total', $field->getDependencies()['config']);

    // Deleting the total field keeps the shared costs field (and its data).
    $total = FieldConfig::loadByName('entity_test', 'entity_test', 'field_total');
    $this->assertNotNull($total);
    $total->delete();
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_shared_with');
    $this->assertNotNull($field, 'The shared costs field is kept.');
    $this->assertNull($field->getSetting('total_field'));
    $this->assertNotContains('field.field.entity_test.entity_test.field_total', $field->getDependencies()['config'] ?? []);
  }

  /**
   * Tests that the field type is offered once in Field UI.
   */
  public function testNoPreconfiguredOptions(): void {
    $this->assertSame([], AllocationItem::getPreconfiguredOptions());
  }

  /**
   * Tests the fields that can hold the total.
   */
  public function testTotalFieldOptions(): void {
    $field = $this->createSharedCostsFields();
    $this->assertSame(['field_total' => 'Total'], AllocationItem::totalFieldOptions($field));
  }

}
