import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: { outDir: 'server/public', emptyOutDir: false, target: 'es2022', rollupOptions: { output: { manualChunks: { react: ['react','react-dom','react-router-dom'], motion: ['motion'], gsap: ['gsap','@gsap/react'] } } } },
  server: { proxy: { '/api': 'http://127.0.0.1:8080', '/media': 'http://127.0.0.1:8080' } },
});
