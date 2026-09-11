<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="inventory">
		<div class="inventory__tabs">
			<button v-for="part in parts"
				:key="part.id"
				type="button"
				class="inventory__tab"
				:class="{ 'inventory__tab--on': part.id === showing }"
				@click="showing = part.id">
				{{ part.label }} <span class="inventory__count">{{ part.count }}</span>
			</button>
		</div>

		<div v-if="report && report.busy.length" class="inventory__busy">
			<strong>{{ t('sentinel', 'Files changing right now') }}</strong>
			<span v-for="row in report.busy" :key="row.uid + row.kind">
				{{ row.kind === 'delete'
					? t('sentinel', '{uid}: {n} deleted', { uid: row.uid, n: row.count })
					: t('sentinel', '{uid}: {n} rewritten', { uid: row.uid, n: row.count }) }}
			</span>
		</div>

		<div v-if="loading" class="inventory__loading">{{ t('sentinel', 'Looking…') }}</div>

		<template v-else-if="report">
			<section v-if="showing === 'links'">
				<p class="inventory__intro">
					{{ t('sentinel', 'A link is meant to be given away — that is the point of it. What is shown here is how each one is actually being used over the last 30 days, so that a link being opened by people it was never sent to stands out.') }}
				</p>
				<ul class="inventory__list">
					<li v-for="link in report.links.links" :key="link.id" :class="{ 'inventory__row--busy': link.networks >= 25 }">
						<span class="inventory__name" :title="link.target">{{ link.target || t('sentinel', '(deleted)') }}</span>
						<span class="inventory__dim">{{ link.owner }}</span>
						<span class="inventory__dim">{{ t('sentinel', 'made {when}', { when: ago(link.created) }) }}</span>
						<span class="inventory__dim">
							{{ link.hasPassword ? t('sentinel', 'password') : t('sentinel', 'no password') }}
						</span>
						<span class="inventory__dim">
							{{ link.expires ? t('sentinel', 'until {when}', { when: on(link.expires) }) : t('sentinel', 'no expiry') }}
						</span>
						<span :class="link.networks >= 25 ? 'inventory__warn' : 'inventory__use'">
							{{ link.views + link.downloads === 0
								? t('sentinel', 'unused')
								: t('sentinel', '{n} opens from {networks} networks', { n: link.views + link.downloads, networks: link.networks }) }}
						</span>
						<span v-if="link.failures > 0" class="inventory__warn">
							{{ t('sentinel', '{n} refused', { n: link.failures }) }}
						</span>
						<span class="inventory__does">
							<NcButton v-if="!link.expires" :disabled="busy" @click="expire(link.id)">
								{{ t('sentinel', 'Expire in 30 days') }}
							</NcButton>
							<NcButton :disabled="busy" variant="error" @click="close(link.id)">
								{{ t('sentinel', 'Remove') }}
							</NcButton>
						</span>
					</li>
				</ul>
				<NcEmptyContent v-if="!report.links.links.length" :name="t('sentinel', 'Nothing is shared by link')" />
			</section>

			<section v-else-if="showing === 'tokens'">
				<p class="inventory__intro">
					{{ t('sentinel', 'Every key currently cut for every account. An application password opens the account without a second factor, does not expire, and nothing removes it when the device it was made for stops being used.') }}
				</p>
				<NcCheckboxRadioSwitch :model-value="onlyCold" @update:model-value="onlyCold = $event">
					{{ t('sentinel', 'Only the ones nobody has used lately') }}
				</NcCheckboxRadioSwitch>
				<ul class="inventory__list">
					<li v-for="token in tokens" :key="token.id" :class="{ 'inventory__row--open': token.cold }">
						<span class="inventory__name" :title="token.name">{{ token.name || t('sentinel', 'unnamed') }}</span>
						<span class="inventory__dim">{{ token.uid }}</span>
						<span class="inventory__dim">{{ token.kind === 'application' ? t('sentinel', 'application password') : t('sentinel', 'session') }}</span>
						<span :class="token.cold ? 'inventory__warn' : 'inventory__dim'">
							{{ t('sentinel', 'last used {when}', { when: ago(token.lastUsed) }) }}
						</span>
						<span class="inventory__does">
							<NcButton :disabled="busy" variant="error" @click="revoke(token.uid, token.id)">
								{{ t('sentinel', 'Revoke') }}
							</NcButton>
						</span>
					</li>
				</ul>
			</section>

			<section v-else>
				<p class="inventory__intro">
					{{ t('sentinel', 'Whoever can do the most damage, with the least standing in the way, first.') }}
				</p>
				<ul class="inventory__list">
					<li v-for="account in report.accounts.accounts" :key="account.uid">
						<span class="inventory__name">{{ account.name }}</span>
						<span class="inventory__dim">{{ account.uid }}</span>
						<span :class="account.admin ? 'inventory__warn' : 'inventory__dim'">
							{{ account.admin ? t('sentinel', 'administrator') : t('sentinel', 'account') }}
						</span>
						<span :class="account.twoFactor ? 'inventory__ok' : 'inventory__warn'">
							{{ account.twoFactor ? t('sentinel', 'second factor') : t('sentinel', 'password only') }}
						</span>
						<span class="inventory__dim">{{ t('sentinel', 'last seen {when}', { when: ago(account.lastSeen) }) }}</span>
						<span class="inventory__dim">{{ t('sentinel', '{n} known networks', { n: account.places }) }}</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { closeLink, expireLink, inventory, revokeToken, type InventoryReport } from '../api'
