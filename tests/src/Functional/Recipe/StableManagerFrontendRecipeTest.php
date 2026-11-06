<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Functional\Recipe;

use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Stable Manager front end recipe: the theme, Gin and a private site.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class StableManagerFrontendRecipeTest extends GenericRecipeTestBase {

  use StableManagerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function getRecipePath(): string {
    return $this->recipe('stable_manager_frontend');
  }

  /**
   * Tests the themes, Gin's settings, the private site and the dashboard.
   */
  public function testFrontend(): void {
    $this->doApply();
    $site = $this->config('system.site');
    // The look and the private site, but not the site's name: that's the
    // site's own.
    $this->assertSame('/stable', $site->get('page.front'));
    $this->assertSame('/user/login', $site->get('page.403'));
    $this->assertNotSame('Stable Manager', $site->get('name'));
    $this->assertSame('stable_manager_theme', $this->config('system.theme')->get('default'));
    $this->assertSame('gin', $this->config('system.theme')->get('admin'));
    $this->assertTrue($this->config('node.settings')->get('use_admin_theme'));
    $modules = $this->config('core.extension')->get('module');
    foreach (['block', 'toolbar', 'gin_toolbar', 'admin_toolbar', 'admin_toolbar_tools'] as $module) {
      $this->assertArrayHasKey($module, $modules);
    }

    // Gin: in the theme's brown, with a horizontal bar, and light by default
    // ('0'; not '1' for dark, nor 'auto' for the system) that people can
    // change for themselves.
    $gin = $this->config('gin.settings');
    $this->assertSame('custom', $gin->get('preset_accent_color'));
    $this->assertSame('#6f4520', $gin->get('accent_color'));
    $this->assertSame('gin', $gin->get('preset_focus_color'));
    $this->assertSame('0', $gin->get('enable_darkmode'));
    $this->assertTrue($gin->get('show_user_theme_settings'));
    $this->assertSame('horizontal', $gin->get('classic_toolbar'));

    // The site is private: visitors get the login form, and nothing else.
    $this->assertSame([], $this->config('user.role.anonymous')->get('permissions'));
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->fieldExists('name');
    $this->drupalGet('stable/expenses');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->fieldExists('name');

    // A stable manager sees the dashboard, in the theme with its blocks: the
    // branding and the main navigation.
    $manager = $this->createStableManager();
    $this->assertContains('view the administration theme', $this->config('user.role.stable_manager')->get('permissions'));
    $this->drupalLogin($manager);
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->titleEquals('Dashboard | ' . $site->get('name'));
    $this->assertSession()->elementTextEquals('css', '.site-branding__name', $site->get('name'));
    $this->assertSession()->elementTextEquals('css', '#app-nav a[aria-current="page"]', 'Dashboard');
    $this->assertSession()->linkExists('Add an expense');

    // Adding an expense happens in Gin, in its light version.
    $this->clickLink('Add an expense');
    $this->assertSession()->responseContains('/themes/contrib/gin/');
    $this->assertSession()->fieldExists('title[0][value]');
    $this->assertSession()->responseContains('"darkmode":"0"');

    // Dark is a choice in one's own account, and it's only theirs.
    $this->drupalGet($manager->toUrl('edit-form'));
    $this->submitForm(['enable_user_settings' => TRUE, 'enable_darkmode' => '1'], 'Save');
    $this->drupalGet('node/add/expense');
    $this->assertSession()->responseContains('"darkmode":"1"');
    $this->drupalLogin($this->createStableManager());
    $this->drupalGet('node/add/expense');
    $this->assertSession()->responseContains('"darkmode":"0"');

    // Admins get the admin menu with its submenus, which open on hover, in
    // Gin's horizontal toolbar.
    $this->drupalLogin($this->rootUser);
    // Not admin/content: on PHP 8.5, core's content view triggers a
    // deprecation (a NULL langcode in ContentEntityBase::hasTranslation()).
    $this->drupalGet('admin/structure');
    $this->assertSession()->responseContains('"toolbar_variant":"horizontal"');
    $this->assertSession()->responseContains('/modules/contrib/admin_toolbar/');
    $this->assertSession()->elementExists('css', '#toolbar-item-administration-tray li.menu-item--expanded.menu-item__system-admin_structure > ul.toolbar-menu a[href$="/admin/structure/block"]');
  }

}
