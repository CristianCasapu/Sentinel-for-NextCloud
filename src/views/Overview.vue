<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="over">
		<div v-if="loading" class="over__loading">{{ t('sentinel', 'Looking…') }}</div>

		<template v-else-if="report">
			<div class="over__headline" :class="'over__headline--' + report.state">
				<div>
					<h3>{{ headline }}</h3>
					<p>{{ t('sentinel', 'Checked {when}', { when: ago(report.checkedAt) }) }}</p>
				</div>
				<NcButton @click="$emit('go', 'posture')">{{ t('sentinel', 'See the detail') }}</NcButton>
			</div>

			<!-- Whether anything would actually reach a person. A server with
			     every watcher running and no way to reach anybody is a server
			     with no watchers at all, and it looks identical from inside. -->
			<div class="over__reach" :class="{ 'over__reach--broken': !reachable }">
				<div class="over__reachtext">
					<strong>{{ reachable ? t('sentinel', 'You will be told') : t('sentinel', 'Nothing would reach you') }}</strong>
					<span>{{ reachText }}</span>
				</div>
				<NcButton :disabled="testing || !report.delivery.configured" @click="sendTest">
					{{ testing ? t('sentinel', 'Sending…') : t('sentinel', 'Send a test message') }}
				</NcButton>
			</div>

			<div v-if="report.busy.length" class="over__busy">
				<strong>{{ t('sentinel', 'Files changing right now') }}</strong>
				<span v-for="row in report.busy" :key="row.uid + row.kind">
					{{ row.kind === 'delete'
						? t('sentinel', '{uid}: {n} deleted', { uid: row.uid, n: row.count })
						: t('sentinel', '{uid}: {n} rewritten', { uid: row.uid, n: row.count }) }}
				</span>
			</div>

			<div class="over__tiles">
				<button v-for="tile in tiles" :key="tile.id" class="over__tile" :class="'over__tile--' + tile.tone" @click="$emit('go', tile.go)">
					<span class="over__figure">{{ tile.figure }}</span>
					<span class="over__label">{{ tile.label }}</span>
					<span class="over__note">{{ tile.note }}</span>
				</button>
			</div>

			<section class="over__section">
				<h3>{{ t('sentinel', 'What is being watched') }}</h3>
				<p class="over__intro">
					{{ t('sentinel', 'The most expensive mistake with an app like this is assuming it watches something it was never told to watch. So here is the list, plainly.') }}
				</p>
				<ul class="over__watchers">
					<li v-for="watcher in report.watchers" :key="watcher.id" :class="{ 'over__watcher--off': !watcher.on }">
						<span class="over__mark">{{ watcher.on ? '●' : '○' }}</span>
						<span class="over__wname">{{ watcher.name }}</span>
						<span class="over__wwhat">{{ watcher.what }}</span>
						<span class="over__wstate">{{ watcher.on ? t('sentinel', 'watching') : t('sentinel', 'off') }}</span>
					</li>
				</ul>
			</section>

			<section class="over__section">
				<h3>{{ t('sentinel', 'Lately') }}</h3>
				<p class="over__intro">
					{{ t('sentinel', '{day} in the last day, {week} in the last week.', { day: total(report.events.day), week: total(report.events.week) }) }}
				</p>
				<ul v-if="report.events.recent.length" class="over__events">
					<li v-for="row in report.events.recent" :key="row.id" :class="'over__event--' + row.severity">
						<span class="over__when">{{ ago(row.occurred) }}</span>
						<span class="over__what">{{ row.summary }}</span>
					</li>
				</ul>
				<p v-else class="over__quiet">{{ t('sentinel', 'Nothing has happened. Which is the best thing this page can say.') }}</p>
				<NcButton @click="$emit('go', 'events')">{{ t('sentinel', 'The whole journal') }}</NcButton>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import { overview, testMail, type OverviewReport } from '../api'
import { ago } from '../format'

defineEmits<{ go: [tab: string] }>()

const report = ref<OverviewReport | null>(null)
const loading = ref(true)
const testing = ref(false)

const headline = computed(() => {
	if (!report.value) {
		return ''
	}
	const counts = report.value.counts
	if (counts.bad > 0) {
		return t('sentinel', '{n} things need attention', { n: counts.bad })
	}
	if (counts.warn > 0) {
		return t('sentinel', '{n} things are worth a look', { n: counts.warn })
	}
	if (counts.note > 0) {
		return t('sentinel', 'Nothing urgent, {n} things worth knowing', { n: counts.note })
	}
	return t('sentinel', 'Everything checked is in order')
})

