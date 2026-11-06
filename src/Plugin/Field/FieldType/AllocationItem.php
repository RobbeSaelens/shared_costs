<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\FieldConfigInterface as FieldConfigEntityInterface;

/**
 * A reference to a sharer, with its share of the host entity's total.
 *
 * Like core's file field, an entity reference with extra columns: the share
 * (a percentage, or empty for an equal part of the rest) and the amount, which
 * AllocationItemList works out on save from the total field set in the field
 * settings. The amount is stored, so Views can add it up per sharer.
 *
 * @property string|int|null $target_id
 * @property \Drupal\Core\Entity\EntityInterface|null $entity
 * @property string|int|float|null $share
 * @property string|int|float|null $amount
 *
 * @extends \Drupal\Core\Field\Plugin\Field\FieldType\EntityReferenceItem<\Drupal\Core\Entity\EntityInterface>
 */
#[FieldType(
  id: 'shared_costs_allocation',
  label: new TranslatableMarkup('Shared costs'),
  description: [
    new TranslatableMarkup('Splits an amount of this entity over other entities, equally or by percentage'),
    new TranslatableMarkup('For example: the costs of an expense shared by the horses in a stable'),
  ],
  category: 'reference',
  default_widget: 'shared_costs_allocation_table',
  default_formatter: 'shared_costs_allocation_default',
  list_class: AllocationItemList::class,
  cardinality: FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
)]
class AllocationItem extends EntityReferenceItem {

  /**
   * Field types that can hold the total.
   */
  public const TOTAL_FIELD_TYPES = ['decimal', 'float', 'integer'];

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The field settings and their defaults.
   */
  public static function defaultFieldSettings(): array {
    return ['total_field' => NULL] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['share'] = DataDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Share'))
      ->setDescription(new TranslatableMarkup('The percentage of the total, or empty for an equal part of what the other shares leave.'));
    $properties['amount'] = DataDefinition::create('decimal')
      ->setLabel(new TranslatableMarkup('Amount'))
      ->setDescription(new TranslatableMarkup("This sharer's part of the total, worked out when the entity is saved."));
    return $properties;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, array<string, mixed>>
   *   The schema: columns and indexes.
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    $schema = parent::schema($field_definition);
    $schema['columns']['share'] = [
      'description' => 'The percentage of the total; NULL for an equal part of the rest.',
      'type' => 'numeric',
      'precision' => 5,
      'scale' => 2,
    ];
    $schema['columns']['amount'] = [
      'description' => "The sharer's part of the total.",
      'type' => 'numeric',
      'precision' => 14,
      'scale' => 2,
    ];
    return $schema;
  }

  /**
   * {@inheritdoc}
   *
   * @return \Symfony\Component\Validator\Constraint[]
   *   The constraints.
   */
  public function getConstraints(): array {
    $constraints = parent::getConstraints();
    $constraints[] = $this->getTypedDataManager()->getValidationConstraintManager()->create('ComplexData', [
      'properties' => [
        'share' => [
          'Range' => [
            'min' => 0,
            'max' => 100,
            'notInRangeMessage' => $this->t('%name: a share must be from 0 to 100 %.', ['%name' => (string) $this->getFieldDefinition()->getLabel()]),
          ],
        ],
      ],
    ]);
    return $constraints;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The settings form.
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    $form = parent::fieldSettingsForm($form, $form_state);
    $options = static::totalFieldOptions($this->getFieldDefinition());
    $form['total_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Total'),
      '#description' => $this->t('The number field that holds the amount to split. Each sharer gets its part of it, worked out when the content is saved.'),
      '#options' => $options,
      '#empty_option' => $this->t('- Select a field -'),
      '#default_value' => $this->getSetting('total_field'),
      '#required' => TRUE,
      '#weight' => -10,
    ];
    if ($options === []) {
      $form['total_field']['#description'] = $this->t('Add a decimal, float or integer field to hold the total first.');
    }
    return $form;
  }

  /**
   * Lists the fields on the same bundle that can hold the total.
   *
   * @return array<string, string>
   *   Field labels, keyed by field name.
   */
  public static function totalFieldOptions(FieldDefinitionInterface $field_definition): array {
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($field_definition->getTargetEntityTypeId(), (string) $field_definition->getTargetBundle());
    $options = [];
    foreach ($definitions as $name => $definition) {
      // Stored, editable number fields: not IDs and other read-only fields.
      if (in_array($definition->getType(), self::TOTAL_FIELD_TYPES, TRUE) && !$definition->isReadOnly() && !$definition->isComputed()) {
        $options[$name] = (string) $definition->getLabel();
      }
    }
    asort($options);
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, string[]>
   *   The dependencies, by type.
   */
  public static function calculateDependencies(FieldDefinitionInterface $field_definition): array {
    $dependencies = parent::calculateDependencies($field_definition);
    if ($total = static::totalFieldConfig($field_definition)) {
      $dependencies['config'][] = $total->getConfigDependencyName();
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field.
   * @param array<string, array<string, mixed>> $dependencies
   *   The dependencies being removed, by type and name.
   */
  public static function onDependencyRemoval(FieldDefinitionInterface $field_definition, array $dependencies): bool {
    $changed = parent::onDependencyRemoval($field_definition, $dependencies);
    // Removing the total field must not remove this field (and its data):
    // forget the setting instead, amounts stay as they are.
    $total = static::totalFieldConfig($field_definition);
    if ($total && $field_definition instanceof FieldConfigInterface && isset($dependencies['config'][$total->getConfigDependencyName()])) {
      $field_definition->setSetting('total_field', NULL);
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Loads the total field's config, when it's a configurable field.
   */
  protected static function totalFieldConfig(FieldDefinitionInterface $field_definition): ?FieldConfigEntityInterface {
    $name = $field_definition->getSetting('total_field');
    if (!is_string($name) || $name === '') {
      return NULL;
    }
    return FieldConfig::loadByName($field_definition->getTargetEntityTypeId(), (string) $field_definition->getTargetBundle(), $name);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   None: this type is offered once.
   */
  public static function getPreconfiguredOptions(): array {
    // Unlike plain entity references, offer this type once in Field UI.
    return [];
  }

}
