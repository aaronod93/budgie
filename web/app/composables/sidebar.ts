// Sidebar collapse state, shared app-wide so panels that need room (e.g. the
// target slide-over) can collapse it programmatically. Persisted locally.
const KEY = 'budgie:sidebar-collapsed'

const sidebarCollapsed = ref(false)

if (import.meta.client) {
  sidebarCollapsed.value = localStorage.getItem(KEY) === '1'
  watch(sidebarCollapsed, value => localStorage.setItem(KEY, value ? '1' : '0'))
}

export function useSidebar() {
  return {
    collapsed: sidebarCollapsed,
    toggle: () => (sidebarCollapsed.value = !sidebarCollapsed.value),
    collapse: () => (sidebarCollapsed.value = true),
    expand: () => (sidebarCollapsed.value = false),
  }
}
