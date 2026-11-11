// The app's phone client is a copy of shared/ (kept in sync with the Odoo app); they must not drift.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const read = (path) => readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
// The copy adds a provenance comment and `export`; nothing else may differ.
const body = (source) => source.split('\n').filter((line) => !line.startsWith('// Copied from')).map((line) => line.replace(/^export /, '')).join('\n').trim()

for (const name of ['phone-session', 'phone-calls']) {
	test(`${name}: the app's copy matches shared/`, () => {
		assert.equal(body(read(`phone_system/js/${name}.mjs`)), body(read(`shared/${name}.js`)))
	})
}
