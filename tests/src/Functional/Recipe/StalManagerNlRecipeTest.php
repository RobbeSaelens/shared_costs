<?php

declare(strict_types=1);

// cspell:ignore aandeel deze gelijk hooi kosten maand toevoegen uitgave voeding
namespace Drupal\Tests\shared_costs\Functional\Recipe;

use Drupal\Tests\system\Functional\Recipe\GenericRecipeTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Dutch Stal Manager recipe, and the module's own translations.
 */
#[Group('shared_costs')]
#[RunTestsInSeparateProcesses]
final class StalManagerNlRecipeTest extends GenericRecipeTestBase {

  use StalManagerTestTrait;

  /**
   * {@inheritdoc}
   */
  protected function getRecipePath(): string {
    return $this->recipe('stal_manager_nl');
  }

  /**
   * Tests the site in Dutch, with the translations imported as in the README.
   */
  public function testDutch(): void {
    $this->doApply();
    $this->assertSame('nl', $this->config('system.site')->get('default_langcode'));
    $luna = $this->createHorse('Luna');
    $bella = $this->createHorse('Bella', 'livery');
    $now = new \DateTimeImmutable('now', new \DateTimeZone($this->config('system.date')->get('timezone.default')));
    $this->createExpense($now->format('Y-m-01'), 'Hooi', 'feed', '1234.50', [[$luna], [$bella]]);

    // Import the module's translations through locale (the files on disk only:
    // tests don't download core's from localize.drupal.org).
    $this->config('locale.settings')->set('translation.use_source', 'local')->save();
    $account = $this->createStableManager();
    $account->addRole($this->drupalCreateRole(['translate interface']))->save();
    $this->drupalLogin($account);
    $this->drupalGet('admin/reports/translations');
    $this->clickLink('Check manually');
    $this->submitForm([], 'Update translations');

    // Dutch labels, categories and numbers.
    $this->drupalGet('stable');
    $this->assertSession()->pageTextContains('Kosten deze maand');
    $this->assertSession()->pageTextContains('€ 1.234,50');
    $this->assertSession()->linkExists('Uitgave toevoegen');
    $this->drupalGet('stable/overview');
    $this->assertSame([['Voeding', '1', '€ 1.234,50']], $this->tableRows('.stable-per-category'));
    $this->assertSame([['Bella', '€ 617,25'], ['Luna', '€ 617,25']], $this->tableRows('.stable-per-horse'));

    // The module's own strings, from its translations/nl.po.
    $this->drupalGet('node/add/expense');
    $this->assertSession()->pageTextContains('Aandeel (%)');
    $this->assertSession()->elementAttributeContains('css', "[name='field_shared_with[{$luna->id()}][share]']", 'placeholder', 'gelijk');
  }

}
