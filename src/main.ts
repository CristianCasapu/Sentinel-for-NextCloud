// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp } from 'vue'
import App from './views/App.vue'

const element = document.getElementById('sentinel')
if (element) {
	createApp(App).mount(element)
}
