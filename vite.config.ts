import { createAppConfig } from '@nextcloud/vite-config'

export default createAppConfig({
	main: 'src/main.ts',
	admin: 'src/admin.ts',
}, {
	inlineCSS: { relativeCSSInjection: true },
	minify: true,
	emptyOutputDirectory: { additionalDirectories: [] },
})
