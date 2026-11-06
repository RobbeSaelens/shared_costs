<?php

declare(strict_types=1);

namespace Drupal\Tests\shared_costs\Functional\Recipe;

use Drupal\Core\Database\StatementInterface;
use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Stal Manager demo recipe: a site to try out, with example costs.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class StalManagerDemoRecipeTest extends GenericRecipeTestBase {

  use StalManagerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function getRecipePath(): string {
    return $this->recipe('stal_manager_demo');
  }

  /**
   * Tests the demo site: private, with its example content shared to the cent.
   */
  public function testDemo(): void {
    $this->doApply();
    $this->assertSame('Stal Manager', $this->config('system.site')->get('name'));
    $this->assertSame('olivero', $this->config('system.theme')->get('default'));

    // Visitors get the login form, and nothing of the costs.
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSession()->fieldExists('name');
    $this->assertSame([], $this->config('user.role.anonymous')->get('permissions'));

    // The example content: every expense is shared to the cent.
    $counts = $this->query('SELECT [type], COUNT(*) FROM {node_field_data} GROUP BY [type] ORDER BY [type]')->fetchAllKeyed();
    $this->assertEquals(['expense' => 79, 'horse' => 2, 'inventory_item' => 5], $counts);
    $total = $this->query('SELECT SUM([field_total_value]) FROM {node__field_total}')->fetchField();
    $shared = $this->query('SELECT SUM([field_shared_with_amount]) FROM {node__field_shared_with}')->fetchField();
    $this->assertSame(round((float) $total, 2), round((float) $shared, 2));
    $this->assertGreaterThan(0, (float) $total);

    // The front page is the dashboard.
    $this->drupalLogin($this->createStableManager());
    $this->drupalGet('<front>');
    $this->assertSession()->titleEquals('Dashboard | Stal Manager');
    $this->assertSession()->linkExists('Add an expense');
  }

  /**
   * Runs a database query on the test site.
   *
   * @return \Drupal\Core\Database\StatementInterface<int, mixed>
   *   The result.
   */
  private function query(string $query): StatementInterface {
    $statement = $this->container->get('database')->query($query);
    $this->assertInstanceOf(StatementInterface::class, $statement);
    return $statement;
  }

}
