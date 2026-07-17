<script setup lang="ts">
import type { CategoryGroupFull, CategoryRef } from '~/stores/budget'

definePageMeta({ middleware: 'auth', layout: 'app' })

const store = useBudgetStore()

const error = ref('')
const renamingGroup = ref<string | null>(null)
const renamingCategory = ref<string | null>(null)
const renameValue = ref('')
const newGroupName = ref('')
const newCategoryNames = reactive<Record<string, string>>({})
const migrating = ref<CategoryRef | null>(null)
const migrateTo = ref('')
const addingGroup = ref(false)
const addingCategory = reactive<Record<string, boolean>>({})
const migrateBusy = ref(false)

async function run(action: () => Promise<unknown>) {
  error.value = ''
  try {
    await action()
  } catch (e) {
    const err = e as { data?: { message?: string } }
    error.value = err.data?.message ?? 'That did not work.'
  }
}

// --- Drag and drop ---------------------------------------------------------

type DragState =
  | { type: 'group', uuid: string }
  | { type: 'category', uuid: string, from: string }

const drag = ref<DragState | null>(null)
// Insert-before markers for the drop indicator.
const overCategory = ref<string | null>(null)
const overGroupEnd = ref<string | null>(null) // append to this group
const overGroupBefore = ref<string | null>(null) // move group before this group
const dropBusy = ref(false)

function startCategoryDrag(event: DragEvent, group: CategoryGroupFull, category: CategoryRef) {
  drag.value = { type: 'category', uuid: category.uuid, from: group.uuid }
  event.dataTransfer!.effectAllowed = 'move'
  event.dataTransfer!.setData('text/plain', category.uuid)
}

function startGroupDrag(event: DragEvent, group: CategoryGroupFull) {
  drag.value = { type: 'group', uuid: group.uuid }
  event.dataTransfer!.effectAllowed = 'move'
  event.dataTransfer!.setData('text/plain', group.uuid)
}

function clearDrag() {
  drag.value = null
  overCategory.value = null
  overGroupEnd.value = null
  overGroupBefore.value = null
}

function overCategoryRow(event: DragEvent, category: CategoryRef) {
  if (drag.value?.type !== 'category' || drag.value.uuid === category.uuid) return
  event.preventDefault()
  overCategory.value = category.uuid
  overGroupEnd.value = null
  overGroupBefore.value = null
}

function overGroupZone(event: DragEvent, group: CategoryGroupFull) {
  if (drag.value?.type !== 'category') return
  event.preventDefault()
  overCategory.value = null
  overGroupEnd.value = group.uuid
  overGroupBefore.value = null
}

function overGroupHeader(event: DragEvent, group: CategoryGroupFull) {
  if (drag.value?.type === 'group' && drag.value.uuid !== group.uuid) {
    event.preventDefault()
    overGroupBefore.value = group.uuid
    overCategory.value = null
    overGroupEnd.value = null
  } else if (drag.value?.type === 'category') {
    // Dropping a category on a group header appends to that group.
    overGroupZone(event, group)
  }
}

/** Drop a dragged category before `before` (or at the end) of `group`. */
async function dropCategory(group: CategoryGroupFull, before: CategoryRef | null) {
  const dragged = drag.value
  if (dragged?.type !== 'category' || dropBusy.value) return
  dropBusy.value = true
  try {
    const order = group.categories.map(c => c.uuid).filter(uuid => uuid !== dragged.uuid)
    const index = before ? order.indexOf(before.uuid) : order.length
    order.splice(index < 0 ? order.length : index, 0, dragged.uuid)
    await run(async () => {
      if (dragged.from !== group.uuid) {
        await store.updateCategory(dragged.uuid, { group_id: group.uuid })
      }
      await store.reorderCategories(group.uuid, order)
    })
  } finally {
    dropBusy.value = false
    clearDrag()
  }
}

/** Drop a dragged group before `before`. */
async function dropGroup(before: CategoryGroupFull) {
  const dragged = drag.value
  if (dragged?.type !== 'group' || dropBusy.value || dragged.uuid === before.uuid) {
    clearDrag()
    return
  }
  dropBusy.value = true
  try {
    const order = store.groups.map(g => g.uuid).filter(uuid => uuid !== dragged.uuid)
    order.splice(order.indexOf(before.uuid), 0, dragged.uuid)
    await run(() => store.reorderGroups(order))
  } finally {
    dropBusy.value = false
    clearDrag()
  }
}

