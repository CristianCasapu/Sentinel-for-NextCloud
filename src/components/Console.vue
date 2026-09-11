<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="console" :class="{ 'console--embedded': embedded }">
		<nav class="console__bar">
			<span v-if="!embedded" class="console__brand">{{ t('sentinel', 'Sentinel') }}</span>
			<h2 v-else class="console__brand">{{ t('sentinel', 'Sentinel') }}</h2>

			<div class="console__tabs" role="tablist">
				<button v-for="tab in tabs"
					:key="tab.id"
					class="console__tab"
					:class="{ 'console__tab--on': view === tab.id }"
					role="tab"
					:aria-selected="view === tab.id"
					@click="switchTo(tab.id)">
					{{ tab.label }}
					<span v-if="tab.id === 'events' && unseen > 0" class="console__badge">{{ unseen }}</span>
				</button>
			</div>
		</nav>

		<p v-if="embedded" class="console__lead">
			{{ t('sentinel', 'Nextcloud defends itself well: it throttles password guessing, hashes passwords properly, sets the right headers and offers two-factor authentication. Sentinel repeats none of that. It watches what accumulates quietly instead, and tells you when it changes.') }}
		</p>

		<main class="console__body">
			<Overview v-if="view === 'overview'" @go="switchTo" />
			<Posture v-else-if="view === 'posture'" @go="switchTo" />
			<Files v-else-if="view === 'files'" />
			<Inventory v-else-if="view === 'inventory'" />
			<Events v-else-if="view === 'events'" />
			<SettingsForm v-else />
		</main>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import Overview from '../views/Overview.vue'
import Posture from '../views/Posture.vue'
import Files from '../views/Files.vue'
import Inventory from '../views/Inventory.vue'
import Events from '../views/Events.vue'
import SettingsForm from '../views/SettingsForm.vue'

/**
 * The same console in both places.
 *
 * An administrator should not have to learn that the settings are in one place
 * and the picture of what is happening is in another — the two questions arrive
 * together ("is anything wrong, and what is watching for it?") and they should
 * be answered on the same screen. So this is mounted both inside Administration
 * → Sentinel and as a full page of its own, and it is the same thing.
 */
const props = withDefaults(defineProps<{ embedded?: boolean }>(), { embedded: false })

type View = 'overview' | 'posture' | 'files' | 'inventory' | 'events' | 'settings'
const known: View[] = ['overview', 'posture', 'files', 'inventory', 'events', 'settings']

const unseen = ref(0)

const fromHash = (): View => {
	const hash = window.location.hash.replace('#', '') as View
	return known.includes(hash) ? hash : 'overview'
}

const view = ref<View>(fromHash())

const tabs = computed(() => [
	{ id: 'overview' as const, label: t('sentinel', 'Overview') },
	{ id: 'posture' as const, label: t('sentinel', 'How things stand') },
	{ id: 'files' as const, label: t('sentinel', 'Files') },
	{ id: 'inventory' as const, label: t('sentinel', 'Who can get in') },
	{ id: 'events' as const, label: t('sentinel', 'What happened') },
	{ id: 'settings' as const, label: t('sentinel', 'Settings') },
])

const switchTo = (id: string) => {
	view.value = known.includes(id as View) ? (id as View) : 'overview'
	if (!props.embedded) {
		// In the address bar, so a reload lands where the reader was and a
		// notification can link straight to the journal. Inside the settings
		// page the hash belongs to Nextcloud's own section routing, so it is
		// left alone.
		window.history.replaceState(null, '', '#' + view.value)
	}
}

onMounted(() => {
	unseen.value = loadState<number>('sentinel', 'unseen', 0)
	if (!props.embedded) {
		window.addEventListener('hashchange', () => {
			view.value = fromHash()
		})
	}
})
</script>

<style scoped>
.console {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
	width: 100%;
}

.console__bar {
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

.console__brand { font-size: 1.2em; font-weight: 700; margin: 0; }
.console__tabs { display: flex; flex-wrap: wrap; gap: 6px; }

.console__tab {
	border: none;
	background: none;
	color: var(--color-text-maxcontrast);
	padding: 8px 14px;
	border-radius: var(--border-radius-pill, 20px);
	cursor: pointer;
	font-size: inherit;
	position: relative;
}

.console__tab:hover { background: var(--color-background-hover); color: inherit; }
.console__tab--on { background: var(--color-primary-element); color: var(--color-primary-element-text); }

.console__badge {
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

.console__body {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 22px 24px 60px;
}

/* Inside the settings page there is already a scrolling container and a
   heading, so the console gives up its own chrome and simply flows. */
.console--embedded { height: auto; }
.console--embedded .console__bar { padding: 0 0 12px; position: static; }
.console--embedded .console__body { overflow: visible; padding: 18px 0 0; }
.console__lead { color: var(--color-text-maxcontrast); max-width: 80ch; margin-top: 12px; }

@media (max-width: 700px) {
	.console__bar { padding: 10px 12px; gap: 8px 12px; }
	.console--embedded .console__bar { padding: 0 0 10px; }
	.console__brand { flex: 1 1 100%; }
	.console__tab { padding: 7px 11px; font-size: 0.95em; }
	.console__body { padding: 16px 12px 48px; }
	.console--embedded .console__body { padding: 14px 0 0; }
}
</style>
