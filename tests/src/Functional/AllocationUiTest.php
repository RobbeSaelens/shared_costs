<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\field_ui\Traits\FieldUiTestTrait;
use Drupal\Tests\shared_costs\Traits\SharedCostsTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the shared costs field through the browser: Field UI, form and page.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class AllocationUiTest extends BrowserTestBase {

  use FieldUiTestTrait;
  use SharedCostsTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_test', 'field', 'field_ui', 'shared_costs'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser([
      'administer entity_test content',
      'administer entity_test fields',
      'administer entity_test form display',
      'view test entity',
    ]));
  }

  /**
   * Tests adding the field in Field UI, with its total field.
   */
  public function testFieldUi(): void {
    // A total first, so it can be picked.
    $this->fieldUIAddNewField('entity_test/structure/entity_test', 'amount', 'Amount', 'decimal');
    $this->fieldUIAddNewField('entity_test/structure/entity_test', 'split', 'Split', 'shared_costs_allocation', [], [
      'settings[total_field]' => 'field_amount',
    ]);
    $field = FieldConfig::loadByName('entity_test', 'entity_test', 'field_split');
    $this->assertNotNull($field);
    $this->assertSame('field_amount', $field->getSetting('total_field'));
    $this->assertSame(-1, $field->getFieldStorageDefinition()->getCardinality());
    $this->assertSame('shared_costs_allocation_table', EntityFormDisplay::load('entity_test.entity_test.default')?->getComponent('field_split')['type'] ?? NULL);
  }

  /**
   * Tests sharing costs through the form, and what the page shows.
   */
  public function testForm(): void {
    $this->createSharedCostsFields();
    $this->container->get('entity_display.repository')->getFormDisplay('entity_test', 'entity_test')
      ->setComponent('field_total', ['type' => 'number'])
      ->setComponent('field_shared_with', ['type' => 'shared_costs_allocation_table'])
      ->save();
    $this->container->get('entity_display.repository')->getViewDisplay('entity_test', 'entity_test')
      ->setComponent('field_shared_with', ['type' => 'shared_costs_allocation_default', 'settings' => ['link' => FALSE]])
      ->save();
    $luna = $this->createSharer('Luna');
    $bella = $this->createSharer('Bella');

    $this->drupalGet('entity_test/add');
    $this->assertSession()->elementTextContains('css', 'table caption', 'Shared with');
    $this->assertSession()->fieldExists('Share of Luna');
    $this->submitForm([
      'name[0][value]' => 'Farrier',
      'field_total[0][value]' => '140',
      "field_shared_with[{$luna->id()}][selected]" => TRUE,
      "field_shared_with[{$luna->id()}][share]" => '70',
      "field_shared_with[{$bella->id()}][selected]" => TRUE,
    ], 'Save');

    $costs = $this->loadByName('Farrier');
    $this->assertSame(['Luna' => '98.00', 'Bella' => '42.00'], $this->amounts($costs));
    $this->drupalGet($costs->toUrl());
    $this->assertSession()->pageTextContains('Luna · 70 % · 98.00');
    $this->assertSession()->pageTextContains('Bella · 30 % · 42.00');

    // Editing keeps what was picked.
    $this->drupalGet($costs->toUrl('edit-form'));
    $this->assertSession()->checkboxChecked("field_shared_with[{$luna->id()}][selected]");
    $this->assertSession()->fieldValueEquals("field_shared_with[{$luna->id()}][share]", '70.00');
  }

  /**
   * Tests the error messages.
   */
  public function testValidation(): void {
    $field = $this->createSharedCostsFields();
    $field->setRequired(TRUE)->save();
    $this->container->get('entity_display.repository')->getFormDisplay('entity_test', 'entity_test')
      ->setComponent('field_total', ['type' => 'number'])
      ->setComponent('field_shared_with', ['type' => 'shared_costs_allocation_table'])
      ->save();
    $luna = $this->createSharer('Luna');
    $bella = $this->createSharer('Bella');

    $this->drupalGet('entity_test/add');
    $this->submitForm([
      'name[0][value]' => 'Vet',
      'field_total[0][value]' => '100',
      "field_shared_with[{$luna->id()}][selected]" => TRUE,
      "field_shared_with[{$luna->id()}][share]" => '60',
      "field_shared_with[{$bella->id()}][selected]" => TRUE,
      "field_shared_with[{$bella->id()}][share]" => '50',
    ], 'Save');
    $this->assertSession()->pageTextContains('The shares add up to 110 %, which is more than 100 %.');

    $this->submitForm([
      "field_shared_with[{$luna->id()}][selected]" => FALSE,
      "field_shared_with[{$bella->id()}][selected]" => FALSE,
    ], 'Save');
    $this->assertSession()->pageTextContains('Pick at least one for Shared with.');
    $this->assertNull(EntityTest::load(3), 'Nothing was saved.');
  }

  /**
   * Tests the route the table asks for live amounts (HTMX).
   */
  public function testSplitPreviewRoute(): void {
    $this->drupalLogout();
    $this->drupalGet('shared-costs/split-preview', ['query' => [
      'field' => 'field_shared_with',
      'total' => 'field_total',
      'field_shared_with' => [5 => ['selected' => '1', 'share' => '']],
      'field_total' => [['value' => '9.99']],
    ]]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('<output id="shared-costs-field-shared-with-5" hx-swap-oob="true">9.99</output>');
  }

  /**
   * Loads the test entity with a name.
   */
  protected function loadByName(string $name): EntityTest {
    $entities = $this->container->get('entity_type.manager')->getStorage('entity_test')->loadByProperties(['name' => $name]);
    $entity = reset($entities);
    $this->assertInstanceOf(EntityTest::class, $entity);
    return $entity;
  }

}
