/**
 * Administrator setup (REQ-005 6.1): create a new phone system through GCNA's checkout
 * (email code, Stripe embedded checkout), follow its setup, give users an extension,
 * or connect an existing phone system in the admin settings.
 */
const api = (path) => OC.generateUrl(`/apps/phone_system/setup${path}`)
const body = () => document.getElementById('phone-setup-body')
const el = (tag, props = {}, ...children) => {
	const node = Object.assign(document.createElement(tag), props)
	node.append(...children.filter((c) => c !== null && c !== undefined))
	return node
}
const message = (text, error = false) => {
	const node = document.getElementById('phone-setup-message')
	node.textContent = text ?? ''
	node.className = `phone-setup-message${error ? ' error' : ''}`
}
window.__setup = { step: 'loading' }

async function call(path, method = 'GET', data) {
	const response = await fetch(path.startsWith(OC.generateUrl('/apps/')) ? path : api(path), {
		method, headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
		body: data ? JSON.stringify(data) : undefined,
	})
	const payload = await response.json().catch(() => ({}))
	if (!response.ok) throw new Error(payload.error ?? t('phone_system', 'The request failed.'))
	return payload
}

const money = (price) => new Intl.NumberFormat(OC.getLanguage(), { style: 'currency', currency: price.currency.toUpperCase() }).format(price.amount / 100)

function step(name, ...nodes) {
	window.__setup.step = name
	body().replaceChildren(...nodes)
}

function choose() {
	const create = el('button', { type: 'button', className: 'phone-setup-card primary-card' },
		el('strong', { textContent: t('phone_system', 'Create a new phone system') }),
		el('span', { textContent: t('phone_system', 'Choose a plan, pay by card or SEPA Direct Debit, and your phone system is set up for you.') }))
	create.addEventListener('click', plans)
	const connect = el('a', { className: 'phone-setup-card', href: OC.generateUrl('/settings/admin/phone_system') },
		el('strong', { textContent: t('phone_system', 'Connect existing phone system') }),
		el('span', { textContent: t('phone_system', 'Enter the address of your phone system and give users their extensions.') }))
	step('choose', el('div', { className: 'phone-setup-cards' }, create, connect))
}

async function plans() {
	message(t('phone_system', 'Loading plans…'))
	const catalog = await call('/catalog').catch((error) => { message(error.message, true); throw error })
	message('')
	let term = 'monthly'
	const list = el('div', { className: 'phone-setup-plans' })
	const render = () => list.replaceChildren(...catalog.plans.filter((p) => p.prices[term]).map((plan, index) => el('label', { className: 'phone-setup-plan' },
		el('input', { type: 'radio', name: 'plan', value: plan.planId, checked: index === 0 }),
		el('span', { textContent: plan.name }),
		el('strong', { textContent: `${money(plan.prices[term])} / ${term === 'monthly' ? t('phone_system', 'month') : t('phone_system', 'year')}` }))))
	const terms = el('div', { className: 'phone-setup-terms' }, ...['monthly', 'annual'].map((value) => {
		const button = el('button', { type: 'button', textContent: value === 'monthly' ? t('phone_system', 'Monthly') : t('phone_system', 'Yearly') })
		button.addEventListener('click', () => { term = value; render() })
		return button
	}))
	render()
	const form = el('form', { className: 'phone-setup-form' }, terms, list)
	const buyer = catalog.buyer
	if (!catalog.linked) {
		const field = (name, label, value, type = 'text') => el('label', {}, el('span', { textContent: label }), el('input', { name, type, value: value ?? '', required: true }))
		form.append(
			field('adminName', t('phone_system', 'Your name'), buyer.adminName),
			field('email', t('phone_system', 'Your email address'), buyer.email, 'email'),
			field('companyName', t('phone_system', 'Company'), buyer.companyName),
			field('domain', t('phone_system', 'Company domain'), buyer.domain),
		)
		if (catalog.legal) {
			const terms = el('a', { href: catalog.legal.terms.url, target: '_blank', rel: 'noopener', textContent: t('phone_system', 'terms') })
			const privacy = el('details', {}, el('summary', { textContent: t('phone_system', 'Privacy notice') }),
				...catalog.legal.privacy.sections.map((s) => el('section', {}, el('h4', { textContent: s.title }), ...s.text.map((line) => el('p', { textContent: line })))))
			form.append(privacy, el('label', { className: 'phone-setup-accept' }, el('input', { type: 'checkbox', name: 'acceptTerms', required: true }),
				el('span', {}, t('phone_system', 'I accept the'), ' ', terms, ' ', t('phone_system', 'and the privacy notice of {entity}.', { entity: catalog.legal.imprint.entity }))))
		} else {
			form.append(el('p', { className: 'error', textContent: t('phone_system', 'Purchases open once the provider has published its terms.') }))
		}
	}
	form.append(el('button', { type: 'submit', className: 'primary', textContent: catalog.linked ? t('phone_system', 'Continue to payment') : t('phone_system', 'Continue') }))
	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		const data = Object.fromEntries(new FormData(form))
		try {
			message(t('phone_system', 'Please wait…'))
			const result = await call('/checkout', 'POST', {
				...data, planId: data.plan, term, catalogVersion: catalog.catalogVersion, acceptTerms: data.acceptTerms === 'on',
				termsVersion: catalog.legal?.terms.version ?? '', privacyVersion: catalog.legal?.privacy.version ?? '',
			})
			message('')
			result.state === 'verification_pending' ? verify(data.email) : pay(result)
		} catch (error) { message(error.message, true) }
	})
	step('plans', form)
}

