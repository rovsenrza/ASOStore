import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './specs',
  // The flows share one database and build on each other.
  workers: 1,
  fullyParallel: false,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.BASE_URL ?? 'http://127.0.0.1:8002',
    locale: 'ru-RU',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
  ],
});
