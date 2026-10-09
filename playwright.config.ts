import { defineConfig, devices } from "@playwright/test";
import { ADMIN_STATE, OMNABASE_E2E, SITE } from "./tests/e2e/harness";

// The browser flows: wp-env's WordPress, opened as https://wp.omnabase.test (routed to http://localhost:8888, see tests/e2e/harness.ts),
// with OmnaBase's e2e server as the backend. Needs `npx wp-env start`, the seed and Node 22. The e2e server is started by
// bin/omnabase-e2e.sh when it is not already running (it needs TEST_DATABASE_URL_ADMIN); never while OmnaBase's own suite runs.
const port = Number(new URL(OMNABASE_E2E).port || 3011);
export default defineConfig({
  testDir: "./tests/e2e",
  testMatch: /.*\.spec\.ts/,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  fullyParallel: false, // one WordPress and one OmnaBase seed shared by every file: the flows run one after the other
  workers: 1,
  retries: 0,
  reporter: "list",
  globalSetup: "./tests/e2e/global-setup.ts",
  use: { ...devices["Desktop Chrome"], baseURL: SITE, storageState: ADMIN_STATE, trace: "retain-on-failure" },
  webServer: { command: "bin/omnabase-e2e.sh", port, reuseExistingServer: true, timeout: 180_000, stdout: "pipe", stderr: "pipe" },
});