function verify(email) {
	const code = el('input', { name: 'code', inputMode: 'numeric', autocomplete: 'one-time-code', pattern: '[0-9]{6}', maxLength: 6, required: true, id: 'phone-setup-code' })
	const resend = el('button', { type: 'button', textContent: t('phone_system', 'Send a new code') })
	resend.addEventListener('click', () => call('/checkout/resend', 'POST').then(() => message(t('phone_system', 'A new code is on its way.'))).catch((e) => message(e.message, true)))
	const form = el('form', { className: 'phone-setup-form' },
		el('p', { textContent: email ? t('phone_system', 'We sent a six-digit code to {email}.', { email }) : t('phone_system', 'Enter the six-digit code from the email.') }),
		el('label', {}, el('span', { textContent: t('phone_system', 'Verification code') }), code),
		el('p', { className: 'phone-actions' }, el('button', { type: 'submit', className: 'primary', textContent: t('phone_system', 'Confirm') }), resend))
	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		try { message(''); pay(await call('/checkout/confirm', 'POST', { code: code.value.trim() })) } catch (error) { message(error.message, true) }
	})
	step('verify', form)
}

async function loadStripe() {
	if (window.Stripe) return window.Stripe
	await new Promise((resolve, reject) => {
		const script = el('script', { src: 'https://js.stripe.com/v3/', async: true })
		script.addEventListener('load', resolve); script.addEventListener('error', () => reject(new Error(t('phone_system', 'The payment form could not be loaded.'))))
		document.head.append(script)
	})
	return window.Stripe
}

async function pay(payment) {
	if (payment.state === 'paid') return overview()
	const mount = el('div', { id: 'phone-setup-stripe', className: 'phone-setup-stripe' })
	step('payment', mount)
	try {
		const Stripe = await loadStripe()
		const checkout = await Stripe(payment.publishableKey).initEmbeddedCheckout({
			clientSecret: payment.clientSecret,
			onComplete: () => { window.__setup.paid = true; checkout.destroy(); message(t('phone_system', 'Payment received. Your phone system is being set up.')); overview() },
		})
		checkout.mount(mount)
		window.__setup.stripe = 'mounted'
	} catch (error) { message(error.message, true) }
}

const STAGE = {
	paid: t('phone_system', 'Payment'), provisioned: t('phone_system', 'Phone system'), users: t('phone_system', 'Users'),
}
let poll = null

async function overview() {
	clearTimeout(poll)
	const state = await call('/state').catch((error) => { message(error.message, true); throw error })
	window.__setup.state = state
	if (!state.systems.length) {
		// Right after payment GCNA may not have created the system yet: keep waiting instead of starting over.
		if (state.linked && window.__setup.paid) {
			step('waiting', el('p', { textContent: t('phone_system', 'Payment received. Your phone system is being set up.') }))
			poll = setTimeout(overview, 3000)
			return
		}
		if (state.checkout.state === 'verification_pending') return verify('')
		if (state.checkout.state === 'payment_required') return pay(state.checkout)
		return choose()
	}
	const admin = await call(OC.generateUrl('/apps/phone_system/admin/state'))
	const cards = state.systems.map((system) => {
		const stages = el('ul', { className: 'phone-setup-stages' }, ...Object.entries(system.stages).map(([name, stage]) =>
			el('li', { className: `stage-${stage.state}`, textContent: `${STAGE[name] ?? name}: ${stage.state === 'complete' ? '✓' : stage.message ?? '…'}` })))
		const card = el('section', { className: 'phone-setup-system' },
			el('h3', { textContent: system.name }), el('p', { className: 'phone-status', textContent: system.hostname }), stages)
		if (system.cancelled) {
			card.append(el('p', { className: 'error', textContent: t('phone_system', 'Cancelled. The phone system and its data will be deleted on {date}.', { date: new Date(system.cancelled.deleteAt).toLocaleDateString() }) }))
			return card
		}
		if (system.stages.provisioned?.state === 'complete') {
			const picks = el('div', { className: 'phone-setup-users' }, ...admin.users.map((user) => el('label', {},
				el('input', { type: 'checkbox', value: user.id, checked: !!user.extension && state.tenant === system.hostname }),
				el('span', { textContent: user.extension && state.tenant === system.hostname ? `${user.name} (${user.extension})` : user.name }))))
			const save = el('button', { type: 'button', className: 'primary', textContent: t('phone_system', 'Give the selected users an extension') })
			save.addEventListener('click', async () => {
				const users = [...picks.querySelectorAll('input:checked')].map((input) => input.value)
				try { message(t('phone_system', 'Please wait…')); await call(`/systems/${system.id}/users`, 'POST', { users }); message(t('phone_system', 'Done. The selected users can now call from the phone window.')); overview() } catch (error) { message(error.message, true) }
			})
			const billing = el('button', { type: 'button', textContent: t('phone_system', 'Manage subscription') })
			billing.addEventListener('click', async () => {
				try { window.location.href = (await call(`/systems/${system.id}/billing`, 'POST')).url } catch (error) { message(error.message, true) }
			})
			card.append(el('h4', { textContent: t('phone_system', 'Users') }), picks, el('p', { className: 'phone-actions' }, save, billing))
		}
		return card
	})
	const another = el('button', { type: 'button', textContent: t('phone_system', 'Buy another phone system') })
	another.addEventListener('click', plans)
	step('systems', ...cards, el('p', {}, another))
	if (state.systems.some((s) => !s.cancelled && s.stages.provisioned?.state !== 'complete')) poll = setTimeout(overview, 5000)
}

overview().catch((error) => message(error.message, true))
