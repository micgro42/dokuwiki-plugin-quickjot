/**
 * Shared client for the quickjot plugin endpoint.
 */

/**
 * Read the stored configuration.
 *
 * @returns {Promise<{baseUrl: string, token: string}>}
 */
async function loadSettings() {
    const stored = await browser.storage.local.get(['baseUrl', 'token']);
    return {
        baseUrl: stored.baseUrl || '',
        token: stored.token || '',
    };
}

/**
 * Save the configuration.
 *
 * @param {string} baseUrl wiki base URL, e.g. https://wiki.example.com
 * @param {string} token DokuWiki API token
 * @returns {Promise<void>}
 */
function saveSettings(baseUrl, token) {
    return browser.storage.local.set({
        baseUrl: normaliseBaseUrl(baseUrl),
        token: token.trim(),
    });
}

/**
 * Strip trailing slashes so URLs can be built by simple concatenation.
 *
 * @param {string} url
 * @returns {string}
 */
function normaliseBaseUrl(url) {
    return url.trim().replace(/\/+$/, '');
}

/**
 * The origin pattern the extension needs permission for.
 *
 * @param {string} baseUrl
 * @returns {string|null} null when the URL cannot be parsed
 */
function originPattern(baseUrl) {
    try {
        return new URL(normaliseBaseUrl(baseUrl)).origin + '/*';
    } catch (e) {
        return null;
    }
}

/**
 * POST to one of the plugin's ajax calls.
 *
 * Errors are raised as Error objects carrying the message the wiki sent, so callers
 * can show something more useful than "request failed".
 *
 * @param {string} call ajax call name
 * @param {object} fields form fields to send
 * @returns {Promise<object>} the decoded response
 */
async function callApi(call, fields = {}) {
    const {baseUrl, token} = await loadSettings();
    if (!baseUrl || !token) {
        throw new Error('QuickJot is not configured yet - open the options page first.');
    }

    const body = new URLSearchParams();
    for (const [key, value] of Object.entries(fields)) {
        if (value) body.append(key, value);
    }

    let response;
    try {
        response = await fetch(`${baseUrl}/lib/exe/ajax.php?call=${call}`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${token}`,
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            },
            // the token is the only credential, never send wiki cookies along
            credentials: 'omit',
            body: body.toString(),
        });
    } catch (e) {
        throw new Error(`Could not reach ${baseUrl} - is the URL right and the permission granted?`);
    }

    const text = await response.text();
    let json;
    try {
        json = JSON.parse(text);
    } catch (e) {
        throw new Error(`The wiki answered with something that is not JSON (HTTP ${response.status}).`);
    }

    if (!json.ok) {
        throw new Error(json.error || `Request failed with HTTP ${response.status}.`);
    }
    return json;
}

/**
 * Ask the wiki which page a note would go to right now.
 *
 * @returns {Promise<object>}
 */
function fetchStatus() {
    return callApi('plugin_quickjot_status');
}

/**
 * Add a note to today's page.
 *
 * @param {{text: string, quote?: string, url?: string, title?: string}} note
 * @returns {Promise<object>}
 */
function addNote(note) {
    return callApi('plugin_quickjot', note);
}