function onDropOnHeader(group: CategoryGroupFull) {
  if (drag.value?.type === 'group') dropGroup(group)
  else if (drag.value?.type === 'category') dropCategory(group, null)
}

// --- Management (rename / add / hide / icon / delete) ----------------------

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
  if (!name || addingGroup.value) return
  addingGroup.value = true
  try {
    await run(() => store.createGroup(name))
    newGroupName.value = ''
  } finally {
    addingGroup.value = false
  }
}

async function addCategory(group: CategoryGroupFull) {
  const name = (newCategoryNames[group.uuid] ?? '').trim()
  if (!name || addingCategory[group.uuid]) return
  addingCategory[group.uuid] = true
  try {
    await run(() => store.createCategory(group.uuid, name))
    newCategoryNames[group.uuid] = ''
  } finally {
    addingCategory[group.uuid] = false
  }
}

async function setIcon(category: CategoryRef, icon: string) {
  const trimmed = icon.trim()
  if (trimmed === (category.icon ?? '')) return
  await run(() => store.updateCategory(category.uuid, { icon: trimmed || null }))
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
  if (!migrating.value || !migrateTo.value || migrateBusy.value) return
  const uuid = migrating.value.uuid
  const target = migrateTo.value
  migrateBusy.value = true
  try {
    await run(() => store.deleteCategory(uuid, target))
    migrating.value = null
  } finally {
    migrateBusy.value = false
  }
}

async function removeGroup(group: CategoryGroupFull) {
  try {
    await $confirm(`Delete "${group.name}"?`, 'The group must be empty; its categories should be moved first.', 'Delete', 'Cancel')
  } catch { return }
  await run(() => store.deleteGroup(group.uuid))
}

const migrateOptions = computed(() =>
  store.groups.flatMap(group =>
    group.categories
      .filter(c => c.uuid !== migrating.value?.uuid)
      .map(c => ({ uuid: c.uuid, label: `${c.icon ? c.icon + ' ' : ''}${group.name} · ${c.name}` })),
  ))
</script>

