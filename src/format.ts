import { translate as t } from '@nextcloud/l10n'

/** How long ago, said the way a person would say it. */
export const ago = (timestamp: number): string => {
	if (!timestamp) {
		return t('sentinel', 'never')
	}
	const seconds = Math.max(0, Math.floor(Date.now() / 1000) - timestamp)
	if (seconds < 90) {
		return t('sentinel', 'just now')
	}
	const minutes = Math.floor(seconds / 60)
	if (minutes < 60) {
		return t('sentinel', '{n} minutes ago', { n: minutes })
	}
	const hours = Math.floor(minutes / 60)
	if (hours < 24) {
		return t('sentinel', '{n} hours ago', { n: hours })
	}
	const days = Math.floor(hours / 24)
	if (days < 31) {
		return t('sentinel', '{n} days ago', { n: days })
	}
	const months = Math.floor(days / 30)
	if (months < 24) {
		return t('sentinel', '{n} months ago', { n: months })
	}
	return t('sentinel', '{n} years ago', { n: Math.floor(days / 365) })
}

export const on = (timestamp: number): string => {
	if (!timestamp) {
		return '—'
	}
	return new Date(timestamp * 1000).toLocaleString()
}

export const bytes = (size: number): string => {
	if (size < 1024) {
		return size + ' B'
	}
	const units = ['KB', 'MB', 'GB']
	let value = size / 1024
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}
	return value.toFixed(value < 10 ? 1 : 0) + ' ' + units[unit]
}
