// The phone window's failures reach the user translated and actionable (REQ-005).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { explain, TEXTS } from '../../phone_system/js/errors.mjs'

const t = (text) => text
const err = (message, extra = {}) => Object.assign(new Error(message), extra)

test('each failure of the shared client has its own message', () => {
	const cases = [
		[err('x', { name: 'NotAllowedError' }), 'The microphone is blocked. Allow it for this site and try again.'],
		[err('x', { name: 'NotFoundError' }), 'No microphone was found. Connect one and try again.'],
		[err('The phone system could not be reached.', { step: 'unreachable' }), 'The phone system could not be reached. Check the connection and try again.'],
		[err('Individual login was rejected.', { step: 'refused' }), "The phone system refused your extension's login. Ask an administrator to check your extension."],
		[err('…the browser kept no session.', { step: 'cookies' }), "Your browser blocked the phone system's sign-in cookie. Allow cookies for gcn-alliance.com and open the phone again."],
		[err('…another identity.', { step: 'identity' }), 'The phone system signed in another extension. Close the phone window and open it again.'],
		[err('Invalid signaling message.', { step: 'signaling' }), 'The connection to the phone system was interrupted. Close the phone window and open it again.'],
		[err('Call setup failed; check microphone permission and signaling.'), 'The call could not start. Allow the microphone for this site and try again.'],
		[err('Answer failed; check microphone permission and signaling.'), 'The call could not be answered. Allow the microphone for this site and try again.'],
		[err('A second incoming call was declined; the phone supports one call.'), 'Another call came in and was declined: the phone takes one call at a time.'],
		[err('Only authorized internal extensions are allowed.'), 'This number cannot be called from here.'],
		[err('A ready, idle phone is required.'), 'The phone is busy or not ready yet.'],
		[err('Registration renewal timed out.'), 'The phone lost its connection. Close the phone window and open it again.'],
		[err('Hangup delivery failed.'), 'The connection to the phone system was interrupted.'],
		[err('Something nobody expected'), 'Something went wrong with the call. Try again.'],
	]
	for (const [error, expected] of cases) assert.equal(explain(error, t), expected, error.message)
})

test("the server's own message is shown as it came, already translated", () => {
	assert.equal(explain(err('Ihnen ist keine Durchwahl zugewiesen.', { step: 'grant' }), t), 'Ihnen ist keine Durchwahl zugewiesen.')
})

test('a cancelled call shows nothing', () => {
	assert.equal(explain(err('Call was cancelled.'), t), null)
	assert.equal(explain(err('Connection was cancelled.'), t), null)
})

test('every message is translated into German, formal and informal locale', () => {
	for (const lang of ['de', 'de_DE']) {
		const { translations } = JSON.parse(readFileSync(new URL(`../../phone_system/l10n/${lang}.json`, import.meta.url), 'utf8'))
		const js = readFileSync(new URL(`../../phone_system/l10n/${lang}.js`, import.meta.url), 'utf8')
		for (const text of TEXTS) {
			assert.ok(translations[text], `${lang}.json lacks: ${text}`)
			assert.ok(js.includes(JSON.stringify(text)), `${lang}.js lacks: ${text}`)
		}
	}
})
