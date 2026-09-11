<!--
  - SPDX-FileCopyrightText: 2026 Cristian Casapu
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="admin">
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
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { showError } from '@nextcloud/dialogs'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { saveSetting } from '../api'

const settings = ref<Record<string, unknown>>(loadState('sentinel', 'settings', {}))

interface Field {
	key: string
	label: string
	hint: string
	kind: 'bool' | 'int' | 'string' | 'enum'
	choices?: Array<{ value: string, label: string }>
}

const groups = computed<Array<{ title: string, note: string, fields: Field[] }>>(() => [
	{
		title: t('sentinel', 'Being told'),
		note: t('sentinel', 'The bell inside Nextcloud is the right place for most things and the wrong place for the one that matters: an administrator who is not signed in does not have a bell. Whatever happened at three in the morning would be eight hours old before anybody opened a browser.'),
		fields: [
			{ key: 'notify_admins', kind: 'bool', label: t('sentinel', 'Tell the administrators in the bell menu'), hint: t('sentinel', 'Findings appear in the notifications of every administrator.') },
			{
				key: 'notify_from',
				kind: 'enum',
				label: t('sentinel', 'In the bell, from'),
				hint: t('sentinel', 'Anything below this is written down but not announced. Announcing everything is how people learn to ignore announcements.'),
				choices: [
					{ value: 'notice', label: t('sentinel', 'Everything') },
					{ value: 'warning', label: t('sentinel', 'Worth a look and above') },
					{ value: 'alarm', label: t('sentinel', 'Only what needs attention') },
				],
			},
			{ key: 'email_enabled', kind: 'bool', label: t('sentinel', 'Also send email'), hint: t('sentinel', 'To every administrator who has an address, and to anything added below.') },
			{
				key: 'email_from',
				kind: 'enum',
				label: t('sentinel', 'By mail, from'),
				hint: t('sentinel', 'Alarms only, by default: an inbox that fills up with routine is an inbox where the one message that mattered scrolls off the first screen.'),
				choices: [
					{ value: 'notice', label: t('sentinel', 'Everything') },
					{ value: 'warning', label: t('sentinel', 'Worth a look and above') },
					{ value: 'alarm', label: t('sentinel', 'Only what needs attention') },
				],
			},
			{ key: 'email_extra', kind: 'string', label: t('sentinel', 'And to these addresses'), hint: t('sentinel', 'Comma separated. Usually the address that reaches a phone, rather than a mailbox on this same server — which may be the thing that is down.') },
			{ key: 'digest_enabled', kind: 'bool', label: t('sentinel', 'Send a summary once a day'), hint: t('sentinel', 'Worth having even when there is nothing to say: the morning it stops arriving is the morning to go and look at why.') },
			{ key: 'digest_hour', kind: 'int', label: t('sentinel', 'At which hour'), hint: t('sentinel', 'Server time, 0 to 23.') },
		],
	},
	{
		title: t('sentinel', 'Watching'),
		note: t('sentinel', 'Nothing here changes anything on the server. It decides what is looked at and who is told.'),
		fields: [
			{ key: 'watch_enabled', kind: 'bool', label: t('sentinel', 'Watch this installation'), hint: t('sentinel', 'Switching this off stops every listener and the background job. The pages still work.') },
			{ key: 'watch_interval', kind: 'int', label: t('sentinel', 'How often to look, in seconds'), hint: t('sentinel', 'The background job. Walking the files happens at most once an hour regardless.') },
			{ key: 'retain_days', kind: 'int', label: t('sentinel', 'Keep the journal for, in days'), hint: t('sentinel', 'Older entries are deleted. A record nobody will ever read is only a liability.') },
		],
	},
	{
		title: t('sentinel', 'Public links'),
		note: t('sentinel', 'A link without a password is a decision, not an oversight, so nothing here treats one as a fault. What is watched is how a link is used compared with how that same link is normally used.'),
		fields: [
			{ key: 'watch_links', kind: 'bool', label: t('sentinel', 'Watch how links are used'), hint: t('sentinel', 'Counts opens, downloads and refusals per link per day. No addresses of visitors are stored, only how many networks.') },
			{ key: 'link_crowd', kind: 'int', label: t('sentinel', 'Say something above this many networks in a day'), hint: t('sentinel', 'Below this, nothing is ever said, however busy the link.') },
			{ key: 'link_surge', kind: 'int', label: t('sentinel', 'And only if it is this many times its own record'), hint: t('sentinel', 'A link that has always been busy is allowed to be busy. Only one doing several times what it has ever done before is news.') },
			{ key: 'link_failures', kind: 'int', label: t('sentinel', 'Refused password attempts in a day before saying so'), hint: t('sentinel', 'Somebody guessing at a link that does have a password.') },
			{ key: 'judge_open_links', kind: 'bool', label: t('sentinel', 'Also treat a link with no password as a finding'), hint: t('sentinel', 'Off, because it is an opinion rather than a finding. On for installations where every link really is meant to have a password.') },
		],
	},
	{
		title: t('sentinel', 'Files changing very fast'),
		note: t('sentinel', 'A sync client on a machine that catches ransomware uploads every encrypted file over the original, and every check that asks about passwords says the server is fine. The only visible thing is the rate.'),
		fields: [
			{ key: 'watch_churn', kind: 'bool', label: t('sentinel', 'Watch how fast files change'), hint: t('sentinel', 'One counter per account, in the cache. Files the server writes for itself — previews, avatars — are not counted.') },
			{ key: 'churn_window', kind: 'int', label: t('sentinel', 'Over how long, in seconds'), hint: t('sentinel', 'The window the counting covers.') },
			{ key: 'churn_writes', kind: 'int', label: t('sentinel', 'Files rewritten before acting'), hint: t('sentinel', 'No person rewrites hundreds of files by hand in a few minutes.') },
			{ key: 'churn_deletes', kind: 'int', label: t('sentinel', 'Files deleted before acting'), hint: t('sentinel', 'Deleting is the more frightening of the two, so the number is lower.') },
			{ key: 'churn_renames', kind: 'int', label: t('sentinel', 'Files renamed to one new extension before acting'), hint: t('sentinel', 'Ransomware renames what it encrypts, and renames it all to the same thing.') },
			{ key: 'churn_require_evidence', kind: 'bool', label: t('sentinel', 'Require evidence, not just speed'), hint: t('sentinel', 'On, and it should stay on. Five hundred files in five minutes is a phone finishing its first backup as often as it is an encryptor. What decides is the files themselves: no legitimate client rewrites a .jpg so that it stops being a JPEG.') },
			{ key: 'churn_sample_every', kind: 'int', label: t('sentinel', 'Once suspicious, open one file in every'), hint: t('sentinel', 'Nothing is read at all until the rate is halfway to the threshold above.') },
			{ key: 'churn_mismatch_floor', kind: 'int', label: t('sentinel', 'Wrong files before that counts'), hint: t('sentinel', 'A handful of genuinely mislabelled files is not an attack.') },
			{ key: 'churn_mismatch_ratio', kind: 'int', label: t('sentinel', 'And what share of those checked, as a percentage'), hint: t('sentinel', 'A run of wrong files one after another counts on its own, however the whole window averages out.') },
			{
				key: 'churn_response',
				kind: 'enum',
				label: t('sentinel', 'And then'),
				hint: t('sentinel', 'Disabling the account also ends its sessions, because a sync client holds a token and does not care that its owner was marked disabled. It will also stop somebody restoring a large backup into their folder — that is the price of a switch that acts without asking.'),
				choices: [
					{ value: 'tell', label: t('sentinel', 'Raise an alarm') },
					{ value: 'lock', label: t('sentinel', 'Disable the account and end its sessions') },
				],
			},
		],
	},
	{
		title: t('sentinel', 'Which program is writing'),
		note: t('sentinel', 'Nextcloud sees files changing and knows whose account they belong to. It cannot see which process changed them — and a sync client uploading what ransomware did on somebody\'s laptop is a different emergency from something on this server writing into the data directory. A small daemon outside answers that; it ships with this app under edr/.'),
		fields: [
			{ key: 'edr_expected', kind: 'bool', label: t('sentinel', 'Expect the process watcher to be installed'), hint: t('sentinel', 'With this on, its absence or silence is reported. Turn it off if you have decided not to run it.') },
			{ key: 'edr_spool', kind: 'string', label: t('sentinel', 'Where it leaves its reports'), hint: t('sentinel', 'A directory it writes and Nextcloud only reads. No socket, no port, no credential on disk.') },
		],
	},
	{
		title: t('sentinel', 'A scanner to ask'),
		note: t('sentinel', 'Not a scanner for everything that arrives — that is a different job with a different cost, and Nextcloud has an app for it. This is asked about the handful of files that already look wrong for another reason.'),
		fields: [
			{ key: 'clam_enabled', kind: 'bool', label: t('sentinel', 'Ask ClamAV when something looks wrong'), hint: t('sentinel', 'Through clamd\'s socket. Not clamscan, which reloads the whole signature database for every single file.') },
			{ key: 'clam_socket', kind: 'string', label: t('sentinel', 'Its socket'), hint: t('sentinel', 'A path for a unix socket, or host:port.') },
			{ key: 'clam_max_bytes', kind: 'int', label: t('sentinel', 'Scan at most, in bytes'), hint: t('sentinel', 'Past this, only the beginning of a file is sent.') },
			{ key: 'clam_sample', kind: 'int', label: t('sentinel', 'How many files to scan per suspicion'), hint: t('sentinel', 'Enough to be sure, few enough to be quick.') },
		],
	},
	{
		title: t('sentinel', 'Asking the server what it serves'),
		note: t('sentinel', 'Every other check here reads the code. This one is an ordinary anonymous visitor asking the web server for the files that must never be handed out — which is the only way to find out what a changed rewrite rule or a copied virtual host actually does.'),
		fields: [
			{ key: 'probe_enabled', kind: 'bool', label: t('sentinel', 'Ask this server for files it should refuse'), hint: t('sentinel', 'A few dozen requests to its own address, a few times a day.') },
			{ key: 'probe_extra', kind: 'string', label: t('sentinel', 'Also ask for'), hint: t('sentinel', 'Comma separated paths, for anything particular to this installation.') },
			{ key: 'certificate_warn_days', kind: 'int', label: t('sentinel', 'Warn this many days before the certificate expires'), hint: t('sentinel', 'Renewal is automatic until the day it is not, and nothing tells you it has stopped.') },
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
.admin__group { margin-top: 28px; }
.admin__group:first-child { margin-top: 0; }
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
