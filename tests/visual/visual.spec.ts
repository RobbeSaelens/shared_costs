import { expect, test, type Page } from '@playwright/test';

/**
 * The demo's pages, on its example costs (October 2026 and before).
 *
 * The URLs name the month, so the pages don't change with today's date. Only
 * the dashboard shows this month and this year: its numbers and charts are
 * masked.
 */

/** Waits until the page has settled: fonts, images and charts. */
async function settle(page: Page): Promise<void> {
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => document.fonts.ready);
}

test('dashboard', async ({ page }) => {
  await page.goto('/stable');
  await settle(page);
  await expect(page).toHaveScreenshot('dashboard.png', {
    fullPage: true,
    mask: [page.locator('.stat-card__value'), page.locator('.chart-box')],
  });
});

test('overview', async ({ page }) => {
  await page.goto('/stable/overview/202610');
  await settle(page);
  await expect(page).toHaveScreenshot('overview.png', { fullPage: true });
});

test('statement', async ({ page }) => {
  await page.goto('/stable/overview/202610');
  await page.getByRole('link', { name: 'Luna', exact: true }).click();
  await expect(page).toHaveURL(/\/stable\/statement\/\d+\/202610$/);
  await settle(page);
  await expect(page).toHaveScreenshot('statement.png', { fullPage: true });
});

test('expenses', async ({ page }) => {
  await page.goto('/stable/expenses?category=feed');
  await settle(page);
  await expect(page).toHaveScreenshot('expenses.png', { fullPage: true });
});

test('inventory', async ({ page }) => {
  await page.goto('/stable/inventory');
  await settle(page);
  await expect(page).toHaveScreenshot('inventory.png', { fullPage: true });
});

test('horses', async ({ page }) => {
  await page.goto('/stable/horses');
  await settle(page);
  await expect(page).toHaveScreenshot('horses.png', { fullPage: true });
});

test.describe('logged out', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('login', async ({ page }) => {
    await page.goto('/user/login');
    await settle(page);
    await expect(page).toHaveScreenshot('login.png', { fullPage: true });
  });
});
