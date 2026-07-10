export default defineNuxtRouteMiddleware(async () => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.fetchUser()
  if (auth.user) return navigateTo('/')
})