<template>
  <div class="mx-auto max-w-3xl p-6">
    <h1 class="mb-1 text-xl font-bold">Categories</h1>
    <p class="mb-6 text-sm text-mist-700">
      Drag categories to reorder or move them between groups; drag a group header to reorder groups.
    </p>

    <p v-if="error" class="mb-4 rounded-sm bg-red-100 px-4 py-2 text-sm text-red-700">{{ error }}</p>

    <div class="overflow-hidden rounded-sm border border-paper-300 bg-white text-ink-800">
      <div
        v-for="group in store.groups"
        :key="group.uuid"
        class="border-b border-paper-300 last:border-b-0"
      >
        <!-- Group header (draggable, drop target) -->
        <div
          class="flex items-center gap-2 bg-paper-100 px-4 py-2"
          :class="{ 'border-t-2 border-accent-500': overGroupBefore === group.uuid }"
          draggable="true"
          @dragstart="startGroupDrag($event, group)"
          @dragend="clearDrag"
          @dragover="overGroupHeader($event, group)"
          @drop.prevent="onDropOnHeader(group)"
        >
          <span class="cursor-grab select-none text-paper-400" title="Drag to reorder">⠿</span>
          <input
            v-if="renamingGroup === group.uuid"
            v-model="renameValue"
            class="rounded-sm border border-ink-500 bg-white px-2 py-0.5 font-semibold focus:outline-none"
            autofocus
            @keydown.enter.prevent="commitRename"
            @keydown.esc="renamingGroup = null"
            @blur="commitRename"
          >
          <button v-else class="font-semibold hover:text-accent-600" title="Rename" @click="startRenameGroup(group)">
            {{ group.name }}
          </button>
          <span class="flex-1" />
          <button
            class="px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700"
            title="Delete group (must be empty)"
            @click="removeGroup(group)"
          >✕</button>
        </div>

        <!-- Categories -->
        <div
          v-for="category in group.categories"
          :key="category.uuid"
          class="flex items-center gap-2 border-t border-paper-200 px-4 py-1.5 pl-6"
          :class="{
            'border-t-2 border-t-accent-500': overCategory === category.uuid,
            'opacity-50': category.hidden || (drag?.type === 'category' && drag.uuid === category.uuid),
          }"
          draggable="true"
          @dragstart="startCategoryDrag($event, group, category)"
          @dragend="clearDrag"
          @dragover="overCategoryRow($event, category)"
          @drop.prevent="dropCategory(group, category)"
        >
          <span class="cursor-grab select-none text-paper-400" title="Drag to move">⠿</span>
          <UiEmojiPicker
            :model-value="category.icon"
            @update:model-value="setIcon(category, $event)"
          />
          <input
            v-if="renamingCategory === category.uuid"
            v-model="renameValue"
            class="rounded-sm border border-ink-500 bg-white px-2 py-0.5 text-sm focus:outline-none"
            autofocus
            @keydown.enter.prevent="commitRename"
            @keydown.esc="renamingCategory = null"
            @blur="commitRename"
          >
          <button v-else class="text-sm hover:text-accent-600" title="Rename" @click="startRenameCategory(category)">
            {{ category.name }}<span v-if="category.hidden" class="ml-1 text-xs text-mist-700">(hidden)</span>
          </button>
          <span class="flex-1" />
          <button
            class="px-1.5 text-xs text-mist-700 hover:bg-paper-100"
            :title="category.hidden ? 'Unhide' : 'Hide'"
            @click="run(() => store.updateCategory(category.uuid, { hidden: !category.hidden }))"
          >
            {{ category.hidden ? 'Unhide' : 'Hide' }}
          </button>
          <button
            class="px-1.5 text-paper-400 hover:bg-red-100 hover:text-red-700"
            title="Delete category"
            @click="removeCategory(category)"
          >✕</button>
        </div>

        <!-- Add category / drop-at-end zone -->
        <form
          class="flex gap-2 px-4 py-2 pl-6"
          :class="{ 'bg-mist-200/40': overGroupEnd === group.uuid }"
          @submit.prevent="addCategory(group)"
          @dragover="overGroupZone($event, group)"
          @drop.prevent="dropCategory(group, null)"
        >
          <input
            v-model="newCategoryNames[group.uuid]"
            placeholder="New category…"
            class="w-48 rounded-sm border border-paper-400 bg-white px-2 py-1 text-sm focus:border-ink-500 focus:outline-none"
          >
          <UiButton type="submit" variant="secondary" size="sm" :loading="addingCategory[group.uuid]" :disabled="!(newCategoryNames[group.uuid] ?? '').trim()">
            Add
          </UiButton>
        </form>
      </div>

      <!-- Add group -->
      <form class="flex gap-2 border-t border-paper-300 px-4 py-3" @submit.prevent="addGroup">
        <input
          v-model="newGroupName"
          placeholder="New group…"
          class="w-48 rounded-sm border border-paper-400 bg-white px-2 py-1 text-sm focus:border-ink-500 focus:outline-none"
        >
        <UiButton type="submit" size="sm" :loading="addingGroup" :disabled="!newGroupName.trim()">
          Add group
        </UiButton>
      </form>
    </div>

    <!-- Migrate-on-delete modal -->
    <div v-if="migrating" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div class="w-full max-w-sm rounded-sm border border-paper-300 bg-white p-6 text-ink-800 shadow-xl">
        <h2 class="mb-1 text-lg font-semibold">Delete "{{ migrating.name }}"</h2>
        <p class="mb-4 text-sm text-mist-700">
          This category has transactions or assigned money. Choose where its history
          and money should move — nothing is lost.
        </p>
        <form class="space-y-4" @submit.prevent="confirmMigrate">
          <UiSelect v-model="migrateTo">
            <option value="" disabled>Move everything to…</option>
            <option v-for="option in migrateOptions" :key="option.uuid" :value="option.uuid">
              {{ option.label }}
            </option>
          </UiSelect>
          <div class="flex justify-end gap-2">
            <UiButton variant="ghost" :disabled="migrateBusy" @click="migrating = null">Cancel</UiButton>
            <UiButton type="submit" variant="danger" :loading="migrateBusy" :disabled="!migrateTo">
              Move & delete
            </UiButton>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>
