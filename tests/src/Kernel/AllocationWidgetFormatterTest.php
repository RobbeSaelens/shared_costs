<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shared_costs\Plugin\Field\FieldFormatter\AllocationFormatter;
use Drupal\shared_costs\Plugin\Field\FieldWidget\AllocationTableWidget;
use Drupal\Tests\shared_costs\Traits\SharedCostsTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the table widget and the formatter.
 */
#[CoversClass(AllocationTableWidget::class)]
#[CoversClass(AllocationFormatter::class)]
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class AllocationWidgetFormatterTest extends KernelTestBase {

  use SharedCostsTestTrait;
  use UserCreationTrait;

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
    $this->installConfig(['system']);
    // Sharers in another bundle: referenced, but no longer allowed.
    EntityTestHelper::createBundle('retired');
    $this->setUpCurrentUser([], ['view test entity', 'administer entity_test content']);
  }

  /**
   * Tests the table: a row per sharer, with what was picked.
   */
  public function testWidgetForm(): void {
    $field = $this->createSharedCostsFields();
    $field->setSetting('handler_settings', ['target_bundles' => ['entity_test' => 'entity_test']])->save();
    EntityFormDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->setComponent('field_shared_with', ['type' => 'shared_costs_allocation_table'])
      ->save();

    $luna = $this->createSharer('Luna');
    $bella = $this->createSharer('Bella');
    $old = EntityTest::create(['name' => 'Storm', 'type' => 'retired']);
    $old->save();
    $costs = $this->createCosts('30', [[$luna, '40'], [$old]]);
    $costs->save();

    $form = \Drupal::service('entity.form_builder')->getForm($costs);
    $table = $form['field_shared_with']['widget'];
    $this->assertSame('table', $table['#type']);

    // Luna is picked with 40 %; Bella can be picked; nothing else is listed
    // except Storm, read-only because its bundle is no longer allowed.
    $this->assertTrue($table[$luna->id()]['selected']['#default_value']);
    $this->assertEquals(40, $table[$luna->id()]['share']['#default_value']);
    $this->assertFalse($table[$bella->id()]['selected']['#default_value']);
    $this->assertNull($table[$bella->id()]['share']['#default_value']);
    $this->assertTrue($table[$old->id()]['selected']['#disabled']);
    $this->assertTrue($table[$old->id()]['share']['#disabled']);
    $this->assertSame('Storm', $table[$old->id()]['selected']['#title']);
    $this->assertFalse($table[$luna->id()]['selected']['#disabled']);
    // Accessible: a caption, and a label for every share.
    $this->assertSame('Shared with', (string) $table['#caption']);
    $this->assertSame('Share of Luna', (string) $table[$luna->id()]['share']['#title']);

    // The amounts are shown from the start: Luna 40 % of 30, Storm the rest.
    $this->assertSame('12.00', $table[$luna->id()]['amount']['#value']);
    $this->assertSame('18.00', $table[$old->id()]['amount']['#value']);
    $this->assertSame('', $table[$bella->id()]['amount']['#value']);
    $this->assertSame('shared-costs-field-shared-with-' . $luna->id(), $table[$luna->id()]['amount']['#attributes']['id']);

    // HTMX: the table asks for new amounts when the shares or the total change.
    $this->assertContains('core/drupal.htmx', $table['#attached']['library']);
    $attributes = $table['#attributes'];
    $this->assertStringEndsWith('/shared-costs/split-preview', (string) $attributes['data-hx-get']);
    $this->assertSame("[name^='field_shared_with['], [name='field_total[0][value]']", (string) $attributes['data-hx-include']);
    $this->assertStringContainsString("from:[name='field_total[0][value]']", (string) $attributes['data-hx-trigger']);
    $this->assertSame('none ignoreTitle:true', (string) $attributes['data-hx-swap']);
    $vals = json_decode((string) $attributes['data-hx-vals'], TRUE);
    $this->assertSame([
      'field' => 'field_shared_with',
      'total' => 'field_total',
      'decimal' => '.',
      'locked' => json_encode([(string) $old->id() => NULL]),
    ], $vals);
  }

  /**
   * Tests that sharers with an equal part (no share) are ticked when editing.
   */
  public function testWidgetEqualParts(): void {
    $field = $this->createSharedCostsFields();
    $field->setSetting('handler_settings', ['target_bundles' => ['entity_test' => 'entity_test']])->save();
    EntityFormDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->setComponent('field_shared_with', ['type' => 'shared_costs_allocation_table'])
      ->save();
    $luna = $this->createSharer('Luna');
    $bella = $this->createSharer('Bella');
    $costs = $this->createCosts('30', [[$luna], [$bella]]);
    $costs->save();

    $table = \Drupal::service('entity.form_builder')->getForm($costs)['field_shared_with']['widget'];
    foreach ([$luna, $bella] as $sharer) {
      $this->assertTrue($table[$sharer->id()]['selected']['#default_value'], $sharer->label() . ' is ticked.');
      $this->assertNull($table[$sharer->id()]['share']['#default_value']);
      $this->assertSame('15.00', $table[$sharer->id()]['amount']['#value']);
    }
  }

  /**
   * Tests the widget's setting: the decimal marker of the amounts.
   */
  public function testWidgetSettings(): void {
    $field = $this->createSharedCostsFields();
    $widget = \Drupal::service('plugin.manager.field.widget')->getInstance([
      'field_definition' => $field,
      'form_mode' => 'default',
      'configuration' => ['type' => 'shared_costs_allocation_table', 'settings' => ['decimal_separator' => ',']],
    ]);
    $this->assertInstanceOf(AllocationTableWidget::class, $widget);
    $this->assertSame(['Amounts like 1234,50'], array_map('strval', $widget->settingsSummary()));
    $form = [];
    $this->assertSame(',', $widget->settingsForm($form, new FormState())['decimal_separator']['#default_value']);
  }

  /**
   * Tests turning the submitted rows into field values.
   */
  public function testMassageFormValues(): void {
    $field = $this->createSharedCostsFields();
    $widget = \Drupal::service('plugin.manager.field.widget')->getInstance([
      'field_definition' => $field,
      'form_mode' => 'default',
      'configuration' => ['type' => 'shared_costs_allocation_table', 'settings' => []],
    ]);
    $this->assertInstanceOf(AllocationTableWidget::class, $widget);
    $form = [];
    $values = $widget->massageFormValues([
      '1' => ['selected' => 1, 'share' => '70'],
      '2' => ['selected' => 1, 'share' => ''],
      '3' => ['selected' => 0, 'share' => '30'],
      '4' => 'not a row',
    ], $form, new FormState());
    $this->assertSame([
      ['target_id' => 1, 'share' => '70'],
      ['target_id' => 2, 'share' => NULL],
    ], $values);
  }

  /**
   * Tests that a required field needs at least one sharer.
   */
  public function testRequired(): void {
    $element = ['#required' => TRUE, '#parents' => ['field_shared_with'], '#title' => 'Shared with'];
    $form_state = new FormState();
    $form_state->setValue('field_shared_with', ['1' => ['selected' => 0, 'share' => '']]);
    AllocationTableWidget::validateTable($element, $form_state);
    $this->assertSame('Pick at least one for Shared with.', (string) $form_state->getErrors()['field_shared_with']);

    $form_state = new FormState();
    $form_state->setValue('field_shared_with', ['1' => ['selected' => 1, 'share' => '']]);
    AllocationTableWidget::validateTable($element, $form_state);
    $this->assertSame([], $form_state->getErrors());

    // Optional: nothing picked is fine.
    $form_state = new FormState();
    AllocationTableWidget::validateTable(['#required' => FALSE] + $element, $form_state);
    $this->assertSame([], $form_state->getErrors());
  }

  /**
   * Tests the formatter: sharers with their share and amount.
   */
  public function testFormatter(): void {
    $this->createSharedCostsFields();
    $luna = $this->createSharer('Luna');
    $bella = $this->createSharer('Bella');
    $nova = $this->createSharer('Nova');
    $costs = $this->createCosts('1234.5', [[$luna, '50'], [$bella], [$nova]]);
    $costs->save();

    $this->assertSame([
      'Luna · 50 % · 617.25',
      'Bella · 25 % · 308.63',
      'Nova · 25 % · 308.62',
    ], $this->formatted($costs, []));

    $this->assertSame([
      'Luna · 50 % · € 617,25',
    ], array_slice($this->formatted($costs, ['decimal_separator' => ',', 'thousand_separator' => '.', 'prefix' => '€ ']), 0, 1));

    $big = $this->createCosts('12345.67', [[$luna]]);
    $big->save();
    $this->assertSame(['Luna · 12.345,67 EUR'], $this->formatted($big, [
      'show_share' => FALSE,
      'decimal_separator' => ',',
      'thousand_separator' => '.',
      'suffix' => ' EUR',
    ]));
    $this->assertSame(['Luna · 100 %'], $this->formatted($big, ['show_amount' => FALSE]));
    $this->assertSame(['Luna'], $this->formatted($big, ['show_share' => FALSE, 'show_amount' => FALSE]));

    // Without a total, there are no amounts: only the given shares show.
    $open = $this->createCosts(NULL, [[$luna, '60'], [$bella]]);
    $open->save();
    $this->assertSame(['Luna · 60 %', 'Bella'], $this->formatted($open, []));
  }

  /**
   * Tests that sharers the viewer may not see are left out.
   */
  public function testFormatterAccess(): void {
    $this->createSharedCostsFields();
    $costs = $this->createCosts('10', [[$this->createSharer('Luna')]]);
    $costs->save();
    $this->setUpCurrentUser([], []);
    $this->assertSame([], $this->formatted($costs, []));
  }

  /**
   * Tests the formatter's settings summary.
   */
  public function testFormatterSummary(): void {
    $field = $this->createSharedCostsFields();
    $formatter = \Drupal::service('plugin.manager.field.formatter')->getInstance([
      'field_definition' => $field,
      'view_mode' => 'default',
      'configuration' => ['type' => 'shared_costs_allocation_default', 'settings' => ['prefix' => '€ ']],
    ]);
    $this->assertInstanceOf(AllocationFormatter::class, $formatter);
    $summary = array_map('strval', $formatter->settingsSummary());
    $this->assertContains('With share, amount (€ 1234.50)', $summary);

    $form = [];
    $settings = $formatter->settingsForm($form, new FormState());
    // The entity reference label formatter's own setting, then these.
    $this->assertSame(
      ['link', 'show_share', 'show_amount', 'decimal_separator', 'thousand_separator', 'prefix', 'suffix'],
      array_values(array_intersect(array_keys($settings), array_keys(AllocationFormatter::defaultSettings()))),
    );
  }

  /**
   * Renders the field with the formatter, one plain-text line per sharer.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $costs
   *   The host entity.
   * @param array<string, mixed> $settings
   *   The formatter settings.
   *
   * @return string[]
   *   The lines.
   */
  protected function formatted(EntityTest $costs, array $settings): array {
    $build = $this->allocations($costs)->view([
      'type' => 'shared_costs_allocation_default',
      'label' => 'hidden',
      'settings' => $settings + ['link' => FALSE],
    ]);
    $lines = [];
    foreach (array_filter(array_keys($build), 'is_int') as $delta) {
      $html = (string) \Drupal::service('renderer')->renderInIsolation($build[$delta]);
      $lines[] = trim(html_entity_decode(strip_tags($html)));
    }
    return $lines;
  }

}
