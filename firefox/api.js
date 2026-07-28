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
 * How this device's clock relates to UTC.
 *
 * The wiki may well run on a server in another timezone - usually UTC - and it is this
 * device's idea of "today" and "now" that a note should be filed under. Both fields are
 * sent: the IANA name is the accurate one because it carries the DST rules, the offset
 * is a fallback for a server whose timezone database does not know the name.
 *
 * @returns {{tz: string, tzoffset: string}}
 */
function localTimezone() {
    let name = '';
    try {
        name = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch (e) {
        // no Intl data - the offset below is enough on its own
    }

    // getTimezoneOffset() counts minutes *behind* UTC, ISO 8601 counts them ahead
    const minutes = -new Date().getTimezoneOffset();
    const pad = (n) => String(Math.floor(Math.abs(n))).padStart(2, '0');

    return {
        tz: name,
        tzoffset: (minutes < 0 ? '-' : '+') + pad(minutes / 60) + ':' + pad(minutes % 60),
    };
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

    // added here rather than at the call sites so no caller can forget it
    const payload = {...localTimezone(), ...fields};

    const body = new URLSearchParams();
    for (const [key, value] of Object.entries(payload)) {
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
