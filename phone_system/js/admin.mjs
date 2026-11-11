/** Administrator settings: phone system address and user-to-extension mapping. */
const url = (path) => OC.generateUrl(`/apps/phone_system/admin${path}`)
const message = (text, error = false) => {
	const node = document.getElementById('phone-admin-message')
	node.textContent = text
	node.style.color = error ? 'var(--color-error)' : ''
}

async function send(path, method, body) {
	const response = await fetch(url(path), {
		method, headers: { requesttoken: OC.requestToken, 'Content-Type': 'application/json' },
		body: body ? JSON.stringify(body) : undefined,
	})
	const data = await response.json()
	if (!response.ok) throw new Error(data.error ?? t('phone_system', 'Saving failed.'))
	return data
}

function render(state) {
	document.getElementById('phone-admin-host').value = state.tenant
	const body = document.querySelector('#phone-admin-users tbody')
	body.replaceChildren(...state.users.map((user) => {
		const row = document.createElement('tr')
		row.dataset.user = user.id
		const name = document.createElement('td')
		name.textContent = user.name
		const extension = Object.assign(document.createElement('input'), { type: 'text', inputMode: 'numeric', value: user.extension ?? '', size: 6, className: 'phone-admin-extension' })
		const password = Object.assign(document.createElement('input'), { type: 'password', autocomplete: 'new-password', className: 'phone-admin-password', placeholder: user.extension ? t('phone_system', 'unchanged') : '' })
		const save = Object.assign(document.createElement('button'), { type: 'button', textContent: t('phone_system', 'Assign'), className: 'phone-admin-assign' })
		save.addEventListener('click', async () => {
			message(t('phone_system', 'Checking the login with the phone system…'))
			try {
				render(await send('/mapping', 'POST', { user: user.id, extension: extension.value.trim(), password: password.value }))
				message(t('phone_system', 'Extension {extension} assigned to {user}.', { extension: extension.value.trim(), user: user.name }))
			} catch (error) {
				message(error.message, true)
				send('/state', 'GET').then(render).catch(() => {})
			}
		})
		const cells = [name, extension, password, save].map((node) => {
			if (node.tagName === 'TD') return node
			const cell = document.createElement('td'); cell.append(node); return cell
		})
		if (user.extension) {
			const remove = Object.assign(document.createElement('button'), { type: 'button', textContent: t('phone_system', 'Remove') })
			remove.addEventListener('click', async () => {
				try { render(await send(`/mapping/${encodeURIComponent(user.id)}`, 'DELETE')); message('') } catch (error) { message(error.message, true) }
			})
			cells[3].append(' ', remove)
		}
		row.append(...cells)
		return row
	}))
}

document.getElementById('phone-admin-tenant').addEventListener('submit', async (event) => {
	event.preventDefault()
	try { render(await send('/tenant', 'POST', { tenant: document.getElementById('phone-admin-host').value })); message(t('phone_system', 'Saved.')) } catch (error) { message(error.message, true) }
})

send('/state', 'GET').then(render).catch((error) => message(error.message, true))
