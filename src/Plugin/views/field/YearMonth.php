<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Plugin\views\field;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItem;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\FieldAPIHandlerTrait;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ResultRow;

/**
 * The year and month of a date field, to group (and add up) by month.
 *
 * Core Views can group by a date, but not by its month. This field selects
 * the date as "YYYY-MM", so with aggregation on, amounts add up per month.
 * It sorts by that same column: sorting by the date itself would not work
 * under GROUP BY on MySQL and PostgreSQL.
 */
#[ViewsField('shared_costs_year_month')]
final class YearMonth extends FieldPluginBase {

  use FieldAPIHandlerTrait;

  /**
   * Builds the field.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter, for month names.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The options and their defaults.
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options['format'] = ['default' => 'Y-m'];
    $options['order'] = ['default' => 'ASC'];
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state): void {
    parent::buildOptionsForm($form, $form_state);
    $form['format'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Format'),
      '#description' => $this->t('How to show the month, as a <a href="https://www.php.net/manual/en/datetime.format.php">PHP date format</a>: <em>Y-m</em> for 2026-11, <em>M Y</em> for Nov 2026, <em>F</em> for November.'),
      '#default_value' => $this->options['format'],
      '#required' => TRUE,
    ];
    $form['order'] = [
      '#type' => 'select',
      '#title' => $this->t('Sort by month'),
      '#description' => $this->t('Sort the results by this month. Sorting by the date itself is not possible when results are grouped by month.'),
      '#options' => [
        '' => $this->t('No'),
        'ASC' => $this->t('Oldest first'),
        'DESC' => $this->t('Newest first'),
      ],
      '#default_value' => $this->options['order'],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @param bool $use_groupby
   *   Whether the view groups results (aggregation).
   */
  public function query($use_groupby = FALSE): void {
    assert($this->query instanceof Sql);
    $this->ensureMyTable();
    // Date-only values have no time zone, so no offset (as core's datetime
    // handlers do).
    $offset = $this->getFieldStorageDefinition()?->getSetting('datetime_type') !== DateTimeItem::DATETIME_TYPE_DATE;
    $date = $this->query->getDateField($this->tableAlias . '.' . $this->realField, TRUE, $offset);
    $this->field_alias = $this->query->addField(NULL, $this->query->getDateFormat($date, 'Y-m', TRUE), $this->tableAlias . '_' . $this->field);
    if (in_array($this->options['order'], ['ASC', 'DESC'], TRUE)) {
      $this->query->addOrderBy(NULL, NULL, $this->options['order'], $this->field_alias);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clickSort($order): void {
    assert($this->query instanceof Sql);
    // The alias query() added: sorting by it works under GROUP BY.
    $this->query->addOrderBy(NULL, NULL, $order, $this->field_alias);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values): string {
    $value = $this->getValue($values);
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}$/', $value)) {
      return '';
    }
    if ($this->options['format'] === 'Y-m') {
      return $value;
    }
    // The middle of the first day: no time zone moves it to another month.
    $time = strtotime($value . '-01 12:00:00');
    return $time === FALSE ? '' : $this->dateFormatter->format($time, 'custom', (string) $this->options['format']);
  }

}
