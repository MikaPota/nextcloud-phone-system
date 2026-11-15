/** Every user's own BIZZKICKR: register, the card with its QR code, sending it, requests and connections. */
const APP = 'bizzkickr'
const url = (path) => OC.generateUrl(`/apps/${APP}${path}`)
const tr = (text, vars) => t(APP, text, vars)
const body = document.getElementById('bizzkickr-body')
const FIELDS = [['name', 'Name'], ['organization', 'Organization'], ['role', 'Role'], ['phone', 'Phone'], ['email', 'Email on the card'], ['web', 'Web']]

function message(text, error = false) {
	const node = document.getElementById('bizzkickr-message')
	node.textContent = text ?? ''
	node.classList.toggle('error', error)
}

function el(tag, props = {}, ...children) {
	const node = Object.assign(document.createElement(tag), props)
	node.append(...children.filter((child) => child !== null && child !== undefined))
	return node
}

async function send(path, method = 'GET', data) {
	const response = await fetch(url(path), {
		method, headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
		body: data ? JSON.stringify(data) : undefined,
	})
	const payload = await response.json().catch(() => ({}))
	if (!response.ok) throw new Error(payload.error ?? tr('BIZZKICKR cannot be reached right now. Retry later.'))
	return payload
}

function button(text, action, primary = false) {
	const node = el('button', { type: 'button', textContent: text, className: primary ? 'primary' : '' })
	node.addEventListener('click', async () => {
		node.disabled = true
		message('')
		try { await action() } catch (error) { message(error.message, true) } finally { node.disabled = false }
	})
	return node
}

const recipient = new URLSearchParams(location.search).get('send') ?? ''

function cardSection(card) {
	const qr = el('img', { className: 'bizzkickr-qr', alt: tr('QR code of your card'), src: url(`/card/qr?v=${encodeURIComponent(card.id + card.name + card.role)}`) })
	const link = el('a', { href: card.link, target: '_blank', rel: 'noopener noreferrer', textContent: card.link })
	const form = el('form', { className: 'bizzkickr-form' })
	for (const [field, label] of FIELDS) {
		form.append(el('label', {}, tr(label), el('input', { name: field, value: card[field] ?? '', maxLength: 100 })))
	}
	form.append(el('div', {}, el('button', { type: 'submit', className: 'primary', textContent: tr('Save the card') })))
	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		try {
			render(await send('/card', 'PUT', Object.fromEntries(new FormData(form))))
			message(tr('Saved.'))
		} catch (error) { message(error.message, true) }
	})
	return el('section', { className: 'bizzkickr-card' }, el('div', { className: 'bizzkickr-show' }, qr, link), form)
}

function sendSection() {
	const input = el('input', { type: 'email', value: recipient, placeholder: 'name@example.com', required: true })
	const form = el('form', { className: 'bizzkickr-row' }, input, el('button', { type: 'submit', textContent: tr('Send my card') }))
	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		try {
			await send('/send', 'POST', { to: input.value })
			message(tr('Your card is on its way to {email}.', { email: input.value }))
			input.value = ''
		} catch (error) { message(error.message, true) }
	})
	if (recipient) setTimeout(() => input.focus())
	return el('section', {}, el('h3', { textContent: tr('Send your card') }), el('p', { className: 'bizzkickr-hint', textContent: tr('BIZZKICKR emails your card with its vCard and a link to connect.') }), form)
}

function requestList(requests) {
	if (!requests.length) return el('p', { className: 'bizzkickr-hint', textContent: tr('No requests are waiting.') })
	return el('ul', { className: 'bizzkickr-requests' }, ...requests.map((request) => {
		const answer = (verb) => async () => {
			const result = await send(`/requests/${request.id}/${verb}`, 'POST')
			message(verb === 'accept' ? tr('{name} is now a connection and in your contacts.', { name: request.name }) : tr('Declined.'))
			list.replaceChildren(requestList(result.requests))
		}
		const who = [request.name, request.organization, request.event].filter(Boolean).join(' · ')
		return el('li', {}, el('span', { textContent: who }), button(tr('Accept'), answer('accept'), true), button(tr('Decline'), answer('decline')))
	}))
}
const list = el('div')

function connectionsSection(state) {
	const when = state.syncedAt ? new Date(state.syncedAt).toLocaleString() : tr('never')
	const load = async () => list.replaceChildren(requestList((await send('/requests')).requests))
	load().catch((error) => message(error.message, true))
	return el('section', {},
		el('h3', { textContent: tr('Requests to connect') }), list,
		el('h3', { textContent: tr('Connections') }),
		el('p', { className: 'bizzkickr-hint', textContent: tr('Confirmed connections become contacts in your address book. A field you change there is not overwritten later. Last fetched: {when}', { when }) }),
		button(tr('Fetch connections now'), async () => { render(await send('/sync', 'POST')); message(tr('Connections are up to date.')) }))
}

function render(state) {
	if (state.notice) message(state.notice, true)
	if (!state.linked) {
		body.replaceChildren(el('p', { textContent: tr('An administrator must connect this Nextcloud to GCN-ALLIANCE first: Administration settings > BIZZKICKR.') }))
		return
	}
	if (state.state === 'none') {
		body.replaceChildren(
			el('p', { textContent: tr('BIZZKICKR is your digital business card. Whoever scans it asks to connect; once you accept, they appear in your contacts.') }),
			el('p', { textContent: tr('BIZZKICKR emails a confirmation link to {email}.', { email: state.email || '?' }) }),
			button(tr('Register for BIZZKICKR'), async () => { render(await send('/register', 'POST')); message(tr('Check your email for the confirmation link.')) }, true))
		return
	}
	if (state.state === 'unconfirmed') {
		body.replaceChildren(
			el('p', { textContent: tr('BIZZKICKR emailed a confirmation link to {email}. Open it, then refresh.', { email: state.email }) }),
			el('div', { className: 'bizzkickr-row' },
				button(tr('Refresh'), async () => render(await send('/refresh', 'POST')), true),
				button(tr('Send the link again'), async () => { render(await send('/register', 'POST')); message(tr('Check your email for the confirmation link.')) })))
		return
	}
	if (state.state === 'no_card') {
		body.replaceChildren(
			el('p', { textContent: tr('Create your card from your Nextcloud profile; you can change every field afterwards.') }),
			button(tr('Create my card'), async () => render(await send('/card', 'POST')), true),
			connectionsSection(state))
		return
	}
	body.replaceChildren(cardSection(state.card), sendSection(), connectionsSection(state))
}

send('/state').then(render).catch((error) => message(error.message, true))
