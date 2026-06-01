import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Organisation Pages site is served from the domain root (opus-125.github.io/).
export default defineConfig({
  base: '/',
  plugins: [react(), tailwindcss()],
})
