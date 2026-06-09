<script setup lang="ts">
import { ref } from 'vue'
import type { NightAuditWarning } from '@/types/night-audit'

// ──────────────────────────────────────────────────────────────
//  WarningList — Sprint 13quater-C
//  Cards dépliables. `dense` désactive le chevron pour les
//  contextes lecture seule (snapshot historique).
// ──────────────────────────────────────────────────────────────

const props = defineProps<{
  warnings: NightAuditWarning[]
  dense?: boolean
}>()

const openCodes = ref<Set<string>>(new Set())

function toggle(code: string): void {
  if (props.dense) return
  if (openCodes.value.has(code)) {
    openCodes.value.delete(code)
  } else {
    openCodes.value.add(code)
  }
}

function isOpen(code: string): boolean {
  return props.dense ? true : openCodes.value.has(code)
}

function formatDetail(code: string, d: Record<string, unknown>): string {
  switch (code) {
    case 'arrivals.pending':
    case 'departures.pending':
      return `${d.confirmationNumber ?? '?'} · ${d.guest ?? '?'} (ch. ${d.room ?? '?'})`
    case 'invoices.draft':
      return `Facture ${d.number ?? '?'} · ${d.totalXof ?? '0'} XOF (${d.reservation ?? '-'})`
    case 'rooms.orphan_occupied':
      return `Chambre ${d.number ?? '?'}`
    default:
      return JSON.stringify(d)
  }
}
</script>

<template>
  <div class="warning-list">
    <div
      v-for="w in props.warnings"
      :key="w.code"
      class="warning-card"
      :class="{ 'is-dense': dense }"
    >
      <header
        class="warning-header"
        :class="{ 'is-clickable': !dense }"
        @click="toggle(w.code)"
      >
        <i class="ti ti-alert-triangle warning-icon" aria-hidden="true"></i>
        <div class="warning-titles">
          <div class="warning-label">{{ w.label }}</div>
          <div class="warning-count">{{ w.count }} élément(s)</div>
        </div>
        <i
          v-if="!dense"
          class="ti chevron"
          :class="isOpen(w.code) ? 'ti-chevron-up' : 'ti-chevron-down'"
          aria-hidden="true"
        ></i>
      </header>
      <div v-if="isOpen(w.code)" class="warning-body">
        <p class="warning-message">{{ w.message }}</p>
        <ul
          v-if="w.details && w.details.length > 0"
          class="warning-details"
        >
          <li v-for="(d, i) in w.details" :key="i">
            {{ formatDetail(w.code, d) }}
          </li>
        </ul>
      </div>
    </div>
  </div>
</template>

<style scoped>
.warning-list { display: flex; flex-direction: column; gap: 8px; }

.warning-card {
  background: #FBF6E8;
  border-left: 3px solid #C4922A;
  border-radius: 8px;
  overflow: hidden;
}

.warning-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  user-select: none;
}
.warning-header.is-clickable { cursor: pointer; }
.warning-header.is-clickable:hover { background: rgba(196, 146, 42, 0.06); }

.warning-icon { font-size: 18px; color: #8A6319; flex-shrink: 0; }

.warning-titles { flex: 1; min-width: 0; }
.warning-label { font-weight: 600; color: #8A6319; font-size: 13px; }
.warning-count { color: #6B6459; font-size: 11px; margin-top: 1px; }

.chevron { font-size: 16px; color: #8A6319; }

.warning-body {
  padding: 0 14px 12px 42px;
  font-size: 12px;
}
.warning-message { color: #3D3830; margin: 0 0 6px 0; line-height: 1.45; }
.warning-details {
  list-style: disc;
  padding-left: 18px;
  color: #6B6459;
  font-size: 11px;
  margin: 0;
}
.warning-details li { padding: 2px 0; }

.is-dense .warning-header { padding: 8px 12px; }
.is-dense .warning-body { padding: 0 12px 10px 38px; }
.is-dense .warning-icon { font-size: 14px; }
.is-dense .warning-label { font-size: 12px; }
.is-dense .warning-count { font-size: 10px; }
</style>
