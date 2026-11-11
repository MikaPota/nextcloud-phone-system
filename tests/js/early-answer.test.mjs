// The PBX's early answer (183) must work where the browser has no "pranswer" (Firefox).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { PilotPhone } from '../../phone_system/js/phone-calls.mjs'

class Peer {
	constructor(pranswer) { this.pranswer = pranswer; this.signalingState = 'stable'; this.remoteDescription = null; this.applied = [] }
	addTrack() {}
	async createOffer() { return { type: 'offer', sdp: 'v=0 offer' } }
	async setLocalDescription() { this.signalingState = 'have-local-offer' }
	async addIceCandidate() {}
	async setRemoteDescription(description) {
		if (description.type === 'pranswer' && !this.pranswer) throw Object.assign(new Error('pranswer not yet implemented'), { name: 'NotSupportedError' })
		if (this.signalingState === 'stable') throw Object.assign(new Error('no offer'), { name: 'InvalidStateError' })
		this.applied.push(description.type)
		this.remoteDescription = description
		this.signalingState = description.type === 'pranswer' ? 'have-remote-pranswer' : 'stable'
	}
	close() {}
}

async function call(pranswer) {
	const session = new EventTarget()
	session.state = 'ready'
	session.sent = []
	session.sendCall = (message) => session.sent.push(message)
	const peer = new Peer(pranswer)
	const track = { stop() {} }
	const phone = new PilotPhone({ session, createPeer: () => peer, createId: () => 'c1@app', getMedia: async () => ({ getAudioTracks: () => [track], getTracks: () => [track] }) })
	const failures = []
	phone.addEventListener('failure', (event) => failures.push(event.detail.message))
	await phone.dial('202')
	const signal = async (detail) => { session.dispatchEvent(new CustomEvent('signal', { detail })); await new Promise((r) => setTimeout(r, 10)) }
	await signal({ callid: 'c1@app', code: 183, sdp: 'v=0 early', cseq: 1 })
	await signal({ callid: 'c1@app', code: 200, sdp: 'v=0 final', cseq: 1 })
	return { phone, peer, failures, session }
}

for (const [engine, pranswer, applied] of [['Chrome', true, ['pranswer', 'answer']], ['Firefox', false, ['answer']]]) {
	test(`${engine}: an early answer, then 200 OK, connects the call`, async () => {
		const { phone, peer, failures, session } = await call(pranswer)
		assert.deepEqual(failures, [])
		assert.deepEqual(peer.applied, applied)
		assert.equal(phone.state, 'active')
		assert.ok(session.sent.some((m) => m.action === 'sip-ack'))
		phone.hangup()
	})
}
