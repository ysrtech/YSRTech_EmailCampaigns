import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

// Build output goes DIRECTLY into the OpenMage skin directory so the
// committed bundle ships with the module (no Node needed on target installs).
// "base" is the design package OpenMage always includes in its theme fallback
// chain (unlike "default", which is only used when a store explicitly sets its
// package to "default") — see app/design/adminhtml/base/default/ for the phtml
// template that loads this bundle.
const outDir = fileURLToPath(
  new URL('../skin/adminhtml/base/default/ysrtech/emailcampaigns/', import.meta.url)
);

export default defineConfig({
  plugins: [react()],
  base: '/skin/adminhtml/base/default/ysrtech/emailcampaigns/',
  // easy-email-editor/extensions assume a Node-like `process.env`, which Vite
  // (unlike webpack) doesn't polyfill for the browser — without this the
  // bundle throws "ReferenceError: process is not defined" at load time.
  define: {
    'process.env': {},
  },
  build: {
    outDir,
    // false so this build and vite.flow.config.js's don't wipe each other's
    // output — both share this same skin directory.
    emptyOutDir: false,
    lib: {
      entry: 'src/main.jsx',
      name: 'YsrEmailEditor',
      formats: ['iife'],
      fileName: () => 'editor.js',
    },
    cssCodeSplit: false,
    rollupOptions: {
      output: { assetFileNames: 'editor.[ext]' },
    },
  },
});
