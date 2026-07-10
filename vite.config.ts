import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve, dirname } from "path";
import { createRequire } from "module";
import { fileURLToPath } from "url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);

export default defineConfig({
  plugins: [react()],
  css: {
    postcss: {
      plugins: [
        require("tailwindcss")(resolve(__dirname, "tailwind.config.ts")),
      ],
    },
  },
  build: {
    outDir: "assets/dist",
    emptyOutDir: true,
    // With the IIFE single-entry format below, Vite's default cssCodeSplit silently drops
    // the imported admin.css — the build emitted only admin.js, and AdminPage.php's
    // file_exists() guard then served an unstyled admin (round-3 audit F5).
    cssCodeSplit: false,
    rollupOptions: {
      input: resolve(__dirname, "assets/admin/src/main.tsx"),
      output: {
        // IIFE wraps the whole bundle in its own scope. Without it the entry was emitted
        // with bare top-level `function` declarations that leaked to the global scope —
        // one minified to `function wp(){…}`, which OVERWROTE WordPress's `window.wp`
        // object and broke wp.svgPainter (and anything else reading window.wp) on every
        // consent admin page. IIFE keeps our code off the global namespace.
        format: "iife",
        name: "PKStandardConsentAdmin",
        entryFileNames: "admin.js",
        assetFileNames: "admin.css",
      },
    },
  },
  resolve: {
    alias: {
      "@pk/ui": resolve(__dirname, "../_shared/ui/src/index.ts"),
    },
  },
});