const reachable = computed(() =>
	!!report.value && report.value.delivery.configured && (report.value.delivery.enabled || report.value.delivery.inApp))

const reachText = computed(() => {
	if (!report.value) {
		return ''
	}
	const delivery = report.value.delivery
	if (!delivery.configured) {
		return t('sentinel', 'No administrator has an email address, and none was added by hand. An administrator who is not signed in has no bell either.')
	}
	const parts: string[] = []
	if (delivery.inApp) {
		parts.push(t('sentinel', 'in the bell menu'))
	}
	if (delivery.enabled) {
		parts.push(t('sentinel', 'by mail to {who}', { who: delivery.recipients.join(', ') }))
	}
	if (parts.length === 0) {
		return t('sentinel', 'Both the bell and the mail are switched off, so a finding would sit on this page until somebody came looking.')
	}
	const daily = delivery.digest
		? t('sentinel', 'A summary is sent every day at {hour}:00.', { hour: String(delivery.digestHour).padStart(2, '0') })
		: t('sentinel', 'No daily summary is sent.')
	return t('sentinel', 'Findings arrive {how}. {daily}', { how: parts.join(t('sentinel', ' and ')), daily })
})

const total = (tally: Record<string, number>) =>
	Object.values(tally).reduce((sum, n) => sum + n, 0)

const tiles = computed(() => {
	if (!report.value) {
		return []
	}
	const n = report.value.numbers
	return [
		{
			id: 'accounts',
			go: 'inventory',
			figure: `${n.accounts - n.withoutTwoFactor}/${n.accounts}`,
			label: t('sentinel', 'with a second factor'),
			note: n.withoutTwoFactor > 0
				? t('sentinel', '{n} can be signed in as with a password alone', { n: n.withoutTwoFactor })
				: t('sentinel', 'every account is protected'),
			tone: n.withoutTwoFactor > 0 ? 'warn' : 'good',
		},
		{
			id: 'links',
			go: 'inventory',
			figure: String(n.links),
			label: t('sentinel', 'links open to anyone'),
			note: n.linkOpens > 0
				? t('sentinel', 'opened {n} times in 30 days, the busiest from {networks} networks', { n: n.linkOpens, networks: n.linkBusiestNetworks })
				: t('sentinel', 'none opened in the last 30 days'),
			tone: 'plain',
		},
		{
			id: 'tokens',
			go: 'inventory',
			figure: String(n.tokens),
			label: t('sentinel', 'sessions and app passwords'),
			note: n.coldTokens > 0
				? t('sentinel', '{n} unused for months and still valid', { n: n.coldTokens })
				: t('sentinel', 'all in recent use'),
			tone: n.coldTokens > 0 ? 'warn' : 'good',
		},
		{
			id: 'files',
			go: 'files',
			figure: n.baselineFiles > 0 ? String(n.baselineFiles) : '—',
			label: t('sentinel', 'files recorded'),
			note: n.baselineFiles === 0
				? t('sentinel', 'no baseline taken yet')
				: (n.baselineChanged > 0
					? t('sentinel', '{n} differ from the baseline', { n: n.baselineChanged })
					: t('sentinel', 'all match, checked {when}', { when: ago(n.baselineComparedAt) })),
			tone: n.baselineChanged > 0 ? 'bad' : (n.baselineFiles === 0 ? 'plain' : 'good'),
		},
		{
			id: 'exposure',
			go: 'posture',
			figure: n.exposureServed > 0 ? String(n.exposureServed) : '✓',
			label: t('sentinel', 'files the server hands out'),
			note: n.exposureCheckedAt === 0
				? t('sentinel', 'not asked yet')
				: (n.exposureServed > 0
					? t('sentinel', 'downloadable by anyone who guesses the address')
					: t('sentinel', 'refused every one, asked {when}', { when: ago(n.exposureCheckedAt) })),
			tone: n.exposureServed > 0 ? 'bad' : (n.exposureCheckedAt === 0 ? 'plain' : 'good'),
		},
		{
			id: 'certificate',
			go: 'posture',
			figure: n.certificateKnown ? String(n.certificateDays) : '—',
			label: t('sentinel', 'days of certificate left'),
			note: n.certificateKnown
				? t('sentinel', 'renewal is automatic until the day it is not')
				: t('sentinel', 'could not be read from here'),
			tone: n.certificateKnown && n.certificateDays <= 14 ? 'warn' : 'plain',
		},
	]
})

