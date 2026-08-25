import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';

// Build output goes DIRECTLY into the OpenMage skin directory so the
// committed bundle ships with the module (no Node needed on target installs).
const outDir = fileURLToPath(
  new URL('../skin/adminhtml/default/default/ysrtech/emailcampaigns/', import.meta.url)
);

export default defineConfig({
  plugins: [react()],
  base: '/skin/adminhtml/default/default/ysrtech/emailcampaigns/',
  build: {
    outDir,
    emptyOutDir: true,
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
