<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Kernel;

use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\Core\Routing\RouteMatch;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\shared_costs\EventSubscriber\ContentExportSubscriber;
use Drupal\shared_costs\Hook\SharedCostsHooks;
use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItem;
use Drupal\Tests\shared_costs\Traits\SharedCostsTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Route;

/**
 * Tests how the module fits in: content export, translations, help, settings.
 */
#[CoversClass(ContentExportSubscriber::class)]
#[CoversClass(SharedCostsHooks::class)]
#[CoversClass(AllocationItem::class)]
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class ModuleIntegrationTest extends KernelTestBase {

  use SharedCostsTestTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_test', 'field', 'field_ui', 'shared_costs', 'system', 'user'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    $this->setUpCurrentUser([], ['administer entity_test fields', 'administer entity_test content', 'view test entity']);
  }

  /**
   * Tests that costs export with their sharers as UUIDs, and import again.
   */
  public function testContentExportRoundTrip(): void {
    $this->createSharedCostsFields();
    $luna = $this->createSharer('Luna');
    $bella = $this->createSharer('Bella');
    $costs = $this->createCosts('12.35', [[$luna, '60'], [$bella]]);
    $costs->save();

    $exporter = \Drupal::service(Exporter::class);
    $exported = $exporter->export($costs);
    $data = $exported->data;
    // The sharers by UUID; the open share stays empty (not 0).
    $this->assertEquals([
      ['share' => '60', 'entity' => $luna->uuid()],
      ['share' => NULL, 'entity' => $bella->uuid()],
    ], $data['default']['field_shared_with']);
    $this->assertSame([(string) $luna->uuid() => 'entity_test', (string) $bella->uuid() => 'entity_test'], $exported->metadata->get()['depends']);

    // Export everything, remove it, import it: the sharers are found by UUID.
    $directory = sys_get_temp_dir() . '/shared_costs_content_' . $this->randomMachineName();
    $exporter->exportWithDependencies($costs, $directory);
    $uuids = array_map('strval', [$costs->uuid(), $luna->uuid(), $bella->uuid()]);
    foreach ([$costs, $luna, $bella] as $entity) {
      $entity->delete();
    }
    \Drupal::service(Importer::class)->importContent(new Finder($directory));
    \Drupal::service('file_system')->deleteRecursive($directory);

    $imported = \Drupal::service('entity.repository')->loadEntityByUuid('entity_test', $uuids[0]);
    $this->assertInstanceOf(EntityTest::class, $imported);
    $this->assertSame(['Luna' => '7.41', 'Bella' => '4.94'], $this->amounts($imported));
    $sharers = array_map(static fn ($item) => $item->entity?->uuid(), $this->allocations($imported)->allocations());
    $this->assertSame([$uuids[1], $uuids[2]], $sharers);
  }

  /**
   * Tests that locale looks for translations in the module's own folder.
   */
  public function testTranslationsPath(): void {
    $projects = [
      'shared_costs' => ['info' => ['interface translation server pattern' => 'https://example.com/%language.po']],
      'other' => ['info' => ['interface translation server pattern' => 'https://example.com/other.po']],
    ];
    $before = $projects;
    $this->hooks()->localeTranslationProjectsAlter($projects);
    $path = \Drupal::service('extension.list.module')->getPath('shared_costs');
    $this->assertSame($path . '/translations/%language.po', $projects['shared_costs']['info']['interface translation server pattern']);
    $this->assertSame($before['other'], $projects['other'], 'Other projects are left alone.');
  }

  /**
   * Tests the help page text.
   */
  public function testHelp(): void {
    $hooks = $this->hooks();
    $route = new RouteMatch('help.page.shared_costs', new Route('/admin/help/shared_costs'));
    $help = (string) $hooks->help('help.page.shared_costs', $route);
    $this->assertStringContainsString('splits an amount over other entities', $help);
    $this->assertStringContainsString('cents that always add up to the total', $help);
    $this->assertNull($hooks->help('help.page.other', $route));
  }

  /**
   * Returns the module's hook implementations.
   */
  protected function hooks(): SharedCostsHooks {
    $hooks = \Drupal::service(SharedCostsHooks::class);
    $this->assertInstanceOf(SharedCostsHooks::class, $hooks);
    return $hooks;
  }

  /**
   * Tests the field settings form: the total field to pick.
   */
  public function testFieldSettingsForm(): void {
    $field = $this->createSharedCostsFields();
    $form = \Drupal::service('entity.form_builder')->getForm($field, 'default');
    $total = $form['settings']['total_field'];
    $this->assertSame('select', $total['#type']);
    // The number fields, after the form's "- Select a field -" option.
    $this->assertSame(['field_total' => 'Total'], array_diff_key($total['#options'], ['' => '']));
    $this->assertSame('field_total', $total['#default_value']);
    $this->assertTrue($total['#required']);

    // Without a number field on the bundle, the form says what to add.
    $total_field = \Drupal::service('entity_type.manager')->getStorage('field_config')->load('entity_test.entity_test.field_total');
    $this->assertNotNull($total_field);
    $total_field->delete();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    $field = \Drupal::service('entity_type.manager')->getStorage('field_config')->load('entity_test.entity_test.field_shared_with');
    $this->assertNotNull($field);
    $form = \Drupal::service('entity.form_builder')->getForm($field, 'default');
    $this->assertSame([], array_diff_key($form['settings']['total_field']['#options'], ['' => '']));
    $this->assertStringContainsString('Add a decimal, float or integer field', (string) $form['settings']['total_field']['#description']);
  }

}
