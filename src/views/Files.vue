<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="files">
		<div class="files__intro">
			<h2>{{ t('sentinel', 'The files of this installation') }}</h2>
			<p>
				{{ t('sentinel', 'Nextcloud checks its own files against the signatures it shipped with. On an installation that has ever been patched, that check fails for ever — and a warning that never goes away is a warning nobody reads. This records what the files look like at a moment you choose, and reports only what has moved since.') }}
			</p>
		</div>

		<div v-if="status" class="files__state">
			<template v-if="!status.taken">
				<p>{{ t('sentinel', 'No baseline has been taken yet.') }}</p>
				<NcButton variant="primary" :disabled="busy" @click="take">
					{{ t('sentinel', 'Take a baseline now') }}
				</NcButton>
			</template>

			<template v-else>
				<p class="files__summary">
					{{ t('sentinel', '{n} files recorded', { n: status.files }) }} ·
					{{ t('sentinel', 'approved {when} by {who}', { when: on(status.takenAt), who: status.takenBy || '—' }) }}
				</p>

				<p v-if="status.comparedAt === 0">{{ t('sentinel', 'Not compared yet.') }}</p>
				<p v-else-if="moved === 0" class="files__clean">
					{{ t('sentinel', 'Nothing has changed since. Last walked {when}, {n} files in {seconds}s.', { when: ago(status.comparedAt), n: status.walked, seconds: status.took }) }}
				</p>
				<p v-else class="files__dirty">
					{{ t('sentinel', '{changed} changed, {added} new, {removed} gone', { changed: status.changed, added: status.added, removed: status.removed }) }}
				</p>

				<div class="files__actions">
					<NcButton :disabled="busy" @click="compare">{{ t('sentinel', 'Check now') }}</NcButton>
					<NcButton v-if="moved > 0" :disabled="busy || chosen.length === 0" variant="primary" @click="accept">
						{{ t('sentinel', 'Accept {n} selected', { n: chosen.length }) }}
					</NcButton>
					<NcButton :disabled="busy" @click="take">{{ t('sentinel', 'Re-take the whole baseline') }}</NcButton>
				</div>
			</template>
		</div>

		<div v-if="status && status.differences.length" class="files__diff">
			<div class="files__difftools">
				<NcCheckboxRadioSwitch :model-value="allChosen" @update:model-value="chooseAll">
					{{ t('sentinel', 'Select all') }}
				</NcCheckboxRadioSwitch>
				<input v-model="note"
					class="files__note"
					type="text"
					:placeholder="t('sentinel', 'Why are these allowed to differ? (kept with the record)')">
			</div>

			<ul class="files__list">
				<li v-for="difference in status.differences" :key="difference.path" :class="'files__row--' + difference.state">
					<NcCheckboxRadioSwitch :model-value="chosen.includes(difference.path)"
						@update:model-value="choose(difference.path, $event)" />
					<span class="files__mark">{{ label(difference.state) }}</span>
					<span class="files__path" :title="difference.path">{{ difference.path }}</span>
					<span class="files__size">{{ difference.state === 'removed' ? bytes(difference.wasSize || 0) : bytes(difference.size) }}</span>
					<span class="files__when">{{ difference.modified ? ago(difference.modified) : '—' }}</span>
				</li>
			</ul>

			<p v-if="status.truncated" class="files__more">
				{{ t('sentinel', 'There are more differences than are kept in the list. Run occ sentinel:baseline compare to see them all.') }}
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { baselineAcknowledge, baselineCompare, baselineStatus, baselineTake, type BaselineStatus } from '../api'
import { ago, bytes, on } from '../format'

const status = ref<BaselineStatus | null>(null)
const chosen = ref<string[]>([])
const note = ref('')
const busy = ref(false)

const moved = computed(() => {
	if (!status.value) {
		return 0
	}
	return status.value.changed + status.value.added + status.value.removed
})

const allChosen = computed(() =>
	!!status.value && status.value.differences.length > 0 && chosen.value.length === status.value.differences.length)

const label = (state: string) => ({
	changed: t('sentinel', 'changed'),
	added: t('sentinel', 'new'),
	removed: t('sentinel', 'gone'),
}[state] ?? state)

const choose = (path: string, wanted: boolean) => {
	chosen.value = wanted
		? [...chosen.value, path]
		: chosen.value.filter((p) => p !== path)
}

const chooseAll = (wanted: boolean) => {
	chosen.value = wanted && status.value ? status.value.differences.map((d) => d.path) : []
}

const run = async (work: () => Promise<BaselineStatus>, done?: string) => {
	busy.value = true
	try {
		status.value = await work()
		chosen.value = []
		if (done) {
			showSuccess(done)
		}
	} catch (error) {
		showError(t('sentinel', 'That did not work. The server log will say why.'))
	} finally {
		busy.value = false
	}
}

const compare = () => run(baselineCompare)
const take = () => run(baselineTake, t('sentinel', 'The installation as it is now is the new baseline.'))
const accept = () => run(
	() => baselineAcknowledge(chosen.value, note.value),
	t('sentinel', 'Accepted. Those files will not be reported again until they change.'),
)

onMounted(async () => {
	try {
		status.value = await baselineStatus()
	} catch (error) {
		showError(t('sentinel', 'Could not read the baseline'))
	}
})
</script>

<style scoped>
.files { max-width: 1100px; }
.files__intro h2 { margin: 0 0 6px; }
.files__intro p { color: var(--color-text-maxcontrast); max-width: 80ch; margin-bottom: 18px; }

.files__state {
	padding: 16px 18px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-dark);
	margin-bottom: 18px;
}

.files__summary { color: var(--color-text-maxcontrast); }
.files__clean { color: var(--color-success); font-weight: 600; }
.files__dirty { color: var(--color-error); font-weight: 600; }
.files__actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }

.files__difftools {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.files__note { flex: 1; min-width: 240px; }
.files__list { list-style: none; margin: 0; padding: 0; }

.files__list li {
	display: grid;
	grid-template-columns: auto 80px 1fr 90px 130px;
	align-items: center;
	gap: 10px;
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
}

.files__mark { font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.04em; }
.files__row--changed .files__mark { color: var(--color-error); font-weight: 700; }
.files__row--removed .files__mark { color: var(--color-error); }
.files__row--added .files__mark { color: var(--color-text-maxcontrast); }

.files__path {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.9em;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	direction: rtl;
	text-align: start;
}

.files__size,
.files__when { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.files__more { margin-top: 10px; color: var(--color-text-maxcontrast); }

@media (max-width: 900px) {
	.files__list li { grid-template-columns: auto 70px 1fr; }
	.files__size, .files__when { display: none; }
}
</style>
