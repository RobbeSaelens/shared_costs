<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldWidget\OptionsWidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Htmx\Htmx;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\shared_costs\Allocation\AllocationCalculator;
use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItemList;

/**
 * A table of everything that can share the costs: tick it, give a percentage.
 *
 * An empty percentage is an equal part of what the others leave, so ticking
 * two sharers and leaving both empty splits the costs 50/50. Sharers that
 * can't be picked any more (an unpublished horse) stay listed, read-only, so
 * editing older costs never changes how they were split.
 *
 * Each row shows the sharer's amount. With HTMX (core's drupal.htmx), the
 * amounts follow the total and the shares as they are typed: the table asks
 * SplitPreviewController for them, without submitting the form.
 */
#[FieldWidget(
  id: 'shared_costs_allocation_table',
  label: new TranslatableMarkup('Table of sharers'),
  field_types: ['shared_costs_allocation'],
  multiple_values: TRUE,
)]
final class AllocationTableWidget extends OptionsWidgetBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The settings and their defaults.
   */
  public static function defaultSettings(): array {
    return ['decimal_separator' => '.'] + parent::defaultSettings();
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
  public function settingsForm(array $form, FormStateInterface $form_state): array {
    return [
      'decimal_separator' => [
        '#type' => 'select',
        '#title' => $this->t('Decimal marker of the amounts'),
        '#options' => ['.' => $this->t('Decimal point'), ',' => $this->t('Comma')],
        '#default_value' => $this->getSetting('decimal_separator'),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, string|\Stringable>
   *   The summary lines.
   */
  public function settingsSummary(): array {
    return [$this->t('Amounts like @example', ['@example' => number_format(1234.5, 2, (string) $this->getSetting('decimal_separator'), '')])];
  }

  /**
   * The ID of the <output> that shows a sharer's amount.
   *
   * Shared with SplitPreviewController, which swaps new amounts into it.
   */
  public static function outputId(string $field_name, string|int $sharer): string {
    return Html::cleanCssIdentifier('shared-costs-' . $field_name . '-' . $sharer);
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field values.
   * @param int $delta
   *   The delta (always 0: this widget handles all values).
   * @param array<string, mixed> $element
   *   The form element.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The table.
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $options = $this->getOptions($items->getEntity());
    $current = [];
    $labels = [];
    if ($items instanceof AllocationItemList) {
      foreach ($items->allocations() as $item) {
        if ($item->target_id !== NULL) {
          $current[(string) $item->target_id] = $item->share;
          $labels[(string) $item->target_id] = (string) ($item->entity?->label() ?? $item->target_id);
        }
      }
    }

    $element += [
      '#type' => 'table',
      '#caption' => $element['#title'],
      '#header' => [$this->t('Sharer'), $this->t('Share (%)'), $this->t('Amount')],
      '#empty' => $this->t('There is nothing to share the costs with yet.'),
      '#element_validate' => [[static::class, 'validateTable']],
      '#attributes' => ['class' => ['shared-costs-allocation-table']],
    ];
    $amounts = $this->amounts($items, $current);
    $locked = array_diff_key($current, $options);
    foreach ($options as $id => $label) {
      // An equal part is a NULL share: ticked all the same.
      $element[$id] = $this->row((string) $id, (string) $label, array_key_exists($id, $current), $current[$id] ?? NULL, $amounts[$id] ?? '', FALSE);
    }
    // Sharers the costs were split with, that can't be picked any more.
    foreach ($locked as $id => $share) {
      $element[$id] = $this->row((string) $id, $labels[$id], TRUE, $share, $amounts[$id] ?? '', TRUE);
    }
    $element['#description'] = $this->t('Tick everything that shares these costs. Leave a share empty for an equal part of what the other shares leave.');

    // Live amounts: ask for them when the shares or the total change.
    $field_name = $this->fieldDefinition->getName();
    $total_field = $this->fieldDefinition->getSetting('total_field');
    if (is_string($total_field) && $total_field !== '') {
      $total_input = "[name='{$total_field}[0][value]']";
      (new Htmx())
        ->get(Url::fromRoute('shared_costs.split_preview'))
        ->trigger("change, input changed delay:300ms, input changed delay:300ms from:{$total_input}, change from:{$total_input}")
        ->include("[name^='{$field_name}['], {$total_input}")
        ->vals([
          'field' => $field_name,
          'total' => $total_field,
          'decimal' => (string) $this->getSetting('decimal_separator'),
          // Disabled inputs aren't sent: send what can't be changed here.
          'locked' => (string) json_encode(array_map(static fn ($share): ?string => $share === NULL ? NULL : (string) $share, $locked), JSON_FORCE_OBJECT),
        ])
        ->swap('none')
        ->applyTo($element);
    }
    return $element;
  }

  /**
   * Works out the amounts to show first: from the current total and shares.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field values.
   * @param array<string, string|int|float|null> $shares
   *   The current shares, keyed by sharer.
   *
   * @return array<string, string>
   *   The amounts, keyed by sharer; none without a total.
   */
  protected function amounts(FieldItemListInterface $items, array $shares): array {
    $total_field = $this->fieldDefinition->getSetting('total_field');
    $entity = $items->getEntity();
    $total = is_string($total_field) && $entity->hasField($total_field) ? $entity->get($total_field)->value : NULL;
    if ($shares === [] || !is_numeric($total)) {
      return [];
    }
    try {
      $amounts = (new AllocationCalculator())->split($total, $shares);
    }
    catch (\InvalidArgumentException) {
      return [];
    }
    $separator = (string) $this->getSetting('decimal_separator');
    return array_map(static fn (string $amount): string => number_format((float) $amount, 2, $separator, ''), $amounts);
  }

  /**
   * Builds a row: a checkbox with the sharer's name, the share, the amount.
   *
   * @return array<string, mixed>
   *   The row.
   */
  protected function row(string $id, string $label, bool $selected, string|int|float|null $share, string $amount, bool $locked): array {
    return [
      'selected' => [
        '#type' => 'checkbox',
        '#title' => $label,
        '#default_value' => $selected,
        '#disabled' => $locked,
        '#description' => $locked ? $this->t('Can no longer be picked; kept as it was.') : NULL,
      ],
      'share' => [
        '#type' => 'number',
        '#title' => $this->t('Share of @label', ['@label' => $label]),
        '#title_display' => 'invisible',
        '#min' => 0,
        '#max' => 100,
        '#step' => 0.01,
        '#size' => 6,
        '#default_value' => $share,
        '#disabled' => $locked,
        '#placeholder' => $this->t('equal'),
      ],
      'amount' => [
        '#type' => 'html_tag',
        '#tag' => 'output',
        '#value' => $amount,
        '#attributes' => [
          'id' => static::outputId($this->fieldDefinition->getName(), $id),
          'aria-label' => (string) $this->t('Amount for @label', ['@label' => $label]),
        ],
      ],
    ];
  }

  /**
   * Checks that a required field has at least one sharer.
   *
   * @param array<string, mixed> $element
   *   The table.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateTable(array $element, FormStateInterface $form_state): void {
    if (empty($element['#required'])) {
      return;
    }
    $rows = $form_state->getValue($element['#parents']);
    foreach (is_array($rows) ? $rows : [] as $row) {
      if (!empty($row['selected'])) {
        return;
      }
    }
    $form_state->setError($element, new TranslatableMarkup('Pick at least one for @name.', ['@name' => $element['#title']]));
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string|int, mixed> $values
   *   The submitted rows, keyed by sharer ID.
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<int, array{target_id: string|int, share: string|null}>
   *   The field values.
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    $items = [];
    foreach ($values as $id => $row) {
      if (is_array($row) && !empty($row['selected'])) {
        $share = $row['share'] ?? '';
        $items[] = ['target_id' => $id, 'share' => $share === '' ? NULL : (string) $share];
      }
    }
    return $items;
  }

}
