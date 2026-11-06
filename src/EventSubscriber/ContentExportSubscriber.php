<?php

declare(strict_types=1);

namespace Drupal\shared_costs\EventSubscriber;

use Drupal\Core\DefaultContent\ExportMetadata;
use Drupal\Core\DefaultContent\PreExportEvent;
use Drupal\shared_costs\Plugin\Field\FieldType\AllocationItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Exports shared costs like entity references, in core's default content.
 *
 * Core refers to referenced entities by UUID (and lists them as dependencies)
 * only for its own reference field types. Shared costs are references too: use
 * the same callback for them, so `content:export` gives portable files, for
 * example for recipes. Open (empty) shares stay empty.
 */
final class ContentExportSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [PreExportEvent::class => 'onPreExport'];
  }

  /**
   * Reuses core's entity reference export for shared costs.
   */
  public function onPreExport(PreExportEvent $event): void {
    $reference = $event->getCallbacks()['field_item:entity_reference'] ?? NULL;
    if ($reference === NULL) {
      return;
    }
    $event->setCallback('field_item:shared_costs_allocation', static function (AllocationItem $item, ExportMetadata $metadata) use ($reference): ?array {
      $values = $reference($item, $metadata);
      if (is_array($values)) {
        // Typed data casts an empty share to "0.0", but empty means "an equal
        // part of the rest": keep it as it is. The amount is worked out again
        // when the content is imported (saved), so it isn't exported.
        $values['share'] = $item->share;
        unset($values['amount']);
      }
      return $values;
    });
  }

}
