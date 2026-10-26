import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Dev: the React app runs on :5173 and proxies the stats API to Laravel, so the browser
// sees one origin (no CORS) and Laravel's HTTP basic-auth prompt works as usual.
const laravel = process.env.LARAVEL_URL || 'http://127.0.0.1:8000';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    proxy: { '/dashboard/stats': { target: laravel, changeOrigin: true } },
  },
});
