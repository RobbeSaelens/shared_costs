<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItem;

/**
 * Lists the sharers with their share and amount: "Luna · 50 % · 12.50".
 *
 * Builds on the entity reference label formatter, so sharers the viewer may
 * not see are left out, and labels can link to the sharer.
 */
#[FieldFormatter(
  id: 'shared_costs_allocation_default',
  label: new TranslatableMarkup('Sharers with share and amount'),
  field_types: ['shared_costs_allocation'],
)]
final class AllocationFormatter extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The settings and their defaults.
   */
  public static function defaultSettings(): array {
    return [
      'show_share' => TRUE,
      'show_amount' => TRUE,
      'decimal_separator' => '.',
      'thousand_separator' => '',
      'prefix' => '',
      'suffix' => '',
    ] + parent::defaultSettings();
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
    $elements = parent::settingsForm($form, $form_state);
    $elements['show_share'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the share (%)'),
      '#default_value' => $this->getSetting('show_share'),
    ];
    $elements['show_amount'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the amount'),
      '#default_value' => $this->getSetting('show_amount'),
    ];
    $elements['decimal_separator'] = [
      '#type' => 'select',
      '#title' => $this->t('Decimal marker'),
      '#options' => ['.' => $this->t('Decimal point'), ',' => $this->t('Comma')],
      '#default_value' => $this->getSetting('decimal_separator'),
    ];
    $elements['thousand_separator'] = [
      '#type' => 'select',
      '#title' => $this->t('Thousand marker'),
      '#options' => [
        '' => $this->t('- None -'),
        '.' => $this->t('Decimal point'),
        ',' => $this->t('Comma'),
        ' ' => $this->t('Space'),
        "'" => $this->t('Apostrophe'),
      ],
      '#default_value' => $this->getSetting('thousand_separator'),
    ];
    $elements['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prefix'),
      '#description' => $this->t('Before the amount, for example "€ ".'),
      '#default_value' => $this->getSetting('prefix'),
      '#size' => 10,
    ];
    $elements['suffix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Suffix'),
      '#description' => $this->t('After the amount, for example " EUR".'),
      '#default_value' => $this->getSetting('suffix'),
      '#size' => 10,
    ];
    return $elements;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<int, string|\Stringable>
   *   The summary lines.
   */
  public function settingsSummary(): array {
    $summary = parent::settingsSummary();
    $shown = array_filter([
      $this->getSetting('show_share') ? $this->t('share') : NULL,
      $this->getSetting('show_amount') ? $this->t('amount (@example)', ['@example' => $this->formatAmount('1234.50')]) : NULL,
    ]);
    $summary[] = $shown === [] ? $this->t('Sharers only') : $this->t('With @shown', ['@shown' => implode(', ', array_map('strval', $shown))]);
    return $summary;
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Field\EntityReferenceFieldItemList<\Drupal\Core\Entity\EntityInterface> $items
   *   The field values.
   * @param string $langcode
   *   The language.
   *
   * @return array<int, array<int|string, mixed>>
   *   A render array per visible sharer.
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = parent::viewElements($items, $langcode);
    $amounts = [];
    foreach ($items as $item) {
      $amounts[] = (float) ($item->amount ?? 0);
    }
    $sum = array_sum($amounts);

    foreach (array_keys($this->getEntitiesToView($items, $langcode)) as $delta) {
      $item = $items->get($delta);
      if (!isset($elements[$delta]) || !$item instanceof AllocationItem) {
        continue;
      }
      $parts = [];
      if ($this->getSetting('show_share')) {
        // The share as given; for an open share, what it came down to.
        $share = $item->share ?? ($sum != 0 && $item->amount !== NULL ? (float) $item->amount / $sum * 100 : NULL);
        if ($share !== NULL) {
          $parts[] = $this->formatShare((float) $share);
        }
      }
      if ($this->getSetting('show_amount') && $item->amount !== NULL) {
        $parts[] = $this->formatAmount($item->amount);
      }
      if ($parts !== []) {
        $elements[$delta] = [
          'label' => $elements[$delta],
          'details' => ['#plain_text' => ' · ' . implode(' · ', $parts)],
        ];
      }
    }
    return $elements;
  }

  /**
   * Formats a share: "50 %", "33.33 %".
   */
  protected function formatShare(float $share): string {
    $text = rtrim(rtrim(number_format($share, 2, (string) $this->getSetting('decimal_separator'), ''), '0'), (string) $this->getSetting('decimal_separator'));
    return $text . ' %';
  }

  /**
   * Formats an amount with the separators, prefix and suffix from the settings.
   */
  protected function formatAmount(string|int|float $amount): string {
    $number = number_format((float) $amount, 2, (string) $this->getSetting('decimal_separator'), (string) $this->getSetting('thousand_separator'));
    return $this->getSetting('prefix') . $number . $this->getSetting('suffix');
  }

}
