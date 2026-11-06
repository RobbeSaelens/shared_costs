<?php

declare(strict_types=1);

namespace Drupal\stal_manager_theme\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations from the Starterkit theme.
 */
final class StalManagerThemeHooks {

  /**
   * Implements hook_preprocess_image_widget().
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_image_widget')]
  public function preprocessImageWidget(array &$variables): void {
    $data = &$variables['data'];
    // This prevents image widget templates from rendering preview container
    // HTML to users that do not have permission to access these previews.
    // @todo revisit in https://drupal.org/node/953034
    // @todo revisit in https://drupal.org/node/3114318
    if (isset($data['preview']['#access']) && $data['preview']['#access'] === FALSE) {
      unset($data['preview']);
    }
  }

}
