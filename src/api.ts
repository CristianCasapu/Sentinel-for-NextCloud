import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Everything the page can ask the server, in one place.
 *
 * All of it is administrator-only on the far side, so a failure here is a
 * genuine failure rather than a permission the page should route around.
 */

const base = (path: string) => generateOcsUrl('apps/sentinel/api/v1/' + path)

export type State = 'good' | 'note' | 'warn' | 'bad'

export interface Finding {
	id: string
	title: string
	state: State
	summary: string
	why: string
	fix: string
	detail: Record<string, unknown>
}

export interface PostureReport {
	findings: Finding[]
	counts: Record<State, number>
	checkedAt: number
}

export interface Difference {
	path: string
	state: 'changed' | 'added' | 'removed'
	size: number
	wasSize?: number
	digest: string
	modified: number
}

export interface BaselineStatus {
	taken: boolean
	files: number
	takenAt: number
	takenBy: string
	comparedAt: number
	changed: number
	added: number
	removed: number
	walked: number
	took: number
	differences: Difference[]
	truncated: boolean
}

export interface LinkRow {
	id: number
	type: 'link' | 'mail'
	owner: string
	createdBy: string
	target: string
	itemType: string
	label: string
	created: number
	age: number
	expires: number
	hasPassword: boolean
	canDownload: boolean
	exposed: boolean
	stale: boolean
	views: number
	downloads: number
	failures: number
	networks: number
	lastUsed: number
}

export interface TokenRow {
	id: number
	uid: string
	name: string
	kind: 'application' | 'session'
	lastUsed: number
	lastChecked: number
	passwordStale: boolean
	cold: boolean
}

export interface AccountRow {
	uid: string
	name: string
	enabled: boolean
	admin: boolean
	lastSeen: number
	twoFactor: boolean
	backend: string
	places: number
}

export interface BusyRow {
	uid: string
	kind: string
	count: number
}

export interface InventoryReport {
	links: { links: LinkRow[], total: number, openForever: number }
	tokens: { tokens: TokenRow[], total: number, cold: number }
	accounts: { accounts: AccountRow[], total: number, withoutTwoFactor: number }
	busy: BusyRow[]
}

export interface Watcher {
	id: string
	name: string
	on: boolean
	what: string
}

export interface OverviewReport {
	state: State
	counts: Record<State, number>
	headline: string
	checkedAt: number
	numbers: {
		accounts: number
		withoutTwoFactor: number
		administrators: number
		links: number
		linkOpens: number
		linkBusiestNetworks: number
		tokens: number
		coldTokens: number
		baselineFiles: number
		baselineChanged: number
		baselineComparedAt: number
		exposureServed: number
		exposureCheckedAt: number
		certificateDays: number
		certificateKnown: boolean
	}
	watchers: Watcher[]
	events: {
		day: Record<string, number>
		week: Record<string, number>
		unseen: number
		recent: EventRow[]
	}
	delivery: {
		enabled: boolean
		from: string
		digest: boolean
		digestHour: number
		recipients: string[]
		configured: boolean
		inApp: boolean
		inAppFrom: string
	}
	busy: BusyRow[]
}

export interface ExposureReport {
	reachable: boolean
	base: string
	checked: number
	served: Array<{ path: string, code: number }>
	probedAt: number
	never?: boolean
}

export interface EventRow {
	id: number
	kind: string
	severity: 'notice' | 'warning' | 'alarm'
	subject: string | null
	actor: string | null
	address: string | null
	summary: string
	detail: Record<string, unknown> | null
	occurred: number
	seen: boolean
}

const unwrap = <T>(response: { data: { ocs: { data: T } } }): T => response.data.ocs.data

export const overview = async (): Promise<OverviewReport> =>
	unwrap(await axios.get(base('overview')))

export const testMail = async () =>
	unwrap<{ sent: boolean, recipients: string[] }>(await axios.post(base('mail/test')))

export const posture = async (): Promise<PostureReport> =>
	unwrap(await axios.get(base('posture')))

export const events = async (limit = 100, offset = 0, severity = '') =>
	unwrap<{ events: EventRow[], unseen: number, total: number }>(
		await axios.get(base('events'), { params: { limit, offset, severity } }),
	)

export const markEventsSeen = async () => unwrap(await axios.post(base('events/seen')))

export const inventory = async (): Promise<InventoryReport> =>
	unwrap(await axios.get(base('inventory')))

export const exposure = async (): Promise<ExposureReport> =>
	unwrap(await axios.get(base('exposure')))

export const probe = async (): Promise<ExposureReport> =>
	unwrap(await axios.post(base('exposure')))

export const baselineStatus = async (): Promise<BaselineStatus> =>
	unwrap(await axios.get(base('baseline')))

export const baselineCompare = async (): Promise<BaselineStatus> =>
	unwrap(await axios.post(base('baseline/compare')))

export const baselineTake = async (): Promise<BaselineStatus> =>
	unwrap(await axios.post(base('baseline/take')))

export const baselineAcknowledge = async (paths: string[], note: string): Promise<BaselineStatus> =>
	unwrap(await axios.post(base('baseline/acknowledge'), { paths, note }))

export const baselineForget = async (): Promise<BaselineStatus> =>
	unwrap(await axios.delete(base('baseline')))

export const expireLink = async (id: number, days: number) =>
	unwrap(await axios.post(base('links/' + id + '/expire'), { days }))

export const closeLink = async (id: number) =>
	unwrap(await axios.delete(base('links/' + id)))

export const revokeToken = async (uid: string, id: number) =>
	unwrap(await axios.delete(base('tokens/' + id), { params: { uid } }))

export const saveSetting = async (key: string, value: unknown) =>
	unwrap<{ key: string, value: unknown }>(await axios.put(base('settings/' + key), { value }))

export const resetSetting = async (key: string) =>
	unwrap<{ key: string, value: unknown }>(await axios.delete(base('settings/' + key)))