import { ago, on } from '../format'

const report = ref<InventoryReport | null>(null)
const loading = ref(true)
const busy = ref(false)
const showing = ref<'links' | 'tokens' | 'accounts'>('links')
const onlyCold = ref(false)

const parts = computed(() => [
	{ id: 'links' as const, label: t('sentinel', 'Links'), count: report.value?.links.total ?? 0 },
	{ id: 'tokens' as const, label: t('sentinel', 'Sessions and app passwords'), count: report.value?.tokens.total ?? 0 },
	{ id: 'accounts' as const, label: t('sentinel', 'Accounts'), count: report.value?.accounts.total ?? 0 },
])

const tokens = computed(() => {
	const all = report.value?.tokens.tokens ?? []
	return onlyCold.value ? all.filter((token) => token.cold) : all
})

const load = async () => {
	try {
		report.value = await inventory()
	} catch (error) {
		showError(t('sentinel', 'Could not read the inventory'))
	} finally {
		loading.value = false
	}
}

const act = async (work: () => Promise<unknown>, done: string) => {
	busy.value = true
	try {
		await work()
		await load()
		showSuccess(done)
	} catch (error) {
		showError(t('sentinel', 'That did not work. The server log will say why.'))
	} finally {
		busy.value = false
	}
}

const expire = (id: number) => act(() => expireLink(id, 30), t('sentinel', 'The link will stop working in 30 days.'))
const close = (id: number) => act(() => closeLink(id), t('sentinel', 'The link no longer works.'))
const revoke = (uid: string, id: number) => act(() => revokeToken(uid, id), t('sentinel', 'Revoked. Whatever was using it will have to sign in again.'))

onMounted(load)
</script>

<style scoped>
.inventory { max-width: 1200px; }

.inventory__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 14px;
}

.inventory__tab {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	border-radius: var(--border-radius-pill, 20px);
	padding: 6px 14px;
	cursor: pointer;
	color: inherit;
}

.inventory__tab--on { background: var(--color-primary-element); color: var(--color-primary-element-text); border-color: transparent; }
.inventory__count { opacity: 0.7; margin-inline-start: 4px; }
.inventory__intro { color: var(--color-text-maxcontrast); max-width: 80ch; margin-bottom: 12px; }
.inventory__loading { padding: 40px; color: var(--color-text-maxcontrast); }
.inventory__list { list-style: none; margin: 0; padding: 0; }

.inventory__list li {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 16px;
	padding: 10px 8px;
	border-bottom: 1px solid var(--color-border);
}

.inventory__row--busy { background: var(--color-warning-hover, transparent); }
.inventory__row--busy .inventory__dim { color: inherit; opacity: 0.8; }
.inventory__use { color: var(--color-text-maxcontrast); }

.inventory__busy {
	display: flex;
	flex-wrap: wrap;
	gap: 6px 16px;
	padding: 10px 14px;
	margin-bottom: 14px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-warning-hover, var(--color-background-dark));
}
.inventory__name { font-weight: 600; max-width: 40ch; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.inventory__dim { color: var(--color-text-maxcontrast); }
.inventory__ok { color: var(--color-success); }
.inventory__warn { color: var(--color-error); font-weight: 600; }
.inventory__does { margin-inline-start: auto; display: flex; gap: 6px; }

@media (max-width: 700px) {
	.inventory__list li { gap: 4px 10px; font-size: 0.95em; }
	.inventory__name { max-width: 100%; flex: 1 1 100%; }
	.inventory__does { margin-inline-start: 0; flex: 1 1 100%; }
}
</style>
