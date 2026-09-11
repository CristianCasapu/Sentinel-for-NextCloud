<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="sentinel">
		<nav class="sentinel__bar">
			<span class="sentinel__brand">{{ t('sentinel', 'Sentinel') }}</span>

			<div class="sentinel__tabs" role="tablist">
				<button v-for="tab in tabs"
					:key="tab.id"
					class="sentinel__tab"
					:class="{ 'sentinel__tab--on': view === tab.id }"
					role="tab"
					:aria-selected="view === tab.id"
					@click="switchTo(tab.id)">
					{{ tab.label }}
					<span v-if="tab.id === 'events' && unseen > 0" class="sentinel__badge">{{ unseen }}</span>
				</button>
			</div>
		</nav>

		<main class="sentinel__body">
			<Posture v-if="view === 'posture'" @go="switchTo" />
			<Files v-else-if="view === 'files'" />
			<Inventory v-else-if="view === 'inventory'" />
			<Events v-else />
		</main>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import Posture from './Posture.vue'
import Files from './Files.vue'
import Inventory from './Inventory.vue'
import Events from './Events.vue'

type View = 'posture' | 'files' | 'inventory' | 'events'

const known: View[] = ['posture', 'files', 'inventory', 'events']
const unseen = ref(0)

const fromHash = (): View => {
	const hash = window.location.hash.replace('#', '') as View
	return known.includes(hash) ? hash : 'posture'
}

const view = ref<View>(fromHash())

const tabs = computed(() => [
	{ id: 'posture' as const, label: t('sentinel', 'How things stand') },
	{ id: 'files' as const, label: t('sentinel', 'Files') },
	{ id: 'inventory' as const, label: t('sentinel', 'Who can get in') },
	{ id: 'events' as const, label: t('sentinel', 'What happened') },
])

const switchTo = (id: string) => {
	view.value = known.includes(id as View) ? (id as View) : 'posture'
	// In the address bar, so that a reload lands where the reader was and a
	// notification can link straight to the journal.
	window.history.replaceState(null, '', '#' + view.value)
}

onMounted(() => {
	unseen.value = loadState<number>('sentinel', 'unseen', 0)
	window.addEventListener('hashchange', () => {
		view.value = fromHash()
	})
})
</script>

<style scoped>
.sentinel {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
	width: 100%;
}

.sentinel__bar {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px 24px;
	padding: 14px 24px;
	border-bottom: 1px solid var(--color-border);
	position: sticky;
	top: 0;
	background: var(--color-main-background);
	z-index: 5;
}

.sentinel__brand { font-size: 1.2em; font-weight: 700; }
.sentinel__tabs { display: flex; flex-wrap: wrap; gap: 6px; }

.sentinel__tab {
	border: none;
	background: none;
	color: var(--color-text-maxcontrast);
	padding: 8px 14px;
	border-radius: var(--border-radius-pill, 20px);
	cursor: pointer;
	font-size: inherit;
	position: relative;
}

.sentinel__tab:hover { background: var(--color-background-hover); color: inherit; }
.sentinel__tab--on { background: var(--color-primary-element); color: var(--color-primary-element-text); }

.sentinel__badge {
	display: inline-block;
	min-width: 18px;
	padding: 0 5px;
	margin-inline-start: 6px;
	border-radius: 9px;
	background: var(--color-error);
	color: var(--color-primary-text);
	font-size: 0.8em;
	line-height: 18px;
	text-align: center;
}

.sentinel__body {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 22px 24px 60px;
}

@media (max-width: 700px) {
	.sentinel__bar { padding: 10px 12px; gap: 8px 12px; }
	.sentinel__brand { flex: 1 1 100%; }
	.sentinel__tab { padding: 7px 11px; font-size: 0.95em; }
	.sentinel__body { padding: 16px 12px 48px; }
}
</style>
