import { defineConfig } from '@playwright/test';

/**
 * Visual regression tests of the Stable Manager demo site.
 *
 * They run in Playwright's Linux image (see run.sh), locally and in CI, so
 * fonts and rendering are the same everywhere. The approved screenshots are
 * in screenshots/.
 */
export default defineConfig({
  testDir: '.',
  outputDir: 'test-results',
  snapshotPathTemplate: '{testDir}/screenshots/{projectName}/{arg}{ext}',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'report' }]],
  globalSetup: './global-setup.ts',
  expect: {
    toHaveScreenshot: {
      // Anti-aliasing may differ by a few pixels; a layout change doesn't.
      maxDiffPixelRatio: 0.002,
      animations: 'disabled',
      caret: 'hide',
    },
  },
  use: {
    baseURL: process.env.BASE_URL ?? 'http://shared-costs.ddev.site',
    storageState: '.auth/manager.json',
    // The theme skips its count-up and entrance animations.
    reducedMotion: 'reduce',
    locale: 'en-GB',
    timezoneId: 'Europe/Brussels',
  },
  projects: [
    { name: 'desktop', use: { viewport: { width: 1440, height: 900 } } },
    { name: 'desktop-dark', use: { viewport: { width: 1440, height: 900 }, colorScheme: 'dark' } },
    { name: 'phone', use: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1 } },
  ],
});
