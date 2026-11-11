// Through GCNA's relay the session travels as ?session=, because Safari keeps no third-party cookie.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { PilotSession } from '../../phone_system/js/phone-session.mjs'

const TENANT = 'abc123.pbx.gcn-alliance.com'
const LOGIN = { method: 'login-password', username: '201', id: 'abcdefgh', hash: 'a'.repeat(64) }

function connect(origin, loginAnswer) {
	const requests = []
	const sockets = []
	const fetch = async (url, options) => {
		requests.push(String(url))
		const body = String(url).includes('/login') ? loginAnswer : { type: 'user', cuser: '201', cdomain: TENANT }
		return { ok: true, json: async () => body }
	}
	class WebSocket {
		static OPEN = 1
		constructor(url) {
			this.url = url
			this.readyState = 1
			sockets.push(this)
			setTimeout(() => {
				this.onopen()
				this.onmessage({ data: JSON.stringify({ action: 'sip-register', add: 'x', expires: 600 }) })
			})
		}
		send() {}
		close() {}
	}
	const session = new PilotSession({ fetch, WebSocket })
	return { done: session.connect({ origin, tenant: TENANT, extension: '201', login: { ...LOGIN } }), requests, sockets, session }
}

test('through the relay, the sign-in\'s session goes to the identity check and the socket', async () => {
	const { done, requests, sockets, session } = connect(`https://gcn-alliance.com/phone/${TENANT}/`, { success: true, session: 's3cr3t-0123456789' })
	await done
	assert.equal(new URL(requests[1]).searchParams.get('session'), 's3cr3t-0123456789')
	assert.equal(new URL(sockets[0].url).searchParams.get('session'), 's3cr3t-0123456789')
	session.disconnect()
})

test('a PBX reached directly keeps its cookie and never gets the session in a URL', async () => {
	const { done, requests, sockets, session } = connect(`https://${TENANT}/`, { success: true, session: 's3cr3t-0123456789' })
	await done
	assert.equal(new URL(requests[1]).searchParams.has('session'), false)
	assert.equal(new URL(sockets[0].url).searchParams.has('session'), false)
	session.disconnect()
})

test('a relay that returns no session falls back to the cookie', async () => {
	const { done, requests, sockets, session } = connect(`https://gcn-alliance.com/phone/${TENANT}/`, { success: true })
	await done
	assert.equal(new URL(requests[1]).searchParams.has('session'), false)
	assert.equal(new URL(sockets[0].url).searchParams.has('session'), false)
	session.disconnect()
})
