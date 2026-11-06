<?php

declare(strict_types=1);

// cspell:ignore dierenarts
namespace Drupal\Tests\shared_costs\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Schema\ArrayElement;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Stable Manager recipes' config: valid, complete, and Dutch.
 *
 * Kernel tests check every config save against its schema, so applying the
 * recipes here proves all their config (views, charts, fields) is valid.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class RecipesTest extends KernelTestBase {

  /**
   * Strings that are the same in English and Dutch.
   */
  private const SAME_IN_DUTCH = ['Dashboard', 'Status', 'Transport'];

  /**
   * {@inheritdoc}
   *
   * The modules that every site has (path_alias is required, and the
   * statement's months use it).
   */
  protected static $modules = ['system', 'user', 'path_alias'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user']);
  }

  /**
   * Tests that the recipe installs its config, and can be applied again.
   */
  public function testStableManager(): void {
    $this->applyRecipe('stable_manager');
    $names = $this->recipeConfigNames();
    $this->assertNotEmpty($names);
    $this->assertSame($names, array_values(array_intersect($names, \Drupal::service('config.storage')->listAll())));
    $this->assertSame('chartjs', $this->config('charts.settings')->get('charts_default_settings.library'));

    // Applying it again changes nothing, and doesn't fail.
    $before = \Drupal::service('config.storage')->readMultiple($names);
    $this->applyRecipe('stable_manager');
    $this->assertSame($before, \Drupal::service('config.storage')->readMultiple($names));
  }

  /**
   * Tests the API recipe: JSON:API that can write, for stable managers only.
   *
   * What the API does is tested over HTTP, in StableManagerApiRecipeTest.
   */
  public function testApi(): void {
    // On a site where visitors see published content, as most sites start.
    $this->applyRecipe('stable_manager');
    Role::load(RoleInterface::ANONYMOUS_ID)?->grantPermission('access content')->save();
    $this->applyRecipe('stable_manager_api');
    $this->assertTrue(\Drupal::moduleHandler()->moduleExists('jsonapi'));
    $this->assertFalse($this->config('jsonapi.settings')->get('read_only'));
    $role = $this->config('user.role.stable_manager');
    $this->assertContains('administer node published status', $role->get('permissions'));
    $this->assertContains('delete any inventory_item content', $role->get('permissions'));
    $this->assertSame([], $this->config('user.role.anonymous')->get('permissions'));

    // Applying it again changes nothing, and doesn't fail.
    $names = ['jsonapi.settings', 'user.role.anonymous', 'user.role.stable_manager'];
    $before = \Drupal::service('config.storage')->readMultiple($names);
    $this->applyRecipe('stable_manager_api');
    $this->assertSame($before, \Drupal::service('config.storage')->readMultiple($names));
  }

  /**
   * Tests that the Dutch recipe makes the site Dutch, with nothing left over.
   */
  public function testDutch(): void {
    $this->applyRecipe('stable_manager_nl');
    $this->assertSame('nl', $this->config('system.site')->get('default_langcode'));
    $this->assertSame(['en' => 'en', 'nl' => ''], $this->config('language.negotiation')->get('url.prefixes'));

    $typed_config = \Drupal::service('config.typed');
    $english = [];
    foreach ($this->recipeConfigNames() as $name) {
      $source = $typed_config->createFromNameAndData($name, Yaml::decode((string) file_get_contents($this->recipePath('stable_manager') . "/config/$name.yml")));
      $active = $typed_config->get($name);
      $strings = $this->shownStrings($source);
      if ($strings) {
        $this->assertSame('nl', $this->config($name)->get('langcode'), "$name is Dutch.");
      }
      foreach ($strings as $path => $value) {
        $dutch = $this->shownStrings($active)[$path] ?? NULL;
        if ($dutch === $value && !in_array($value, self::SAME_IN_DUTCH, TRUE)) {
          $english[] = "$name:$path: $value";
        }
      }
    }
    $this->assertSame([], $english, 'Every string that people see is Dutch.');

    // Dutch numbers, except where Charts reads them.
    $dashboard = $this->config('views.view.stable_dashboard');
    $this->assertSame(',', $dashboard->get('display.default.display_options.fields.field_total_value.decimal'));
    $this->assertSame('.', $dashboard->get('display.attachment_3.display_options.fields.field_total_value.decimal'));
    $this->assertSame('Dierenarts', $this->config('field.storage.node.field_cost_category')->get('settings.allowed_values.0.label'));
  }

  /**
   * Applies one of the module's recipes.
   */
  private function applyRecipe(string $name): void {
    RecipeRunner::processRecipe(Recipe::createFromDirectory($this->recipePath($name)));
  }

  /**
   * Returns the path of one of the module's recipes.
   */
  private function recipePath(string $name): string {
    return dirname(__DIR__, 3) . '/recipes/' . $name;
  }

  /**
   * Returns the names of the config the stable_manager recipe ships.
   *
   * @return list<string>
   *   The config names.
   */
  private function recipeConfigNames(): array {
    $names = array_map(static fn (string $file): string => basename($file, '.yml'), glob($this->recipePath('stable_manager') . '/config/*.yml') ?: []);
    sort($names);
    return $names;
  }

  /**
   * Returns the translatable strings that people see, by path.
   *
   * @return array<string, string>
   *   The strings, keyed by their path in the config.
   */
  private function shownStrings(TypedDataInterface $element, string $path = ''): array {
    if ($element instanceof ArrayElement) {
      $strings = [];
      foreach ($element as $key => $child) {
        $strings += $this->shownStrings($child, $path === '' ? (string) $key : "$path.$key");
      }
      return $strings;
    }
    $value = $element->getValue();
    // Not shown, or not words: admin-only names, separators, currency signs,
    // date formats, plural formulas, titles of argument exceptions and the
    // paths of links.
    $hidden = '/(^|\.)(display_title|separator|prefix|format_plural_string|date_format)$|\.exception\.title$|\.arguments\.[a-z_]+\.title$|\.alter\.path$/';
    $definition = $element->getDataDefinition();
    if (!$definition instanceof \ArrayAccess || empty($definition['translatable']) || !is_string($value) || trim($value) === '' || preg_match($hidden, $path)) {
      return [];
    }
    return [$path => $value];
  }

}
