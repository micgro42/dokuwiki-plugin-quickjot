/**
 * Settings page: wiki URL, API token, and the host permission that lets us reach it.
 */

const els = {
    baseUrl: document.getElementById('base-url'),
    token: document.getElementById('token'),
    save: document.getElementById('save'),
    test: document.getElementById('test'),
    status: document.getElementById('status'),
};

/**
 * Show a message under the buttons.
 *
 * @param {string} message
 * @param {string} kind '', 'ok' or 'error'
 */
function setStatus(message, kind = '') {
    els.status.textContent = message;
    els.status.className = 'status' + (kind ? ' ' + kind : '');
}

/**
 * Persist the settings, asking for the host permission on the way.
 *
 * The wiki URL is only known at runtime, so the permission cannot be declared
 * statically and has to be requested here.
 *
 * Everything ahead of the permissions.request() call below must stay synchronous.
 * Firefox only honours the call while the user gesture that triggered this handler is
 * still live, and a single await anywhere before it drops the gesture and makes the
 * call throw "permissions.request may only be called from a user input handler".
 * That is also why there is no permissions.contains() pre-check: request() already
 * resolves true without prompting when the permission is held.
 */
async function save() {
    const baseUrl = els.baseUrl.value;
    const token = els.token.value;

    if (!baseUrl.trim() || !token.trim()) {
        setStatus('Both the URL and the token are needed.', 'error');
        return;
    }

    const origin = originPattern(baseUrl);
    if (!origin) {
        setStatus('That does not look like a URL.', 'error');
        return;
    }

    // first await of this handler - nothing may be awaited above this line
    const granted = await browser.permissions.request({origins: [origin]});
    if (!granted) {
        setStatus(`QuickJot needs permission to reach ${origin} to save notes.`, 'error');
        return;
    }

    await saveSettings(baseUrl, token);
    els.baseUrl.value = normaliseBaseUrl(baseUrl);
    setStatus('Saved.', 'ok');
}

/**
 * Ask the wiki who we are and where a note would go.
 */
async function test() {
    els.test.disabled = true;
    setStatus('Checking…');
    try {
        const status = await fetchStatus();
        const exists = status.exists ? 'exists already' : 'does not exist yet';
        setStatus(`Connected as ${status.user}. Today's notes go to ${status.page} (${exists}).`, 'ok');
    } catch (e) {
        setStatus(e.message, 'error');
    } finally {
        els.test.disabled = false;
    }
}

/**
 * Bind an async click handler so a rejection lands in the status line rather than
 * disappearing into the console as an uncaught promise error.
 *
 * The handler is invoked directly, not wrapped in an async function, so that it still
 * runs inside the user gesture - see the note in save().
 *
 * @param {HTMLElement} element
 * @param {function(): Promise<void>} handler
 */
function onClick(element, handler) {
    element.addEventListener('click', () => {
        try {
            Promise.resolve(handler()).catch((e) => setStatus(e.message, 'error'));
        } catch (e) {
            setStatus(e.message, 'error');
        }
    });
}

onClick(els.save, save);
onClick(els.test, test);

loadSettings().then(({baseUrl, token}) => {
    els.baseUrl.value = baseUrl;
    els.token.value = token;
});
