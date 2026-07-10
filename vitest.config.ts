import { mergeConfig } from "vitest/config";
import { sharedConfig } from "../vitest.shared";

export default mergeConfig(sharedConfig, {
  test: {
    include: ["assets/admin/src/**/*.test.{ts,tsx}"],
  },
});
