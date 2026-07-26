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
 * Make sure we may talk to the configured wiki.
 *
 * The URL is only known at runtime, so the permission cannot be declared statically.
 * Requesting it has to happen from a user gesture, which is why this hangs off Save.
 *
 * @param {string} baseUrl
 * @returns {Promise<boolean>}
 */
async function ensurePermission(baseUrl) {
    const origin = originPattern(baseUrl);
    if (!origin) {
        setStatus('That does not look like a URL.', 'error');
        return false;
    }

    if (await browser.permissions.contains({origins: [origin]})) return true;

    const granted = await browser.permissions.request({origins: [origin]});
    if (!granted) {
        setStatus(`QuickJot needs permission to reach ${origin} to save notes.`, 'error');
    }
    return granted;
}

/**
 * Persist the settings, asking for the host permission on the way.
 */
async function save() {
    const baseUrl = els.baseUrl.value;
    const token = els.token.value;

    if (!baseUrl.trim() || !token.trim()) {
        setStatus('Both the URL and the token are needed.', 'error');
        return;
    }

    if (!await ensurePermission(baseUrl)) return;

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

els.save.addEventListener('click', save);
els.test.addEventListener('click', test);

loadSettings().then(({baseUrl, token}) => {
    els.baseUrl.value = baseUrl;
    els.token.value = token;
});
