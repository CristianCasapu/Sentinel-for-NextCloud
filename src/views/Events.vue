<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="events">
		<div class="events__tools">
			<button v-for="option in filters"
				:key="option.id"
				type="button"
				class="events__filter"
				:class="{ 'events__filter--on': option.id === severity }"
				@click="pick(option.id)">
				{{ option.label }}
			</button>
			<NcButton v-if="unseen > 0" @click="acknowledge">
				{{ t('sentinel', 'Mark {n} as read', { n: unseen }) }}
			</NcButton>
		</div>

		<ul v-if="rows.length" class="events__list">
			<li v-for="row in rows" :key="row.id" :class="['events__row', 'events__row--' + row.severity, { 'events__row--unseen': !row.seen }]">
				<span class="events__when" :title="on(row.occurred)">{{ ago(row.occurred) }}</span>
				<span class="events__summary">{{ row.summary }}</span>
				<span v-if="row.actor" class="events__dim">{{ row.actor }}</span>
				<span v-if="row.address" class="events__dim events__address">{{ row.address }}</span>
			</li>
		</ul>

		<NcEmptyContent v-else-if="!loading"
			:name="t('sentinel', 'Nothing has happened')"
			:description="t('sentinel', 'Which is the best thing a page like this can say.')" />

		<NcButton v-if="rows.length < total" :disabled="loading" class="events__more" @click="loadMore">
			{{ t('sentinel', 'Show older') }}
		</NcButton>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { events, markEventsSeen, type EventRow } from '../api'
import { ago, on } from '../format'

const rows = ref<EventRow[]>([])
const total = ref(0)
const unseen = ref(0)
const severity = ref('')
const loading = ref(false)

const filters = computed(() => [
	{ id: '', label: t('sentinel', 'Everything') },
	{ id: 'alarm', label: t('sentinel', 'Needs attention') },
	{ id: 'warning', label: t('sentinel', 'Worth a look') },
	{ id: 'notice', label: t('sentinel', 'Noted') },
])

const load = async (append = false) => {
	loading.value = true
	try {
		const page = await events(100, append ? rows.value.length : 0, severity.value)
		rows.value = append ? [...rows.value, ...page.events] : page.events
		total.value = page.total
		unseen.value = page.unseen
	} catch (error) {
		showError(t('sentinel', 'Could not read the journal'))
	} finally {
		loading.value = false
	}
}

const pick = (id: string) => {
	severity.value = id
	void load()
}

const loadMore = () => load(true)

const acknowledge = async () => {
	await markEventsSeen()
	unseen.value = 0
	rows.value = rows.value.map((row) => ({ ...row, seen: true }))
}

onMounted(() => load())
</script>

<style scoped>
.events { max-width: 1100px; }
.events__tools { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; align-items: center; }

.events__filter {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	border-radius: var(--border-radius-pill, 20px);
	padding: 6px 14px;
	cursor: pointer;
	color: inherit;
}

.events__filter--on { background: var(--color-primary-element); color: var(--color-primary-element-text); border-color: transparent; }
.events__list { list-style: none; margin: 0; padding: 0; }

.events__row {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 14px;
	padding: 10px 12px;
	border-bottom: 1px solid var(--color-border);
	border-inline-start: 3px solid transparent;
}

.events__row--alarm { border-inline-start-color: var(--color-error); }
.events__row--warning { border-inline-start-color: var(--color-warning); }
.events__row--unseen { background: var(--color-background-hover); }
.events__when { color: var(--color-text-maxcontrast); flex: 0 0 130px; font-size: 0.9em; }
.events__summary { flex: 1; min-width: 200px; }
.events__dim { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.events__address { font-family: var(--font-face-monospace, monospace); }
.events__more { margin: 16px auto 0; }

@media (max-width: 640px) {
	.events__when { flex: 1 1 100%; }
	.events__summary { flex: 1 1 100%; }
}
</style>
