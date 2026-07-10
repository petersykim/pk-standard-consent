import type { Config } from "tailwindcss";
import preset from "../tailwind.preset";

const config: Config = {
  presets: [preset as Config],
  content: [
    "./assets/admin/src/**/*.{ts,tsx}",
    "../_shared/ui/src/**/*.{ts,tsx}",
  ],
  corePlugins: {
    preflight: false,
  },
};

export default config;
