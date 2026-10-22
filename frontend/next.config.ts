import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /* config options here */
  // Mengizinkan perangkat di jaringan pengembangan internal 10.30.x.x.
  allowedDevOrigins: ["10.30.*.*"],
  experimental: {
    agentFeedback: true,
  },
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
