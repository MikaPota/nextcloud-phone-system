/**
 * What the phone window tells the user when the shared client fails. The client
 * (shared/, kept in sync with the Odoo app) speaks English to developers; this maps
 * its failures to translated, actionable text. `null`: nothing to show.
 *
 * `t` is Nextcloud's translate function, bound to this app: t(text) → text.
 */
export function explain(error, t) {
	if (!error) return null
	// The browser refused the microphone, or there is none.
	if (error.name === 'NotAllowedError' || error.name === 'SecurityError') {
		return t('The microphone is blocked. Allow it for this site and try again.')
	}
	if (error.name === 'NotFoundError') return t('No microphone was found. Connect one and try again.')
	// The Nextcloud server's own errors are translated already.
	if (error.step === 'grant') return error.message || t('The phone could not sign in.')
	switch (error.step) {
	case 'unreachable': return t('The phone system could not be reached. Check the connection and try again.')
	case 'refused': return t('The phone system refused your extension\'s login. Ask an administrator to check your extension.')
	case 'cookies': return t('Your browser blocked the phone system\'s sign-in cookie. Allow cookies for gcn-alliance.com and open the phone again.')
	case 'identity': return t('The phone system signed in another extension. Close the phone window and open it again.')
	case 'signaling': return t('The connection to the phone system was interrupted. Close the phone window and open it again.')
	}
	const message = String(error.message ?? '')
	if (/cancelled/i.test(message)) return null
	if (/^Call setup failed/.test(message)) return t('The call could not start. Allow the microphone for this site and try again.')
	if (/^Answer failed/.test(message)) return t('The call could not be answered. Allow the microphone for this site and try again.')
	if (/second incoming call/.test(message)) return t('Another call came in and was declined: the phone takes one call at a time.')
	if (/authorized internal extensions/.test(message)) return t('This number cannot be called from here.')
	if (/ready, idle phone/.test(message)) return t('The phone is busy or not ready yet.')
	if (/^Registration (renewal|removal)/.test(message)) return t('The phone lost its connection. Close the phone window and open it again.')
	if (/signaling|Hangup delivery|connection is not open/i.test(message)) return t('The connection to the phone system was interrupted.')
	return t('Something went wrong with the call. Try again.')
}

/** Every text explain() can show, for the translation files and their test. */
export const TEXTS = [
	'The microphone is blocked. Allow it for this site and try again.',
	'No microphone was found. Connect one and try again.',
	'The phone could not sign in.',
	'The phone system could not be reached. Check the connection and try again.',
	'The phone system refused your extension\'s login. Ask an administrator to check your extension.',
	'Your browser blocked the phone system\'s sign-in cookie. Allow cookies for gcn-alliance.com and open the phone again.',
	'The phone system signed in another extension. Close the phone window and open it again.',
	'The connection to the phone system was interrupted. Close the phone window and open it again.',
	'The call could not start. Allow the microphone for this site and try again.',
	'The call could not be answered. Allow the microphone for this site and try again.',
	'Another call came in and was declined: the phone takes one call at a time.',
	'This number cannot be called from here.',
	'The phone is busy or not ready yet.',
	'The phone lost its connection. Close the phone window and open it again.',
	'The connection to the phone system was interrupted.',
	'Something went wrong with the call. Try again.',
]
