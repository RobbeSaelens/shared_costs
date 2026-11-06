<?php

declare(strict_types=1);

namespace Drupal\stable_manager_theme\Htmx;

use Drupal\Core\Htmx\Htmx;

/**
 * HTMX settings for moving around the app without loading whole pages.
 *
 * Links inside a boosted element fetch their page as usual, but only its main
 * content (and the navigation and messages, so the active link and new
 * messages show) is swapped
 * into this page, with a view transition, and the URL is pushed to the
 * history. Without JavaScript they are ordinary links.
 */
final class AppNavigation {

  /**
   * The element that holds the page's main content.
   */
  public const MAIN = '#main-content';

  /**
   * The element that holds the navigation.
   */
  public const NAVIGATION = '#app-nav';

  /**
   * The element that holds the status messages.
   */
  public const MESSAGES = '#app-toasts';

  /**
   * The progress bar shown while a page loads.
   */
  public const PROGRESS = '#app-progress';

  /**
   * Boosts the links in an element: they swap the main content.
   */
  public static function boost(): Htmx {
    return (new Htmx())
      ->boost()
      ->select(self::MAIN)
      ->target(self::MAIN)
      ->selectOob([self::NAVIGATION, self::MESSAGES])
      ->swap('outerHTML', 'transition:true show:window:top', FALSE)
      ->indicator(self::PROGRESS);
  }

  /**
   * Turns links off for boosting: for pages outside the app (admin pages).
   */
  public static function unboost(): Htmx {
    return (new Htmx())->boost(FALSE);
  }

}
