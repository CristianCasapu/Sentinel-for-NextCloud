<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="admin">
		<h2>{{ t('sentinel', 'Sentinel') }}</h2>
		<p class="admin__lead">
			{{ t('sentinel', 'Nextcloud defends itself well: it throttles password guessing, hashes passwords properly, sets the right headers and offers two-factor authentication. Sentinel repeats none of that. It watches what accumulates quietly instead — the account with no second factor, the link shared for an afternoon three years ago, the application password last used in February that would still work today.') }}
		</p>

		<p class="admin__lead">
			<a :href="pageUrl" class="admin__link">{{ t('sentinel', 'Open the Sentinel page') }}</a>
		</p>

		<section v-for="group in groups" :key="group.title" class="admin__group">
			<h3>{{ group.title }}</h3>
			<p class="admin__note">{{ group.note }}</p>

			<div v-for="field in group.fields" :key="field.key" class="admin__field">
				<template v-if="field.kind === 'bool'">
					<NcCheckboxRadioSwitch :model-value="Boolean(settings[field.key])"
						type="switch"
						@update:model-value="save(field.key, $event)">
						{{ field.label }}
					</NcCheckboxRadioSwitch>
					<p class="admin__hint">{{ field.hint }}</p>
				</template>

				<template v-else-if="field.kind === 'enum'">
					<label :for="'sentinel-' + field.key">{{ field.label }}</label>
					<select :id="'sentinel-' + field.key"
						:value="settings[field.key]"
						@change="save(field.key, ($event.target as HTMLSelectElement).value)">
						<option v-for="choice in field.choices" :key="choice.value" :value="choice.value">
							{{ choice.label }}
						</option>
					</select>
					<p class="admin__hint">{{ field.hint }}</p>
				</template>

				<template v-else>
					<label :for="'sentinel-' + field.key">{{ field.label }}</label>
					<input :id="'sentinel-' + field.key"
						:value="settings[field.key]"
						:type="field.kind === 'int' ? 'number' : 'text'"
						@change="save(field.key, ($event.target as HTMLInputElement).value)">
					<p class="admin__hint">{{ field.hint }}</p>
				</template>
			</div>
		</section>

		<section class="admin__group">
			<h3>{{ t('sentinel', 'The file baseline') }}</h3>
			<p class="admin__note">
				{{ t('sentinel', 'Nextcloud verifies its own files against the signatures it shipped with. On a patched installation that check fails for ever, and a warning that never goes away is one nobody reads. A baseline records what the files look like at a moment you choose.') }}
			</p>
			<p v-if="baseline.taken">
				{{ t('sentinel', '{n} files recorded, approved {when}.', { n: baseline.files, when: on(baseline.takenAt) }) }}
			</p>
			<p v-else>{{ t('sentinel', 'No baseline has been taken yet.') }}</p>
			<a :href="pageUrl + '#files'" class="admin__link">{{ t('sentinel', 'Manage it on the Sentinel page') }}</a>
		</section>
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { saveSetting, type BaselineStatus } from '../api'
import { on } from '../format'

const settings = ref<Record<string, unknown>>(loadState('sentinel', 'settings', {}))
const baseline = ref<BaselineStatus>(loadState('sentinel', 'baseline', { taken: false, files: 0, takenAt: 0 } as BaselineStatus))
const pageUrl = generateUrl('/apps/sentinel/')

interface Field {
	key: string
	label: string
	hint: string
	kind: 'bool' | 'int' | 'string' | 'enum'
	choices?: Array<{ value: string, label: string }>
}

