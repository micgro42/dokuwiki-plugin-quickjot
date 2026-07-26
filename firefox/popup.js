/**
 * The note box. Opened by the toolbar button or the keyboard shortcut.
 */

const els = {
    text: document.getElementById('text'),
    attachLink: document.getElementById('attach-link'),
    attachQuote: document.getElementById('attach-quote'),
    linkRow: document.getElementById('link-row'),
    quoteRow: document.getElementById('quote-row'),
    linkDetail: document.getElementById('link-detail'),
    quoteDetail: document.getElementById('quote-detail'),
    save: document.getElementById('save'),
    status: document.getElementById('status'),
    target: document.getElementById('target'),
};

/** @type {{url: string, title: string, selection: string}} */
const context = {url: '', title: '', selection: ''};

/**
 * Show a message under the form.
 *
 * @param {string} message
 * @param {string} kind '', 'ok' or 'error'
 */
function setStatus(message, kind = '') {
    els.status.textContent = message;
    els.status.className = 'status' + (kind ? ' ' + kind : '');
}

/**
 * Enable a checkbox row and describe what it would attach.
 *
 * @param {HTMLElement} row
 * @param {HTMLInputElement} box
 * @param {HTMLElement} detail
 * @param {string} text
 */
function enableRow(row, box, detail, text) {
    box.disabled = false;
    box.checked = true;
    row.classList.remove('disabled');
    detail.textContent = text;
    detail.title = text;
}

/**
 * Collect the current tab's URL, title and selected text.
 *
 * activeTab is granted while the popup is open, so this needs no broad host permission.
 * Pages the extension may not script (about:, addons.mozilla.org, ...) simply yield no
 * selection.
 */
async function collectContext() {
    const [tab] = await browser.tabs.query({active: true, currentWindow: true});
    if (!tab) return;

    if (tab.url && /^https?:/i.test(tab.url)) {
        context.url = tab.url;
        context.title = tab.title || tab.url;
        enableRow(els.linkRow, els.attachLink, els.linkDetail, context.title);
    } else {
        els.linkDetail.textContent = 'not a web page';
    }

    try {
        const results = await browser.scripting.executeScript({
            target: {tabId: tab.id},
            func: () => window.getSelection().toString(),
        });
        const selection = (results && results[0] && results[0].result || '').trim();
        if (selection) {
            context.selection = selection;
            enableRow(els.quoteRow, els.attachQuote, els.quoteDetail, selection);
        }
    } catch (e) {
        // scripting is refused on privileged pages, which is fine - no selection then
        els.quoteDetail.textContent = 'selection unavailable here';
    }
}

/**
 * Show where a note would land, as a sanity check on the page pattern.
 */
async function showTarget() {
    try {
        const status = await fetchStatus();
        els.target.textContent = '→ ' + status.page;
        els.target.title = status.url;
    } catch (e) {
        setStatus(e.message, 'error');
    }
}

/**
 * Send the note.
 */
async function submit() {
    const text = els.text.value.trim();
    if (!text) {
        setStatus('Type something first.', 'error');
        els.text.focus();
        return;
    }

    els.save.disabled = true;
    setStatus('Saving…');

    const note = {text};
    if (els.attachLink.checked && context.url) {
        note.url = context.url;
        note.title = context.title;
    }
    if (els.attachQuote.checked && context.selection) {
        note.quote = context.selection;
    }

    try {
        const result = await addNote(note);
        setStatus(`Saved to ${result.page} at ${result.time}.`, 'ok');
        setTimeout(() => window.close(), 700);
    } catch (e) {
        // keep the popup and the typed text so nothing is lost
        setStatus(e.message, 'error');
        els.save.disabled = false;
    }
}

/**
 * Report a rejected promise in the status line instead of losing it to the console.
 *
 * @param {Promise} promise
 */
function reportFailure(promise) {
    Promise.resolve(promise).catch((e) => setStatus(e.message, 'error'));
}

els.save.addEventListener('click', () => reportFailure(submit()));
els.text.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        reportFailure(submit());
    }
});

reportFailure(collectContext());
reportFailure(showTarget());
els.text.focus();
