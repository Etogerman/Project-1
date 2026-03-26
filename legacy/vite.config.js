import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  define: {
    __VERCEL_ENV__: JSON.stringify(process.env.VERCEL_ENV ?? ""),
    __VERCEL_GIT_COMMIT_REF__: JSON.stringify(
      process.env.VERCEL_GIT_COMMIT_REF ?? "",
    ),
  },
  plugins: [react()],
});
