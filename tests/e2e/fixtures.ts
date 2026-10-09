/** The flows' `test`: Playwright's, with every browser context routed to wp-env (harness.ts). Import `test` and `expect` from here. */
import { test as base, expect } from "@playwright/test";
import { routeSite } from "./harness";

export const test = base.extend({
  context: async ({ context }, use) => {
    await routeSite(context);
    await use(context);
  },
});
export { expect };
