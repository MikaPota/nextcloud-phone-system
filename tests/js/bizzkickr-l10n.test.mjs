// Every text the BIZZKICKR app shows has a German translation, in both catalogs Nextcloud loads.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

const app = new URL('../../bizzkickr/', import.meta.url).pathname
const files = (dir) => readdirSync(join(app, dir), { recursive: true }).map((name) => join(app, dir, name))
const texts = new Set()
for (const file of [...files('lib'), ...files('templates')].filter((f) => f.endsWith('.php'))) {
	for (const [, text] of readFileSync(file, 'utf8').matchAll(/->t\('((?:[^'\\]|\\.)*)'/g)) texts.add(text.replace(/\\'/g, "'"))
}
for (const file of files('js')) {
	const source = readFileSync(file, 'utf8')
	for (const [, text] of source.matchAll(/\btr\('((?:[^'\\]|\\.)*)'/g)) texts.add(text)
	for (const [, text] of source.matchAll(/\['\w+', '([^']+)'\]/g)) texts.add(text)
	for (const [, text] of source.matchAll(/field\('\w+', '([^']+)'/g)) texts.add(text)
}

test('every shown text is translated into German', () => {
	assert.ok(texts.size > 80)
	for (const catalog of ['de', 'de_DE']) {
		const translations = JSON.parse(readFileSync(join(app, `l10n/${catalog}.json`), 'utf8')).translations
		const missing = [...texts].filter((text) => !translations[text])
		assert.deepEqual(missing, [], `${catalog}.json`)
		const script = readFileSync(join(app, `l10n/${catalog}.js`), 'utf8')
		assert.ok(script.startsWith('OC.L10N.register(\n    "bizzkickr",'))
		for (const text of texts) assert.ok(script.includes(JSON.stringify(text)), `${catalog}.js: ${text}`)
	}
})
