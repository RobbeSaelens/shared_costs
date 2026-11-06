<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Hook;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItem;
use Drupal\views\FieldViewsDataProvider;

/**
 * Hook implementations for shared_costs.
 */
final class SharedCostsHooks {

  use StringTranslationTrait;

  /**
   * Builds the hooks.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module list, for this module's path.
   * @param \Drupal\views\FieldViewsDataProvider|null $fieldViewsDataProvider
   *   Views' field data, when Views is installed (it's optional).
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleExtensionList $moduleList,
    private readonly ?FieldViewsDataProvider $fieldViewsDataProvider = NULL,
  ) {}

  /**
   * Implements hook_locale_translation_projects_alter().
   *
   * The translations ship with the module: point locale at them, wherever the
   * module is installed (modules/custom, modules/contrib, ...).
   *
   * @param array<string, array<string, mixed>> $projects
   *   The projects that locale gets translations for.
   */
  #[Hook('locale_translation_projects_alter')]
  public function localeTranslationProjectsAlter(array &$projects): void {
    if (isset($projects['shared_costs'])) {
      $projects['shared_costs']['info']['interface translation server pattern'] = $this->moduleList->getPath('shared_costs') . '/translations/%language.po';
    }
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.shared_costs') {
      return NULL;
    }
    return '<h2>' . $this->t('About') . '</h2>'
      . '<p>' . $this->t('The <em>Shared costs</em> field splits an amount over other entities: the costs of an expense over the horses in a stable, of a bill over housemates. Each sharer gets an equal part, or a percentage you give it.') . '</p>'
      . '<h2>' . $this->t('Uses') . '</h2>'
      . '<dl><dt>' . $this->t('Adding the field') . '</dt>'
      . '<dd>' . $this->t('Add a decimal, float or integer field for the total first, then a <em>Shared costs</em> field. In its settings, pick what can share the costs and which field holds the total.') . '</dd>'
      . '<dt>' . $this->t('Amounts') . '</dt>'
      . '<dd>' . $this->t('When the content is saved, every sharer gets its part of the total, in cents that always add up to the total. A sharer without a percentage gets an equal part of what the percentages leave.') . '</dd>'
      . '<dt>' . $this->t('Reports') . '</dt>'
      . '<dd>' . $this->t('In Views, the amount can be added up per sharer (through the relationship to the sharer), and date fields get a <em>year and month</em> field to add costs up per month.') . '</dd></dl>';
  }

  /**
   * Implements hook_field_views_data().
   *
   * Core adds the relationship to the referenced entity only for its own
   * entity reference field type: this does the same for shared costs.
   *
   * @return array<string, array<string, mixed>>
   *   The Views data of the field.
   *
   * @see \Drupal\views\Hook\ViewsViewsHooks::fieldViewsData()
   */
  #[Hook('field_views_data')]
  public function fieldViewsData(FieldStorageConfigInterface $field_storage): array {
    $data = $this->fieldViewsDataProvider?->defaultFieldImplementation($field_storage) ?? [];
    $field_name = $field_storage->getName();
    $entity_type_id = $field_storage->getTargetEntityTypeId();
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $target_type_id = $field_storage->getSetting('target_type');
    $target_type = $this->entityTypeManager->getDefinition($target_type_id);
    $target_table = $target_type->getDataTable() ?: $target_type->getBaseTable();
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $table_mapping = $storage instanceof SqlEntityStorageInterface ? $storage->getTableMapping() : NULL;

    foreach (array_keys($data) as $table) {
      if ($target_type instanceof ContentEntityTypeInterface && is_string($target_table) && $table_mapping instanceof DefaultTableMapping) {
        $args = ['@label' => $target_type->getLabel(), '@field_name' => $field_name];
        $data[$table][$field_name]['relationship'] = [
          'title' => $this->t('@label sharing @field_name', $args),
          'label' => $this->t('@field_name: @label', $args),
          'group' => $entity_type->getLabel(),
          'help' => $this->t('The sharers, to add amounts up per sharer. Appears in: @bundles.', ['@bundles' => implode(', ', $field_storage->getBundles())]),
          'id' => 'standard',
          'base' => $target_table,
          'entity type' => $target_type_id,
          'base field' => $target_type->getKey('id'),
          'relationship field' => $field_name . '_target_id',
        ];
        $data[$target_table]['reverse__' . $entity_type_id . '__' . $field_name]['relationship'] = [
          'title' => $this->t('@entity sharing costs through @field_name', [
            '@entity' => $entity_type->getLabel(),
            '@field_name' => $field_name,
          ]),
          'label' => $this->t('@field_name', ['@field_name' => $field_name]),
          'group' => $target_type->getLabel(),
          'help' => $this->t('The @entity whose @field_name includes this @label.', [
            '@entity' => $entity_type->getPluralLabel(),
            '@field_name' => $field_name,
            '@label' => $target_type->getSingularLabel(),
          ]),
          'id' => 'entity_reverse',
          'base' => $entity_type->getDataTable() ?: $entity_type->getBaseTable(),
          'entity_type' => $entity_type_id,
          'base field' => $entity_type->getKey('id'),
          'field_name' => $field_name,
          'field table' => $table_mapping->getDedicatedDataTableName($field_storage),
          'field field' => $field_name . '_target_id',
          'join_extra' => [['field' => 'deleted', 'value' => 0, 'numeric' => TRUE]],
        ];
      }
      // An argument that shows the sharer's label as the title.
      $data[$table][$field_name . '_target_id']['argument']['id'] = 'entity_target_id';
      $data[$table][$field_name . '_target_id']['argument']['target_entity_type_id'] = $target_type_id;
      // The amount and share as plain numbers: to add up per sharer (SUM of
      // one column, which every database accepts) and to feed charts.
      foreach (['amount', 'share'] as $column) {
        if (isset($data[$table][$field_name . '_' . $column])) {
          $data[$table][$field_name . '_' . $column]['field'] = [
            'id' => 'numeric',
            'float' => TRUE,
            'click sortable' => TRUE,
          ];
        }
      }
    }
    return $data;
  }

  /**
   * Implements hook_field_views_data_alter().
   *
   * Gives number fields a plain number field to add up, and date fields a
   * "year and month" field (to add costs up per month) and argument (to show
   * one month, titled by its name).
   *
   * @param array<string, array<string, mixed>> $data
   *   The Views data of the field.
   * @param \Drupal\field\FieldStorageConfigInterface $field_storage
   *   The field.
   */
  #[Hook('field_views_data_alter')]
  public function fieldViewsDataAlter(array &$data, FieldStorageConfigInterface $field_storage): void {
    $field_name = $field_storage->getName();
    $type = $field_storage->getType();
    foreach ($data as $table => $table_data) {
      if (!isset($table_data[$field_name . '_value'])) {
        continue;
      }
      // Number fields: their value as a plain number, so totals can be added
      // up (per month, per category) with a SUM of that one column. Core's
      // field handler would also SUM text columns, which PostgreSQL refuses.
      if (in_array($type, AllocationItem::TOTAL_FIELD_TYPES, TRUE) && !isset($table_data[$field_name . '_value']['field'])) {
        $data[$table][$field_name . '_value']['field'] = [
          'id' => 'numeric',
          'float' => $type !== 'integer',
          'click sortable' => TRUE,
        ];
      }
      if ($type !== 'datetime') {
        continue;
      }
      $data[$table][$field_name . '_year_month'] = [
        'title' => new TranslatableMarkup('@field_name (year and month)', ['@field_name' => $field_name]),
        'help' => new TranslatableMarkup('The year and month of the date, to group by month (for example to add amounts up per month).'),
        'group' => $table_data[$field_name . '_value']['group'] ?? NULL,
        'field' => [
          'id' => 'shared_costs_year_month',
          'field_name' => $field_name,
          'entity_type' => $field_storage->getTargetEntityTypeId(),
          'real field' => $field_name . '_value',
          'click sortable' => TRUE,
        ],
        // Core's year-and-month argument, but titled "November 2026".
        'argument' => [
          'id' => 'shared_costs_year_month',
          'field' => $field_name . '_value',
          'field_name' => $field_name,
          'entity_type' => $field_storage->getTargetEntityTypeId(),
        ],
      ];
    }
  }

}
