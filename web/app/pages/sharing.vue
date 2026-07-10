<script setup lang="ts">
definePageMeta({ middleware: 'auth', layout: 'app' })

interface Member {
  uuid: string | null // null = the owner row
  name: string
  email: string
  role: 'owner' | 'editor' | 'viewer'
}
interface PendingInvite {
  uuid: string
  email: string
  role: string
}
interface Activity {
  action: string
  description: string
  user: string | null
  created_at: string
}

const store = useBudgetStore()
const auth = useAuthStore()

const members = ref<Member[]>([])
const pending = ref<PendingInvite[]>([])
const activity = ref<Activity[]>([])
const inviteForm = reactive({ email: '', role: 'editor' })
const error = ref('')
const inviteBusy = ref(false)

const isOwner = computed(() => store.current?.role === 'owner')

watch(() => store.current, load, { immediate: true })

async function load() {
  if (!store.current) return
  error.value = ''
  const requests: Promise<unknown>[] = [
    apiFetch<{ data: Member[] }>(`${store.base}/members`).then(r => (members.value = r.data)),
    apiFetch<{ data: Activity[] }>(`${store.base}/audit-log`).then(r => (activity.value = r.data)),
  ]
  if (isOwner.value) {
    requests.push(apiFetch<{ data: PendingInvite[] }>(`${store.base}/invitations`).then(r => (pending.value = r.data)))
  }
  await Promise.all(requests)
}

async function invite() {
  inviteBusy.value = true
  error.value = ''
  try {
    await apiFetch(`${store.base}/invitations`, { method: 'POST', body: { ...inviteForm } })
    inviteForm.email = ''
    await load()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'Could not send the invitation.'
  } finally {
    inviteBusy.value = false
  }
}

async function changeRole(member: Member, role: string) {
  if (!member.uuid) return
  await run(() => apiFetch(`${store.base}/members/${member.uuid}`, { method: 'PATCH', body: { role } }))
}

async function removeMember(member: Member) {
  if (!member.uuid) return
  const self = member.email === auth.user?.email
  if (!confirm(self ? 'Leave this budget?' : `Remove ${member.name} from this budget?`)) return
  await run(() => apiFetch(`${store.base}/members/${member.uuid}`, { method: 'DELETE' }))
  if (self) window.location.href = '/'
}

async function cancelInvite(invite: PendingInvite) {
  await run(() => apiFetch(`${store.base}/invitations/${invite.uuid}`, { method: 'DELETE' }))
}

async function run(action: () => Promise<unknown>) {
  error.value = ''
  try {
    await action()
    await load()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'That did not work.'
  }
}

function timeAgo(iso: string): string {
  const seconds = Math.floor((Date.now() - new Date(iso).getTime()) / 1000)
  if (seconds < 60) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  return new Date(iso).toLocaleDateString('en-AU')
}
</script>

<template>
  <div class="mx-auto max-w-3xl p-6">
    <h1 class="mb-1 text-xl font-bold">Sharing</h1>
    <p class="mb-6 text-sm text-mist-300">
      Budget with your partner: editors can do everything except manage sharing; viewers can only look.
    </p>

    <p v-if="error" class="mb-4 rounded-md bg-red-500/15 px-4 py-2 text-sm text-red-300">{{ error }}</p>

    <!-- Members -->
    <section class="mb-6 rounded-xl border border-ink-700 bg-paper-200 text-ink-800">
      <p class="border-b border-paper-300 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-mist-700">Members</p>
      <div v-for="member in members" :key="member.email" class="flex items-center justify-between border-b border-paper-300 px-4 py-3 last:border-0">
        <div>
          <p class="text-sm font-medium">
            {{ member.name }}
            <span v-if="member.email === auth.user?.email" class="text-mist-700">(you)</span>
          </p>
          <p class="text-xs text-mist-700">{{ member.email }}</p>
        </div>
        <div class="flex items-center gap-2">
          <wa-select
            v-if="isOwner && member.role !== 'owner'"
            size="small"
            :value="member.role"
            @change="changeRole(member, String(($event.target as HTMLSelectElement).value || member.role))"
          >
            <wa-option value="editor">Editor</wa-option>
            <wa-option value="viewer">Viewer</wa-option>
          </wa-select>
          <span v-else class="rounded-full bg-paper-100 px-2.5 py-0.5 text-xs font-medium capitalize text-ink-600">
            {{ member.role }}
          </span>
          <button
            v-if="member.role !== 'owner' && (isOwner || member.email === auth.user?.email)"
            class="text-xs text-mist-700 hover:text-red-700 hover:underline"
            @click="removeMember(member)"
          >
            {{ member.email === auth.user?.email ? 'Leave' : 'Remove' }}
          </button>
        </div>
      </div>
    </section>

    <!-- Invite (owner only) -->
    <section v-if="isOwner" class="mb-6 rounded-xl border border-ink-700 bg-paper-200 p-4 text-ink-800">
      <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-mist-700">Invite someone</p>
      <form class="flex flex-wrap gap-2" @submit.prevent="invite">
        <input
          v-model="inviteForm.email"
          type="email"
          required
          placeholder="partner@example.com"
          class="min-w-56 flex-1 rounded-md border border-paper-400 bg-paper-50 px-3 py-2 text-sm"
        >
        <wa-select
          :value="inviteForm.role"
          @change="inviteForm.role = String(($event.target as HTMLSelectElement).value || 'editor')"
        >
          <wa-option value="editor">Editor</wa-option>
          <wa-option value="viewer">Viewer</wa-option>
        </wa-select>
        <button
          type="submit"
          :disabled="inviteBusy"
          class="rounded-md bg-accent-400 px-4 py-2 text-sm font-medium text-ink-900 hover:bg-accent-500 disabled:opacity-50"
        >
          Send invite
        </button>
      </form>

      <div v-if="pending.length" class="mt-4">
        <p class="mb-1 text-xs text-mist-700">Pending</p>
        <div v-for="invitePending in pending" :key="invitePending.uuid" class="flex items-center justify-between py-1 text-sm">
          <span>{{ invitePending.email }} <span class="text-mist-700">({{ invitePending.role }})</span></span>
          <button class="text-xs text-mist-700 hover:text-red-700 hover:underline" @click="cancelInvite(invitePending)">Cancel</button>
        </div>
      </div>
    </section>

    <!-- Activity -->
    <section class="rounded-xl border border-ink-700 bg-paper-200 text-ink-800">
      <p class="border-b border-paper-300 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-mist-700">Recent activity</p>
      <p v-if="activity.length === 0" class="px-4 py-6 text-center text-sm text-mist-700">Nothing yet.</p>
      <div v-for="(entry, i) in activity" :key="i" class="flex items-baseline justify-between gap-4 border-b border-paper-300 px-4 py-2 text-sm last:border-0">
        <span>
          <span class="font-medium">{{ entry.user ?? 'System' }}</span>
          <span class="text-ink-600"> — {{ entry.description }}</span>
        </span>
        <span class="shrink-0 text-xs text-mist-700">{{ timeAgo(entry.created_at) }}</span>
      </div>
    </section>
  </div>
</template>
