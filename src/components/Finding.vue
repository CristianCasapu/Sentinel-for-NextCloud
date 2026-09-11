<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="finding" :class="'finding--' + finding.state">
		<button class="finding__head" type="button" :aria-expanded="open" @click="open = !open">
			<span class="finding__mark" :aria-label="stateLabel">{{ mark }}</span>
			<span class="finding__text">
				<span class="finding__title">{{ finding.title }}</span>
				<span class="finding__summary">{{ finding.summary }}</span>
			</span>
			<span class="finding__chevron" :class="{ 'finding__chevron--open': open }">›</span>
		</button>

		<div v-if="open" class="finding__body">
			<!-- Why it matters comes before what to do about it: a fix nobody
			     understands the reason for is a fix nobody applies. -->
			<p class="finding__why">{{ finding.why }}</p>
			<p class="finding__fix"><strong>{{ t('sentinel', 'What to do') }}</strong> {{ finding.fix }}</p>
			<slot name="detail" :detail="finding.detail" />
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import type { Finding } from '../api'

const props = defineProps<{ finding: Finding }>()
const open = ref(props.finding.state === 'bad')

const mark = computed(() => ({ good: '✓', note: '·', warn: '!', bad: '!!' }[props.finding.state]))
const stateLabel = computed(() => ({
	good: t('sentinel', 'Fine'),
	note: t('sentinel', 'Worth noting'),
	warn: t('sentinel', 'Worth a look'),
	bad: t('sentinel', 'Needs attention'),
}[props.finding.state]))
</script>

<style scoped>
.finding {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	margin-bottom: 10px;
	background: var(--color-main-background);
	overflow: hidden;
}

.finding--bad { border-left: 4px solid var(--color-error); }
.finding--warn { border-left: 4px solid var(--color-warning); }
.finding--note { border-left: 4px solid var(--color-border-dark); }
.finding--good { border-left: 4px solid var(--color-success); }

.finding__head {
	display: flex;
	align-items: center;
	gap: 14px;
	width: 100%;
	padding: 14px 16px;
	background: none;
	border: none;
	text-align: start;
	cursor: pointer;
	font-size: inherit;
	color: inherit;
}

.finding__head:hover { background: var(--color-background-hover); }

.finding__mark {
	flex: 0 0 28px;
	height: 28px;
	line-height: 28px;
	text-align: center;
	border-radius: 50%;
	font-weight: 700;
	background: var(--color-background-dark);
}

.finding--bad .finding__mark { background: var(--color-error); color: var(--color-primary-text); }
.finding--warn .finding__mark { background: var(--color-warning); color: #000; }
.finding--good .finding__mark { background: var(--color-success); color: var(--color-primary-text); }

.finding__text { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
.finding__title { font-weight: 600; }
.finding__summary { color: var(--color-text-maxcontrast); }
.finding__chevron { transition: transform 0.15s; font-size: 20px; color: var(--color-text-maxcontrast); }
.finding__chevron--open { transform: rotate(90deg); }

.finding__body {
	padding: 0 16px 16px calc(16px + 28px + 14px);
	max-width: 80ch;
}

.finding__why { color: var(--color-text-maxcontrast); margin-bottom: 10px; }
.finding__fix { margin-bottom: 10px; }

@media (max-width: 640px) {
	.finding__head { gap: 10px; padding: 12px; }
	.finding__body { padding: 0 12px 12px 12px; }
}
</style>
