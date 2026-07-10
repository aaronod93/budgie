<script setup lang="ts">
import type { CategoryGroupFull, CategoryRef } from '~/stores/budget'

const store = useBudgetStore()

const error = ref('')
const renamingGroup = ref<string | null>(null)
const renamingCategory = ref<string | null>(null)
const renameValue = ref('')
const newGroupName = ref('')
const newCategoryNames = reactive<Record<string, string>>({})
const migrating = ref<CategoryRef | null>(null)
const migrateTo = ref('')

async function run(action: () => Promise<unknown>) {
  error.value = ''
  try {
    await action()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'That did not work.'
  }
}

function startRenameGroup(group: CategoryGroupFull) {
  renamingGroup.value = group.uuid
  renameValue.value = group.name
}

function startRenameCategory(category: CategoryRef) {
  renamingCategory.value = category.uuid
  renameValue.value = category.name
}

async function commitRename() {
  const name = renameValue.value.trim()
  if (renamingGroup.value && name) {
    const uuid = renamingGroup.value
    await run(() => store.updateGroup(uuid, { name }))
  } else if (renamingCategory.value && name) {
    const uuid = renamingCategory.value
    await run(() => store.updateCategory(uuid, { name }))
  }
  renamingGroup.value = null
  renamingCategory.value = null
}

async function addGroup() {
  const name = newGroupName.value.trim()
  if (!name) return
  await run(() => store.createGroup(name))
  newGroupName.value = ''
}

async function addCategory(group: CategoryGroupFull) {
  const name = (newCategoryNames[group.uuid] ?? '').trim()
  if (!name) return
  await run(() => store.createCategory(group.uuid, name))
  newCategoryNames[group.uuid] = ''
}

async function moveGroup(group: CategoryGroupFull, delta: number) {
  const order = store.groups.map(g => g.uuid)
  const index = order.indexOf(group.uuid)
  const target = index + delta
  if (target < 0 || target >= order.length) return
  ;[order[index], order[target]] = [order[target]!, order[index]!]
  await run(() => store.reorderGroups(order))
}

async function moveCategory(group: CategoryGroupFull, category: CategoryRef, delta: number) {
  const order = group.categories.map(c => c.uuid)
  const index = order.indexOf(category.uuid)
  const target = index + delta
  if (target < 0 || target >= order.length) return
  ;[order[index], order[target]] = [order[target]!, order[index]!]
  await run(() => store.reorderCategories(group.uuid, order))
}

async function setIcon(category: CategoryRef, icon: string) {
  const trimmed = icon.trim()
  if (trimmed === (category.icon ?? '')) return
  await run(() => store.updateCategory(category.uuid, { icon: trimmed || null }))
}

async function moveToGroup(category: CategoryRef, groupUuid: string) {
  await run(() => store.updateCategory(category.uuid, { group_id: groupUuid }))
}

async function removeCategory(category: CategoryRef) {
  error.value = ''
  try {
    await store.deleteCategory(category.uuid)
  } catch {
    // Category has history — ask where it should go.
    migrating.value = category
    migrateTo.value = ''
  }
}

async function confirmMigrate() {
  if (!migrating.value || !migrateTo.value) return
  const uuid = migrating.value.uuid
  const target = migrateTo.value
  await run(() => store.deleteCategory(uuid, target))
  migrating.value = null
}

async function removeGroup(group: CategoryGroupFull) {
  await run(() => store.deleteGroup(group.uuid))
}

const migrateOptions = computed(() =>
  store.groups.flatMap(group =>
    group.categories
      .filter(c => c.uuid !== migrating.value?.uuid)
      .map(c => ({ uuid: c.uuid, label: `${group.name} · ${c.name}` })),
  ))
</script>

