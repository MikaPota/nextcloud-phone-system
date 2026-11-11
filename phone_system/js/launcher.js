/**
 * Runs on every Nextcloud page. Nextcloud reloads the page when the user switches
 * apps, which would end a call, so the phone lives in its own window. This script
 * adds the header button, opens or focuses that window, and hands it numbers to dial.
 */
(() => {
	const NAME = 'phone-system'
	const channel = new BroadcastChannel('phone_system')
	const phoneUrl = (dial) => {
		const url = new URL(OC.generateUrl('/apps/phone_system/'), window.location.origin)
		url.searchParams.set('window', '1')
		if (dial) url.searchParams.set('dial', dial)
		return url.toString()
	}

	let alive = false
	channel.addEventListener('message', ({ data }) => {
		if (data?.type === 'pong') alive = true
	})

	/** Focus the running phone window, or open one. A running phone keeps its call. */
	async function openPhone(dial) {
		alive = false
		channel.postMessage({ type: 'ping' })
		await new Promise(resolve => setTimeout(resolve, 150))
		if (alive) {
			if (dial) channel.postMessage({ type: 'dial', number: dial })
			window.open('', NAME)?.focus()
			return
		}
		const opened = window.open(phoneUrl(dial), NAME, 'popup,width=380,height=620')
		if (!opened) console.warn('phone_system: the browser blocked the phone window')
	}
	window.PhoneSystem = { openPhone }

	function addButton() {
		const header = document.querySelector('#header .header-end, #header .header-right, .header-end')
		if (!header || document.getElementById('phone-system-launcher')) return
		const button = document.createElement('button')
		button.id = 'phone-system-launcher'
		button.type = 'button'
		button.title = t('phone_system', 'Phone')
		button.setAttribute('aria-label', t('phone_system', 'Phone'))
		button.className = 'phone-system-launcher'
		button.style.cssText = 'background:none;border:0;color:var(--color-background-plain-text,#fff);width:44px;height:44px;cursor:pointer;display:flex;align-items:center;justify-content:center'
		button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/></svg>'
		button.addEventListener('click', () => openPhone())
		header.prepend(button)
	}

	// Contacts-menu "Call" links and tel: links dial in the phone window instead of navigating.
	document.addEventListener('click', (event) => {
		const link = event.target.closest?.('a[href]')
		if (!link) return
		const href = link.getAttribute('href')
		let dial = null
		if (href.startsWith('tel:')) {
			dial = decodeURIComponent(href.slice(4)).replace(/[^0-9+*#]/g, '')
		} else if (href.includes('/apps/phone_system/') && href.includes('window=1')) {
			dial = new URL(href, window.location.origin).searchParams.get('dial')
		}
		if (dial !== null) {
			event.preventDefault()
			openPhone(dial)
		}
	}, true)

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', addButton)
	} else {
		addButton()
	}
})()
