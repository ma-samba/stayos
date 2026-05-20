<script setup lang="ts">
import { ref } from 'vue'
import { guestService } from '@/services/guest.service'
import type { Guest } from '@/types/entities'

withDefaults(defineProps<{ placeholder?: string }>(), {
  placeholder: 'Rechercher un client...',
})
const emit = defineEmits<{ select: [guest: Guest] }>()

const query   = ref('')
const results = ref<Guest[]>([])
let debounce: ReturnType<typeof setTimeout> | null = null

function onInput(): void {
  if (debounce) clearTimeout(debounce)
  if (query.value.trim().length < 2) {
    results.value = []
    return
  }
  debounce = setTimeout(async () => {
    try {
      results.value = await guestService.search(query.value.trim())
    } catch {
      results.value = []
    }
  }, 300)
}

function pick(g: Guest): void {
  emit('select', g)
  query.value = ''
  results.value = []
}
</script>

<template>
  <div style="position:relative;">
    <input v-model="query" class="input" :placeholder="placeholder" @input="onInput()" />
    <div
      v-if="results.length > 0"
      style="position:absolute; top:100%; left:0; right:0; background:#fff; border:0.5px solid var(--pms-border-2); border-radius:var(--radius-md); z-index:20; max-height:240px; overflow-y:auto; box-shadow:0 4px 12px rgba(0,0,0,0.08);"
    >
      <button
        v-for="g in results"
        :key="g.id"
        type="button"
        style="display:flex; align-items:center; gap:8px; width:100%; padding:8px 12px; border:none; background:none; cursor:pointer; text-align:left; font-size:13px; font-family:var(--font);"
        @click="pick(g)"
      >
        <div class="avatar avatar-sm avatar-teal">{{ (g.firstName[0] + g.lastName[0]).toUpperCase() }}</div>
        <div>
          <div style="font-weight:500;">{{ g.firstName }} {{ g.lastName }}</div>
          <div class="t-muted" style="font-size:11px;">{{ g.email || g.phone || '' }}</div>
        </div>
      </button>
    </div>
  </div>
</template>