<template>
  <div class="rounded-xl border border-ink-700 bg-paper-200 text-ink-800">
    <p class="border-b border-paper-300 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-mist-700">
      Edit categories — changes save immediately
    </p>

    <p v-if="error" class="mx-4 mt-3 rounded-md bg-red-100 px-3 py-2 text-sm text-red-700">{{ error }}</p>

    <div v-for="group in store.groups" :key="group.uuid" class="border-b border-paper-300 px-4 py-3">
      <!-- Group row -->
      <div class="flex items-center gap-2">
        <input
          v-if="renamingGroup === group.uuid"
          v-model="renameValue"
          class="rounded border border-accent-400 bg-paper-50 px-2 py-0.5 font-semibold"
          autofocus
          @keydown.enter.prevent="commitRename"
          @keydown.esc="renamingGroup = null"
          @blur="commitRename"
        >
        <button v-else class="font-semibold hover:text-accent-600" title="Rename" @click="startRenameGroup(group)">
          {{ group.name }}
        </button>
        <span class="flex-1" />
        <button class="rounded px-1.5 text-mist-700 hover:bg-paper-100" title="Move up" @click="moveGroup(group, -1)">↑</button>
        <button class="rounded px-1.5 text-mist-700 hover:bg-paper-100" title="Move down" @click="moveGroup(group, 1)">↓</button>
        <button
          class="rounded px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700"
          title="Delete group (must be empty)"
          @click="removeGroup(group)"
        >✕</button>
      </div>

      <!-- Categories -->
      <div
        v-for="category in group.categories"
        :key="category.uuid"
        class="mt-1.5 flex items-center gap-2 pl-4"
        :class="{ 'opacity-50': category.hidden }"
      >
        <input
          :value="category.icon ?? ''"
          placeholder="🙂"
          maxlength="8"
          title="Emoji icon (Win + . opens the picker)"
          class="w-9 rounded border border-paper-400 bg-paper-50 px-1 py-0.5 text-center text-sm"
          @change="setIcon(category, ($event.target as HTMLInputElement).value)"
        >
        <input
          v-if="renamingCategory === category.uuid"
          v-model="renameValue"
          class="rounded border border-accent-400 bg-paper-50 px-2 py-0.5 text-sm"
          autofocus
          @keydown.enter.prevent="commitRename"
          @keydown.esc="renamingCategory = null"
          @blur="commitRename"
        >
        <button v-else class="text-sm hover:text-accent-600" title="Rename" @click="startRenameCategory(category)">
          {{ category.name }}<span v-if="category.hidden" class="ml-1 text-xs text-mist-700">(hidden)</span>
        </button>
        <span class="flex-1" />
        <wa-select
          size="small"
          title="Move to group"
          :value="group.uuid"
          @change="moveToGroup(category, String(($event.target as HTMLSelectElement).value || group.uuid))"
        >
          <wa-option v-for="g in store.groups" :key="g.uuid" :value="g.uuid">{{ g.name }}</wa-option>
        </wa-select>
        <button
          class="rounded px-1.5 text-xs text-mist-700 hover:bg-paper-100"
          :title="category.hidden ? 'Unhide' : 'Hide'"
          @click="run(() => store.updateCategory(category.uuid, { hidden: !category.hidden }))"
        >
          {{ category.hidden ? 'Unhide' : 'Hide' }}
        </button>
        <button class="rounded px-1 text-mist-700 hover:bg-paper-100" title="Move up" @click="moveCategory(group, category, -1)">↑</button>
        <button class="rounded px-1 text-mist-700 hover:bg-paper-100" title="Move down" @click="moveCategory(group, category, 1)">↓</button>
        <button
          class="rounded px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700"
          title="Delete category"
          @click="removeCategory(category)"
        >✕</button>
      </div>

      <!-- Add category -->
      <form class="mt-2 flex gap-2 pl-4" @submit.prevent="addCategory(group)">
        <input
          v-model="newCategoryNames[group.uuid]"
          placeholder="New category…"
          class="w-48 rounded-md border border-paper-400 bg-paper-50 px-2 py-1 text-sm"
        >
        <button type="submit" class="rounded-md border border-accent-500 px-2 py-1 text-xs text-accent-600 hover:bg-accent-100">
          Add
        </button>
      </form>
    </div>

    <!-- Add group -->
    <form class="flex gap-2 px-4 py-3" @submit.prevent="addGroup">
      <input
        v-model="newGroupName"
        placeholder="New group…"
        class="w-48 rounded-md border border-paper-400 bg-paper-50 px-2 py-1 text-sm"
      >
      <button type="submit" class="rounded-md bg-accent-400 px-3 py-1 text-xs font-medium text-ink-900 hover:bg-accent-500">
        Add group
      </button>
    </form>

    <!-- Migrate-on-delete modal -->
    <div v-if="migrating" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-xl bg-paper-200 p-6 text-ink-800 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold">Delete "{{ migrating.name }}"</h2>
        <p class="mb-4 text-sm text-mist-700">
          This category has transactions or assigned money. Choose where its history
          and money should move — nothing is lost.
        </p>
        <form class="space-y-4" @submit.prevent="confirmMigrate">
          <wa-select
            class="w-full"
            placeholder="Move everything to…"
            required
            :value="migrateTo"
            @change="migrateTo = String(($event.target as HTMLSelectElement).value || '')"
          >
            <wa-option v-for="option in migrateOptions" :key="option.uuid" :value="option.uuid">
              {{ option.label }}
            </wa-option>
          </wa-select>
          <div class="flex justify-end gap-2">
            <button type="button" class="rounded-md px-4 py-2 text-ink-600 hover:bg-paper-300" @click="migrating = null">Cancel</button>
            <button type="submit" class="rounded-md bg-red-600 px-4 py-2 font-medium text-white hover:bg-red-700">
              Move & delete
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
