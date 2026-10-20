import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

// Builds the automation canvas bundle (React Flow) into the skin, where
// Block/Adminhtml/Automation/Canvas.php serves it from. Its own bundle rather
// than part of the email designer's: different admin page, unrelated
// dependency, and no reason to ship one to a page that never uses it.
//
// adminhtml/default/default, not base/default, because that is where this
// branch's skin assets live - the GrapesJS bundle sits beside it.
const outDir = fileURLToPath(
  new URL('../skin/adminhtml/default/default/ysrtech/emailcampaigns/flowbuilder/', import.meta.url)
);

export default defineConfig({
  plugins: [react()],
  base: '/skin/adminhtml/default/default/ysrtech/emailcampaigns/flowbuilder/',
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
