// SPDX-FileCopyrightText: 2026 Cristian Casapu
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createApp } from 'vue'
import Admin from './views/Admin.vue'

const element = document.getElementById('sentinel-admin')
if (element) {
	createApp(Admin).mount(element)
}
