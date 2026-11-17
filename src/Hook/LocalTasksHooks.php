<?php

declare(strict_types=1);

namespace Drupal\shared_costs\Hook;

use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Keeps the tabs of the login page, after the login form was a 403 page.
 *
 * The front end recipe shows visitors the login form for what is private
 * (page.403 is /user/login). Core renders that 403 page in a subrequest for
 * the user.login route, where the tabs ("Log in", "Reset your password") come
 * out empty, and caches the tabs by route only. So once a visitor had opened a
 * private page, the login page itself lost its tabs, until the caches were
 * cleared. The 403 subrequest has _exception_statuscode in its query: varying
 * the tabs by it keeps the two apart.
 */
final class LocalTasksHooks {

  /**
   * Implements hook_block_build_BASE_BLOCK_ID_alter() for the local tasks.
   *
   * @param array<string, mixed> $build
   *   The block's render array.
   * @param \Drupal\Core\Block\BlockPluginInterface $block
   *   The block.
   */
  #[Hook('block_build_local_tasks_block_alter')]
  public function localTasksBlockBuildAlter(array &$build, BlockPluginInterface $block): void {
    $build['#cache']['contexts'][] = 'url.query_args:_exception_statuscode';
  }

}
