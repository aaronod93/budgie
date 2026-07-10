import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({
  // Budgie web is a pure online SPA behind auth — no SSR (see PLAN.md §6/§7).
  ssr: false,
  compatibilityDate: '2026-07-10',
  devtools: { enabled: true },
  vue: {
    compilerOptions: {
      // Web Awesome components are custom elements, not Vue components.
      isCustomElement: tag => tag.startsWith('wa-'),
    },
  },
  app: {
    head: {
      title: "Lil' Budgie",
    },
  },
  modules: ['@pinia/nuxt'],
  css: ['~/assets/css/main.css'],
  vite: {
    plugins: [tailwindcss()],
  },
  runtimeConfig: {
    public: {
      apiBase: 'http://localhost:8000',
      // Reverb websockets (live multi-device refresh); empty key disables.
      // In production the websocket rides the API domain over TLS (Caddy
      // proxies /app/* to the reverb container): host=api domain, port=443,
      // scheme=https.
      reverbKey: 'budgie-local-key',
      reverbHost: 'localhost',
      reverbPort: 8080,
      reverbScheme: 'http',
    },
  },
})