const groups = computed<Array<{ title: string, note: string, fields: Field[] }>>(() => [
	{
		title: t('sentinel', 'Watching'),
		note: t('sentinel', 'Nothing here changes anything on the server. It decides what is looked at and who is told.'),
		fields: [
			{ key: 'watch_enabled', kind: 'bool', label: t('sentinel', 'Watch this installation'), hint: t('sentinel', 'Switching this off stops every listener and the background job. The pages still work.') },
			{ key: 'watch_interval', kind: 'int', label: t('sentinel', 'How often to look, in seconds'), hint: t('sentinel', 'The background job. Walking the files happens at most once an hour regardless.') },
			{ key: 'notify_admins', kind: 'bool', label: t('sentinel', 'Tell the administrators'), hint: t('sentinel', 'Findings arrive in the bell menu of every administrator.') },
			{
				key: 'notify_from',
				kind: 'enum',
				label: t('sentinel', 'Interrupt them from'),
				hint: t('sentinel', 'Anything below this is written down but not announced. Announcing everything is how people learn to ignore announcements.'),
				choices: [
					{ value: 'notice', label: t('sentinel', 'Everything') },
					{ value: 'warning', label: t('sentinel', 'Worth a look and above') },
					{ value: 'alarm', label: t('sentinel', 'Only what needs attention') },
				],
			},
			{ key: 'retain_days', kind: 'int', label: t('sentinel', 'Keep the journal for, in days'), hint: t('sentinel', 'Older entries are deleted. A record nobody will ever read is only a liability.') },
		],
	},
	{
		title: t('sentinel', 'What counts as too old'),
		note: t('sentinel', 'These are judgements about this particular server. A machine used by one person and one used by forty do not have the same answers.'),
		fields: [
			{ key: 'stale_token_days', kind: 'int', label: t('sentinel', 'An application password is stale after, in days'), hint: t('sentinel', 'Unused for this long and it is probably a device nobody has any more.') },
			{ key: 'idle_session_days', kind: 'int', label: t('sentinel', 'A session is idle after, in days'), hint: t('sentinel', 'A browser that has not come back in this long.') },
			{ key: 'dormant_admin_days', kind: 'int', label: t('sentinel', 'An administrator is dormant after, in days'), hint: t('sentinel', 'Rights nobody is using are rights worth taking away.') },
			{ key: 'old_link_days', kind: 'int', label: t('sentinel', 'A link with no expiry is old after, in days'), hint: t('sentinel', 'Long enough that whoever made it has forgotten it exists.') },
		],
	},
	{
		title: t('sentinel', 'The files'),
		note: t('sentinel', 'Which parts of the installation the baseline covers, and how thoroughly.'),
		fields: [
			{ key: 'baseline_scope', kind: 'string', label: t('sentinel', 'Cover'), hint: t('sentinel', 'Comma separated: core, apps, config, themes, 3rdparty. The users\' own files are never included.') },
			{ key: 'baseline_extensions', kind: 'string', label: t('sentinel', 'File kinds'), hint: t('sentinel', 'Comma separated. The interesting ones are the ones that can execute, and .htaccess.') },
			{ key: 'baseline_exclude', kind: 'string', label: t('sentinel', 'Skip anything containing'), hint: t('sentinel', 'Comma separated fragments of a path.') },
			{ key: 'baseline_max_bytes', kind: 'int', label: t('sentinel', 'Skip files larger than, in bytes'), hint: t('sentinel', 'Hashing a very large file costs more than knowing about it is worth.') },
			{
				key: 'baseline_digest',
				kind: 'enum',
				label: t('sentinel', 'Hash with'),
				hint: t('sentinel', 'xxh128 is far faster and is what you want here: this is detecting change, not resisting an adversary who can choose the file contents.'),
				choices: [
					{ value: 'xxh128', label: 'xxh128' },
					{ value: 'sha256', label: 'sha256' },
				],
			},
		],
	},
	{
		title: t('sentinel', 'Where people sign in from'),
		note: t('sentinel', 'Recorded as the network, not the address: a home connection changes address regularly and a phone changes it constantly, so exact addresses would make every ordinary Tuesday look like an intrusion.'),
		fields: [
			{ key: 'track_places', kind: 'bool', label: t('sentinel', 'Remember the networks each account uses'), hint: t('sentinel', 'Only the network and when it was last seen. No addresses of visitors, no locations.') },
			{ key: 'place_alert', kind: 'bool', label: t('sentinel', 'Say something when an account appears somewhere new'), hint: t('sentinel', 'An administrator with no second factor arriving from a new network is the shape of a compromise.') },
			{ key: 'place_granularity', kind: 'int', label: t('sentinel', 'How precise a network is, in bits'), hint: t('sentinel', '24 treats a whole neighbourhood of addresses as one place. Higher is stricter and noisier.') },
			{ key: 'ignore_uids', kind: 'string', label: t('sentinel', 'Ignore these accounts'), hint: t('sentinel', 'Comma separated. For service accounts that sign in from everywhere by design.') },
		],
	},
])

const save = async (key: string, value: unknown) => {
	const previous = settings.value[key]
	settings.value = { ...settings.value, [key]: value }
	try {
		const stored = await saveSetting(key, value)
		// The server is allowed to say no — a number out of range comes back
		// clamped — so the form shows what was actually kept.
		settings.value = { ...settings.value, [key]: stored.value }
	} catch (error) {
		settings.value = { ...settings.value, [key]: previous }
		showError(t('sentinel', 'Could not save that setting'))
	}
}
</script>

<style scoped>
.admin { max-width: 80ch; }
.admin h2 { margin-bottom: 8px; }
.admin__lead { color: var(--color-text-maxcontrast); margin-bottom: 12px; }
.admin__link { text-decoration: underline; }
.admin__group { margin-top: 28px; }
.admin__group h3 { margin-bottom: 4px; font-weight: 600; }
.admin__note { color: var(--color-text-maxcontrast); margin-bottom: 12px; }
.admin__field { margin-bottom: 16px; }
.admin__field label { display: block; margin-bottom: 4px; }
.admin__field input[type="text"], .admin__field input[type="number"], .admin__field select { width: 100%; max-width: 420px; }
.admin__hint { color: var(--color-text-maxcontrast); font-size: 0.9em; margin-top: 2px; }

@media (max-width: 600px) {
	.admin__field input[type="text"], .admin__field input[type="number"], .admin__field select { max-width: 100%; }
}
</style>
