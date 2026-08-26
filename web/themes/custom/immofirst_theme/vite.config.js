import { defineConfig } from 'vite';
import path from 'node:path';

export default defineConfig({
  root: 'src',

  build: {
    outDir: '../dist',
    emptyOutDir: true,

    rollupOptions: {
      input: {
        main: path.resolve(__dirname, 'src/js/main.js'),
      },

      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name].js',
        assetFileNames(assetInfo) {

          if (assetInfo.names?.[0]?.endsWith('.css')) {
            return 'css/main.css';
          }

          return 'assets/[name][extname]';
        }
      }
    }
  }
});