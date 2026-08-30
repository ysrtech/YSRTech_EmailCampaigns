import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

// Sibling build to vite.config.js: a separate bundle for the automation-flow
// canvas (React Flow), kept out of the drag & drop email editor's bundle
// since they're different admin pages with an unrelated dependency (React
// Flow vs easy-email-*) — no reason to ship one to a page that never uses it.
const outDir = fileURLToPath(
  new URL('../skin/adminhtml/base/default/ysrtech/emailcampaigns/', import.meta.url)
);

export default defineConfig({
  plugins: [react()],
  base: '/skin/adminhtml/base/default/ysrtech/emailcampaigns/',
  // Same fix as vite.config.js: some dependency in the chain assumes a
  // Node-like `process.env`, which Vite doesn't polyfill for the browser.
  define: {
    'process.env': {},
  },
  build: {
    outDir,
    emptyOutDir: false,
    lib: {
      entry: 'src/flowBuilder/main.jsx',
      name: 'YsrFlowEditor',
      formats: ['iife'],
      fileName: () => 'flowBuilder.js',
    },
    cssCodeSplit: false,
    rollupOptions: {
      output: { assetFileNames: 'flowBuilder.[ext]' },
    },
  },
});
