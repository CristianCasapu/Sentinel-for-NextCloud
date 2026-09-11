<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="posture">
		<div v-if="loading" class="posture__loading">{{ t('sentinel', 'Looking…') }}</div>

		<template v-else-if="report">
			<div class="posture__headline" :class="'posture__headline--' + worst">
				<h2>{{ headline }}</h2>
				<p>{{ t('sentinel', 'Checked {when}', { when: ago(report.checkedAt) }) }}</p>
			</div>

			<Finding v-for="finding in report.findings" :key="finding.id" :finding="finding">
				<template #detail="{ detail }">
					<!-- The names behind the number. A count tells you there is
					     a problem; a list tells you whose it is. -->
					<ul v-if="finding.id === 'two_factor' && withoutTwoFactor(detail).length" class="posture__list">
						<li v-for="person in withoutTwoFactor(detail)" :key="person.uid">
							<span class="posture__who">{{ person.name }}</span>
							<span class="posture__dim">{{ person.uid }}</span>
							<span class="posture__dim">{{ t('sentinel', 'last seen {when}', { when: ago(person.lastSeen) }) }}</span>
						</li>
					</ul>

					<ul v-else-if="finding.id === 'administrators'" class="posture__list">
						<li v-for="person in admins(detail)" :key="person.uid">
							<span class="posture__who">{{ person.name }}</span>
							<span class="posture__dim">{{ person.uid }}</span>
							<span :class="person.twoFactor ? 'posture__ok' : 'posture__bad'">
								{{ person.twoFactor ? t('sentinel', 'second factor') : t('sentinel', 'password only') }}
							</span>
							<span class="posture__dim">{{ t('sentinel', 'last seen {when}', { when: ago(person.lastSeen) }) }}</span>
						</li>
					</ul>

					<ul v-else-if="finding.id === 'app_passwords' && stale(detail).length" class="posture__list">
						<li v-for="token in stale(detail)" :key="token.uid + token.name">
							<span class="posture__who">{{ token.name }}</span>
							<span class="posture__dim">{{ token.uid }}</span>
							<span class="posture__dim">{{ t('sentinel', 'last used {when}', { when: ago(token.lastUsed) }) }}</span>
						</li>
					</ul>

					<p v-else-if="finding.id === 'public_links' && Number(detail.total) > 0" class="posture__pointer">
						<NcButton @click="$emit('go', 'inventory')">{{ t('sentinel', 'See the links') }}</NcButton>
					</p>

					<p v-else-if="finding.id === 'baseline'" class="posture__pointer">
						<NcButton @click="$emit('go', 'files')">{{ t('sentinel', 'Open the file baseline') }}</NcButton>
					</p>

					<template v-else-if="finding.id === 'exposure'">
						<ul v-if="servedList(detail).length" class="posture__list">
							<li v-for="item in servedList(detail)" :key="item.path">
								<span class="posture__bad">{{ t('sentinel', 'served') }}</span>
								<span class="posture__path">{{ item.path }}</span>
							</li>
						</ul>
						<p class="posture__pointer">
							<NcButton :disabled="probing" @click="askTheServer">
								{{ probing ? t('sentinel', 'Asking…') : t('sentinel', 'Ask the server now') }}
							</NcButton>
						</p>
					</template>
				</template>
			</Finding>
		</template>

		<NcEmptyContent v-else :name="t('sentinel', 'Nothing to report yet')" />
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import Finding from '../components/Finding.vue'
import { posture, probe, type PostureReport } from '../api'
import { ago } from '../format'

defineEmits<{ go: [tab: string] }>()

const report = ref<PostureReport | null>(null)
const loading = ref(true)

const worst = computed(() => {
	if (!report.value) {
		return 'good'
	}
	const counts = report.value.counts
	if (counts.bad > 0) {
		return 'bad'
	}
	if (counts.warn > 0) {
		return 'warn'
	}
	return counts.note > 0 ? 'note' : 'good'
})

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

const withoutTwoFactor = (detail: Record<string, unknown>) =>
	(detail.without ?? []) as Array<{ uid: string, name: string, lastSeen: number }>
const admins = (detail: Record<string, unknown>) =>
	(detail.admins ?? []) as Array<{ uid: string, name: string, lastSeen: number, twoFactor: boolean }>
const stale = (detail: Record<string, unknown>) =>
	(detail.stale ?? []) as Array<{ uid: string, name: string, lastUsed: number }>
const servedList = (detail: Record<string, unknown>) =>
	(detail.served ?? []) as Array<{ path: string, code: number }>

const probing = ref(false)

/**
 * Ask the web server, now, what it is willing to hand out. Thirty-odd requests,
 * so it is a button rather than something the page does on its own.
 */
const askTheServer = async () => {
	probing.value = true
	try {
		const result = await probe()
		report.value = await posture()
		showSuccess(result.served.length === 0
			? t('sentinel', 'Asked for {n} files that should never be served. Refused every time.', { n: result.checked })
			: t('sentinel', '{n} files are being served that should not be.', { n: result.served.length }))
	} catch (error) {
		showError(t('sentinel', 'The server could not ask itself. The log will say why.'))
	} finally {
		probing.value = false
	}
}

onMounted(async () => {
	try {
		report.value = await posture()
	} catch (error) {
		showError(t('sentinel', 'Could not read the state of the installation'))
	} finally {
		loading.value = false
	}
})
</script>

<style scoped>
.posture { max-width: 1100px; }
.posture__loading { padding: 40px; color: var(--color-text-maxcontrast); }

.posture__headline {
	padding: 20px 22px;
	border-radius: var(--border-radius-large, 12px);
	margin-bottom: 20px;
	background: var(--color-background-dark);
}

.posture__headline h2 { margin: 0 0 4px; font-size: 1.5em; }
.posture__headline p { margin: 0; color: var(--color-text-maxcontrast); }
.posture__headline--bad { background: var(--color-error-hover, var(--color-error)); color: var(--color-primary-text); }
.posture__headline--bad p { color: inherit; opacity: 0.85; }
.posture__headline--warn { background: var(--color-warning-hover, var(--color-warning)); color: #000; }
.posture__headline--warn p { color: inherit; opacity: 0.8; }

.posture__list { list-style: none; margin: 0 0 8px; padding: 0; }

.posture__list li {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 14px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.posture__who { font-weight: 600; }
.posture__dim { color: var(--color-text-maxcontrast); }
.posture__ok { color: var(--color-success); }
.posture__bad { color: var(--color-error); font-weight: 600; }
.posture__pointer { margin-top: 6px; }

.posture__path {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.9em;
	overflow-wrap: anywhere;
}

@media (max-width: 640px) {
	.posture__headline { padding: 16px; }
	.posture__headline h2 { font-size: 1.25em; }
}
</style>
