# quickjot

Jot a timestamped note onto today's DokuWiki journal page without opening the wiki.

Two halves that talk over one small HTTP endpoint:

* a DokuWiki plugin that appends notes under a dedicated heading on today's page
* a Firefox extension (`firefox/`) that gives you a keyboard shortcut and a note box

The endpoint is deliberately plain form-encoded HTTP, so a future mobile client (a
Shortcut, a Tasker action, a shell script) can use it just as easily.

## What a note looks like

```
===== Notes =====

  * **14:32** picked up the parcel
  * **16:05** worth reading [[https://example.com/a|An Article]]
> the bit I had selected
```

The note text stays on a single line - embedded newlines become DokuWiki forced line
breaks - so each note remains one list item.

## Setting up the wiki side

1. Drop this directory into `lib/plugins/quickjot/` and enable it in the extension manager.
2. Configure it (Admin → Configuration Settings → quickjot):

   | setting | default | meaning |
   |---|---|---|
   | `pagepattern` | `appreciation:%Y:%m:%Y-%m-%d` | page ID of today's page |
   | `notesheading` | `===== Notes =====` | heading notes go under, created if missing |
   | `timeformat` | `%H:%M` | the bold timestamp on each note |
   | `createpage` | on | create today's page when it does not exist yet |

   `pagepattern` and `timeformat` use **strftime** placeholders. Anything that is not a
   `%` sequence is taken literally, which is what lets a namespace like `appreciation`
   survive unharmed. Times come from the server's clock and timezone.

3. Get an API token: log in, open **Update Profile**, and copy the API token shown there.
   This is DokuWiki's own per-user token - regenerating it revokes the old one, and it is
   the same token for every client you point at this wiki.

## The API

Both calls are `POST` to `lib/exe/ajax.php`, authenticated with
`Authorization: Bearer <token>`. A session cookie is **not** accepted: requiring a header
that a cross-origin page cannot set is what keeps the endpoint safe from CSRF.

### `?call=plugin_quickjot_status`

No parameters, no side effects. Answers with the user, the page a note would go to now,
and whether it exists yet.

```json
{"ok":true,"user":"you","page":"appreciation:2026:07:2026-07-26","exists":false,"url":"…"}
```

### `?call=plugin_quickjot`

Form-encoded body:

| field | required | meaning |
|---|---|---|
| `text` | yes | the note |
| `quote` | no | text to render as a `>` blockquote under the note |
| `url` | no | link to attach; must be `http`/`https` |
| `title` | no | link title, falls back to the URL |

```json
{"ok":true,"page":"appreciation:2026:07:2026-07-26","created":true,"time":"14:32","url":"…"}
```

Failures answer `{"ok":false,"error":"…"}` with a matching status: 401 (no or bad token),
403 (ACL), 404 (page missing and creation disabled), 409 (page locked), 400 (bad input).

Example:

```sh
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
     --data-urlencode 'text=testing the endpoint' \
     --data-urlencode 'url=https://example.com' \
     --data-urlencode 'title=Example' \
     'https://wiki.example.com/lib/exe/ajax.php?call=plugin_quickjot'
```

## The Firefox extension

Load it from `about:debugging` → This Firefox → Load Temporary Add-on → pick
`firefox/manifest.json`. (Temporary add-ons go away when Firefox restarts; package it
with `web-ext build` and sign it via addons.mozilla.org for a permanent install.)

Open its options page, enter the wiki URL and the token, and press Save - Firefox will
ask for permission to reach that host, which the extension needs to make the request.
**Test connection** confirms the token works and shows the page notes will go to.

Then `Alt+Shift+N` (rebindable under `about:addons` → gear → Manage Extension Shortcuts)
or the toolbar button opens the note box. Type, and `Ctrl+Enter` saves. If the current tab
is a web page you can attach it as a link, and if you had text selected you can attach it
as a quote; both are pre-checked when available.

The extension sends the raw fields and lets the plugin build the wiki markup, so escaping
lives in exactly one place.

## Tests

From the wiki's `_test/` directory, after `composer install` there:

```sh
./vendor/bin/phpunit --stderr --group plugin_quickjot
```
