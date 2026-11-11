/**
 * The phone window. It signs in to the user's extension with a one-time login from
 * the Nextcloud server, stays registered while the main window changes pages, takes
 * numbers to dial from launcher.js over BroadcastChannel, names callers and reports
 * each finished call to the call history.
 */
import { PilotSession } from './phone-session.mjs'
import { PilotPhone } from './phone-calls.mjs'
import { explain } from './errors.mjs'

const $ = (id) => document.getElementById(id)
const api = (path) => OC.generateUrl(`/apps/phone_system${path}`)
const headers = { requesttoken: OC.requestToken, 'Content-Type': 'application/json' }
const channel = new BroadcastChannel('phone_system')
const state = { session: 'starting', call: 'idle', events: [], recorded: [] }
window.__phone = state

const session = new PilotSession()
let phone = null
let pending = null
let current = null

const status = (text) => { $('phone-status').textContent = text }
const tr = (text) => t('phone_system', text)
/** A failure in the user's language; the English original stays in state.events for support. */
const fail = (error) => { const text = explain(error, tr); if (text) status(text) }
const LABELS = {
	answered: t('phone_system', 'Answered'), missed: t('phone_system', 'Missed'),
	unanswered: t('phone_system', 'Not answered'), failed: t('phone_system', 'Failed'),
}
const STATES = {
	preparing: t('phone_system', 'Calling…'), ringing: t('phone_system', 'Ringing…'),
	answering: t('phone_system', 'Connecting…'), active: t('phone_system', 'In call'),
}

async function nameOf(remote) {
	if (!remote) return null
	const response = await fetch(`${api('/lookup')}?remote=${encodeURIComponent(remote)}`, { headers })
	return response.ok ? (await response.json()).name : null
}

async function loadHistory() {
	const response = await fetch(api('/calls'), { headers })
	if (!response.ok) return
	const calls = await response.json()
	$('phone-history').replaceChildren(...calls.slice(0, 20).map((call) => {
		const item = document.createElement('li')
		const who = Object.assign(document.createElement('a'), { href: '#', textContent: `${call.direction === 'in' ? '↙' : '↗'} ${call.remoteName || call.remote}` })
		who.addEventListener('click', (event) => { event.preventDefault(); dial(call.remote) })
		const when = document.createElement('span')
		when.textContent = `${LABELS[call.outcome]} · ${new Date(call.startedAt * 1000).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}`
		if (call.outcome === 'missed') when.className = 'missed'
		item.append(who, when)
		return item
	}))
}

async function record(call) {
	const now = Math.floor(Date.now() / 1000)
	const outcome = call.answeredAt ? 'answered' : call.failed ? 'failed' : call.direction === 'incoming' ? 'missed' : 'unanswered'
	const body = {
		direction: call.direction === 'incoming' ? 'in' : 'out',
		remote: (call.remote || '').replace(/[^0-9+*#]/g, '') || 'unknown',
		outcome, startedAt: call.startedAt, duration: call.answeredAt ? now - call.answeredAt : 0,
	}
	if (body.remote === 'unknown') return
	const response = await fetch(api('/calls'), { method: 'POST', headers, body: JSON.stringify(body) })
	state.recorded.push(`${body.direction} ${body.remote} ${outcome} ${response.status}`)
	loadHistory()
}

async function show(detail) {
	state.call = detail.state
	state.events.push(`${detail.direction ?? ''} ${detail.state}`)
	if (detail.state !== 'idle' && current?.id !== detail.id) {
		current = { id: detail.id, direction: detail.direction, remote: detail.remote ?? $('phone-number').value,
			startedAt: Math.floor(Date.now() / 1000), answeredAt: null, failed: false }
		$('phone-remote').textContent = current.remote
		nameOf(current.remote).then((name) => { if (name && current?.id === detail.id) $('phone-remote').textContent = name })
	}
	if (current && detail.remote) current.remote = detail.remote
	if (current && detail.state === 'active' && !current.answeredAt) current.answeredAt = Math.floor(Date.now() / 1000)
	if (detail.state === 'idle') {
		if (current) record(current)
		current = null
		$('phone-remote').textContent = ''
	}
	$('phone-answer').hidden = !(detail.direction === 'incoming' && detail.state === 'ringing')
	$('phone-hangup').hidden = detail.state === 'idle'
	$('phone-mute').hidden = detail.state !== 'active'
	status(STATES[detail.state] ?? t('phone_system', 'Ready'))
}

async function connect() {
	status(t('phone_system', 'Connecting…'))
	const response = await fetch(api('/session'), { method: 'POST', headers })
	const grant = await response.json()
	if (!response.ok) throw Object.assign(new Error(grant.error), { step: 'grant' })
	session.addEventListener('state', ({ detail }) => { state.session = detail })
	await session.connect(grant)
	phone = new PilotPhone({ session, destinations: grant.directory })
	phone.setExternalCalling(true)
	phone.addEventListener('call', ({ detail }) => show(detail))
	phone.addEventListener('remote-stream', ({ detail }) => { $('phone-audio').srcObject = detail })
	phone.addEventListener('failure', ({ detail }) => {
		state.events.push(`failure ${detail.message}`)
		if (current) current.failed = true
		fail(detail)
	})
	status(t('phone_system', 'Ready – extension {extension}', { extension: grant.extension }))
	if (pending) dial(pending)
}

function dial(number) {
	$('phone-number').value = number
	if (!phone) { pending = number; return }
	pending = null
	phone.dial(number).catch((error) => { state.events.push(`dial ${error.message}`); fail(error) })
}

channel.addEventListener('message', ({ data }) => {
	if (data?.type === 'ping') channel.postMessage({ type: 'pong' })
	if (data?.type === 'dial' && typeof data.number === 'string') dial(data.number)
})

$('phone-form').addEventListener('submit', (event) => { event.preventDefault(); dial($('phone-number').value.trim()) })
$('phone-answer').addEventListener('click', () => phone.answer().catch((error) => { state.events.push(`answer ${error.message}`); fail(error) }))
$('phone-hangup').addEventListener('click', () => phone.hangup())
let muted = false
$('phone-mute').addEventListener('click', () => {
	muted = !muted
	phone.mute(muted)
	$('phone-mute').textContent = muted ? t('phone_system', 'Unmute') : t('phone_system', 'Mute')
})

const first = new URLSearchParams(window.location.search).get('dial')
if (first) pending = first
loadHistory()
connect().catch((error) => { state.session = `failed: ${error.step ?? ''} ${error.message}`; fail(error) })
