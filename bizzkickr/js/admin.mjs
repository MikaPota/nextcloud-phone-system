/** Administrator: connect this Nextcloud to GCN-ALLIANCE with a free account, confirmed by an emailed code. */
const APP = 'bizzkickr'
const url = (path) => OC.generateUrl(`/apps/${APP}/setup${path}`)
const tr = (text, vars) => t(APP, text, vars)
const body = document.getElementById('bizzkickr-admin-body')

function message(text, error = false) {
	const node = document.getElementById('bizzkickr-admin-message')
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
	if (!response.ok) throw new Error(payload.error ?? tr('GCNA could not be reached. Retry later or contact your service provider.'))
	return payload
}

function codeForm() {
	const code = el('input', { inputMode: 'numeric', autocomplete: 'one-time-code', maxLength: 6, pattern: '[0-9]{6}', required: true })
	const form = el('form', { className: 'bizzkickr-row' }, el('label', {}, tr('Verification code'), code), el('button', { type: 'submit', className: 'primary', textContent: tr('Confirm') }))
	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		try { await send('/signup/confirm', 'POST', { code: code.value.trim() }); message(tr('This Nextcloud is connected. Users can now register in BIZZKICKR.')); load() } catch (error) { message(error.message, true) }
	})
	const resend = el('button', { type: 'button', textContent: tr('Send a new code') })
	resend.addEventListener('click', async () => { try { await send('/signup/resend', 'POST'); message(tr('A new code is on its way.')) } catch (error) { message(error.message, true) } })
	const restart = el('button', { type: 'button', textContent: tr('Start again') })
	restart.addEventListener('click', async () => { await send('/signup', 'DELETE'); message(''); load() })
	return el('div', {}, el('p', { textContent: tr('GCNA emailed a six-digit code. Enter it within 10 minutes.') }), form, el('div', { className: 'bizzkickr-row' }, resend, restart))
}

function signupForm(state) {
	if (!state.legal) return el('p', { textContent: tr('Sign-up opens once the provider has published its terms.') })
	const field = (name, label, value, type = 'text') => el('label', {}, tr(label), el('input', { name, value: value ?? '', type, required: true }))
	const accept = el('input', { type: 'checkbox', name: 'acceptTerms', required: true })
	const terms = el('a', { href: state.legal.terms.url, target: '_blank', rel: 'noopener noreferrer', textContent: tr('terms') })
	const privacy = el('details', {}, el('summary', { textContent: tr('Privacy notice') }),
		...state.legal.privacy.sections.map((section) => el('section', {}, el('h4', { textContent: section.title }), ...section.text.map((line) => el('p', { textContent: line })))))
	const form = el('form', { className: 'bizzkickr-form' },
		field('adminName', 'Your name', state.buyer.adminName), field('email', 'Your email address', state.buyer.email, 'email'),
		field('companyName', 'Company', state.buyer.companyName), field('domain', 'Company domain', state.buyer.domain),
		privacy,
		el('label', { className: 'bizzkickr-accept' }, accept, el('span', {}, tr('I accept the'), ' ', terms, ' ', tr('and the privacy notice of {entity}.', { entity: state.legal.entity }))),
		el('div', {}, el('button', { type: 'submit', className: 'primary', textContent: tr('Create a free GCNA account') })))
	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		const values = Object.fromEntries(new FormData(form))
		try {
			await send('/signup', 'POST', { ...values, acceptTerms: accept.checked, termsVersion: state.legal.terms.version, privacyVersion: state.legal.privacy.version })
			message('')
			body.replaceChildren(codeForm())
		} catch (error) { message(error.message, true) }
	})
	return form
}

async function load() {
	try {
		const state = await send('/state')
		if (state.linked === 'phone_system') {
			body.replaceChildren(el('p', { textContent: tr('This Nextcloud is connected to GCN-ALLIANCE through the Phone System app. Users can register in BIZZKICKR.') }))
		} else if (state.linked) {
			body.replaceChildren(el('p', { textContent: tr('This Nextcloud is connected to GCN-ALLIANCE. Users can register in BIZZKICKR.') }))
		} else if (state.pending) {
			body.replaceChildren(codeForm())
		} else {
			body.replaceChildren(signupForm(state))
		}
	} catch (error) { message(error.message, true) }
}

load()