const load = async () => {
	try {
		report.value = await overview()
	} catch (error) {
		showError(t('sentinel', 'Could not read the state of the installation'))
	} finally {
		loading.value = false
	}
}

const sendTest = async () => {
	testing.value = true
	try {
		const result = await testMail()
		showSuccess(t('sentinel', 'Sent to {who}. If it does not arrive, the mail settings are where to look.', { who: result.recipients.join(', ') }))
	} catch (error) {
		showError(t('sentinel', 'The message could not be sent. The server log will say why.'))
	} finally {
		testing.value = false
	}
}

onMounted(load)
</script>

<style scoped>
.over { max-width: 1100px; }
.over__loading { padding: 40px; color: var(--color-text-maxcontrast); }

.over__headline {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
	justify-content: space-between;
	padding: 18px 20px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-background-dark);
	margin-bottom: 14px;
}

.over__headline h3 { margin: 0 0 2px; font-size: 1.35em; }
.over__headline p { margin: 0; color: var(--color-text-maxcontrast); }
.over__headline--bad { background: var(--color-error-hover, var(--color-error)); color: var(--color-primary-text); }
.over__headline--bad p { color: inherit; opacity: 0.85; }
.over__headline--warn { background: var(--color-warning-hover, var(--color-warning)); color: #000; }
.over__headline--warn p { color: inherit; opacity: 0.8; }

.over__reach {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
	justify-content: space-between;
	padding: 14px 20px;
	border: 1px solid var(--color-border);
	border-inline-start: 4px solid var(--color-success);
	border-radius: var(--border-radius-large, 12px);
	margin-bottom: 14px;
}

.over__reach--broken { border-inline-start-color: var(--color-error); }
.over__reachtext { display: flex; flex-direction: column; gap: 2px; max-width: 72ch; }
.over__reachtext span { color: var(--color-text-maxcontrast); }

.over__busy {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 16px;
	padding: 12px 20px;
	margin-bottom: 14px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-warning-hover, var(--color-background-dark));
}

.over__tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
	gap: 10px;
	margin-bottom: 24px;
}

.over__tile {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	text-align: start;
	cursor: pointer;
	color: inherit;
	font-size: inherit;
}

.over__tile:hover { background: var(--color-background-hover); }
.over__tile--warn { border-inline-start: 4px solid var(--color-warning); }
.over__tile--bad { border-inline-start: 4px solid var(--color-error); }
.over__tile--good { border-inline-start: 4px solid var(--color-success); }
.over__figure { font-size: 1.7em; font-weight: 700; line-height: 1.1; }
.over__label { font-weight: 600; }
.over__note { color: var(--color-text-maxcontrast); font-size: 0.9em; }

.over__section { margin-bottom: 26px; }
.over__section h3 { margin-bottom: 4px; }
.over__intro { color: var(--color-text-maxcontrast); max-width: 80ch; margin-bottom: 10px; }
.over__watchers, .over__events { list-style: none; margin: 0 0 10px; padding: 0; }

.over__watchers li {
	display: grid;
	grid-template-columns: 20px 1fr 80px;
	gap: 2px 10px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
	align-items: baseline;
}

.over__mark { color: var(--color-success); grid-row: span 2; }
.over__watcher--off .over__mark { color: var(--color-text-maxcontrast); }
.over__wname { font-weight: 600; }
.over__wwhat { grid-column: 2; color: var(--color-text-maxcontrast); font-size: 0.9em; }
.over__wstate { color: var(--color-text-maxcontrast); font-size: 0.85em; text-align: end; }
.over__watcher--off .over__wstate { color: var(--color-warning); }

.over__events li {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 14px;
	padding: 7px 0 7px 10px;
	border-bottom: 1px solid var(--color-border);
	border-inline-start: 3px solid transparent;
}

.over__event--alarm { border-inline-start-color: var(--color-error); }
.over__event--warning { border-inline-start-color: var(--color-warning); }
.over__when { color: var(--color-text-maxcontrast); flex: 0 0 120px; font-size: 0.9em; }
.over__what { flex: 1; min-width: 200px; }
.over__quiet { color: var(--color-text-maxcontrast); margin-bottom: 10px; }

@media (max-width: 640px) {
	.over__headline, .over__reach, .over__busy { padding: 14px; }
	.over__watchers li { grid-template-columns: 20px 1fr; }
	.over__wstate { grid-column: 2; text-align: start; }
	.over__when { flex: 1 1 100%; }
}
</style>
