import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({
  // Budgie web is a pure online SPA behind auth — no SSR (see PLAN.md §6/§7).
  ssr: false,
  compatibilityDate: '2026-07-10',
  devtools: { enabled: true },
  modules: ['@pinia/nuxt'],
  css: ['~/assets/css/main.css'],
  vite: {
    plugins: [tailwindcss()],
  },
  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8000',
    },
  },
})
