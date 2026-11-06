import { chromium, type FullConfig } from '@playwright/test';

/**
 * Logs in as a stable manager, with a one-time login link (LOGIN_URL, from
 * `drush user:login`), and keeps the session for the tests.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  const loginUrl = process.env.LOGIN_URL;
  if (!loginUrl) {
    throw new Error('LOGIN_URL is not set: run the tests with ./run.sh.');
  }
  const { storageState } = config.projects[0].use;
  const browser = await chromium.launch();
  const page = await browser.newPage();
  await page.goto(loginUrl);
  await page.context().storageState({ path: storageState as string });
  await browser.close();
}
