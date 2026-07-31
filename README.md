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
   | `timezone` | *(empty)* | timezone for clients that do not send their own |
   | `createpage` | on | create today's page when it does not exist yet |

   `pagepattern` and `timeformat` use **strftime** placeholders. Anything that is not a
   `%` sequence is taken literally, which is what lets a namespace like `appreciation`
   survive unharmed.

   `timezone` only matters for clients that send no timezone of their own; leave it empty
   to use the server's. See *Timezones* below.

3. Get an API token: log in, open **Update Profile**, and copy the API token shown there.
   This is DokuWiki's own per-user token - regenerating it revokes the old one, and it is
   the same token for every client you point at this wiki.

## Android share sheet (PWA)

The plugin adds a [Web Share Target][wst] to the wiki's web app manifest, which puts the
wiki into Android's system share sheet: share a page from Chrome, selected text from a
reader, or a link from a news app, and quickjot opens with the note already filled in. You
review and edit it, then hit save - a share never writes to the wiki on its own.

[wst]: https://developer.mozilla.org/en-US/docs/Web/Manifest/share_target

Requirements:

- The wiki must be on **public HTTPS with a valid certificate**. A self-signed cert or a
  LAN-only hostname will not do; Android refuses to install the app at all.
- Log in **with "remember me"** in the browser you install from. The compose form is
  authenticated by the ordinary session cookie, and a share that arrives logged out lands
  on the login form with the shared text dropped.
- Install the wiki as an app: Chrome → ⋮ → **Install app** (older versions call it *Add to
  home screen*, and only the WebAPK it creates carries the share target - a plain bookmark
  shortcut does not).

Then share anything to the wiki's icon. Two things are worth knowing when it misbehaves:

- **Chrome only registers share targets when the app is installed or updated.** After any
  change to the manifest - including enabling or disabling this plugin - uninstall the app
  and install it again, or the share sheet keeps showing the old registration.
- **Most apps put the link at the end of the shared text** rather than in the dedicated
  `url` field. quickjot pulls a trailing `http(s)` URL out into the link field so it ends up
  a real link on the note; a URL in the *middle* of the text is left where it is.

The manifest also gains a 192px and a 512px PNG icon, because Chrome will not install the
app without a raster icon that size, and core offers only a 16px favicon and possibly an
SVG. Icons the wiki already declares are left untouched.

Note that the shared text travels as a query parameter, so it appears in the browser's
history and in the server's access log. Saving redirects to a clean URL, but the compose
URL itself is not private.

## The API

Both calls are `POST` to `lib/exe/ajax.php`, authenticated with
`Authorization: Bearer <token>`. A session cookie is **not** accepted: requiring a header
that a cross-origin page cannot set is what keeps the endpoint safe from CSRF.

Both accept the timezone fields described under *Timezones*.

### `?call=plugin_quickjot_status`

No side effects. Answers with the user, the page a note would go to now, whether it
exists yet, and the time and zone it would be stamped with.

```json
{"ok":true,"user":"you","page":"appreciation:2026:07:2026-07-26","exists":false,
 "time":"14:32","timezone":"Europe/Berlin","url":"…"}
```

### `?call=plugin_quickjot`

Form-encoded body:

| field | required | meaning |
|---|---|---|
| `text` | yes | the note |
| `quote` | no | text to render as a `>` blockquote under the note |
| `url` | no | link to attach; must be `http`/`https` |
| `title` | no | link title, falls back to the URL |
| `tz` | no | IANA timezone name, e.g. `Europe/Berlin` |
| `tzoffset` | no | UTC offset, e.g. `+02:00` |

```json
{"ok":true,"page":"appreciation:2026:07:2026-07-26","created":true,
 "time":"14:32","timezone":"Europe/Berlin","url":"…"}
```

Failures answer `{"ok":false,"error":"…"}` with a matching status: 401 (no or bad token),
403 (ACL), 404 (page missing and creation disabled), 409 (page locked), 400 (bad input,
including an unusable timezone).

Example:

```sh
curl -s -X POST -H "Authorization: Bearer $TOKEN" \
     --data-urlencode 'text=testing the endpoint' \
     --data-urlencode 'url=https://example.com' \
     --data-urlencode 'title=Example' \
     --data-urlencode "tz=$(cat /etc/timezone)" \
     'https://wiki.example.com/lib/exe/ajax.php?call=plugin_quickjot'
```

## Timezones

A wiki on a UTC server would otherwise roll over to tomorrow's page in the middle of your
evening and stamp every note with the wrong time, so the *client* decides what "today"
means. Resolution order, first usable one wins:

1. `tz`, an IANA name — preferred, because it carries the DST rules.
2. `tzoffset`, a plain UTC offset — a fallback for a server whose timezone database does
   not know the name.
3. The `timezone` setting, for clients that send neither.
4. The server's own timezone.

A client that sends a timezone the server can make nothing of gets a **400** rather than a
note filed in the wrong zone. Sending nothing at all is fine and keeps steps 3 and 4.

Only the *zone* comes from the client; the instant is still the server's clock. A device
with a wrong clock therefore cannot misdate a note, and there is nothing to keep in sync.

The Firefox extension sends both fields on every call, so there is nothing to configure.
**Test connection** on its options page shows the resolved time and zone, which is the
quickest way to confirm this is working.

## The Firefox extension

### Development install

`about:debugging` → This Firefox → Load Temporary Add-on → pick `firefox/manifest.json`.
Gone at the next restart. Settings survive *reloads* only because the manifest pins
`browser_specific_settings.gecko.id`; without it every load would get a fresh storage area.

### Permanent install: sign it as unlisted

Firefox Release and Beta enforce add-on signing and ignore
`xpinstall.signatures.required`, so a permanent install means getting the package signed
by Mozilla. Unlisted signing does that without publishing: the add-on is never listed,
searchable, or given a public page. (Developer Edition, Nightly and ESR do honour that
preference and can install an unsigned build - but this repo assumes ordinary Firefox.)

Tooling lives in `firefox/package.json` and needs **Node 20 or newer** - current web-ext
does not run on Node 18, which is end-of-life anyway.

```sh
cd firefox
npm install
npm run lint     # AMO's own validator - run before every upload
npm run build    # -> web-ext-artifacts/quickjot-<version>.zip
```

The scripts pass `--ignore-files` on the command line rather than using a
`web-ext-config` file. Recent web-ext refuses a `.js` config and wants `.cjs` or `.mjs`,
and a config it silently ignores would quietly package the dev scaffolding - the CLI flags
have no such failure mode.

Then either upload that file at addons.mozilla.org with distribution set to **On your
own**, or let web-ext do the round trip:

```sh
export WEB_EXT_API_KEY=user:12345678:123
export WEB_EXT_API_SECRET=...          # from addons.mozilla.org → Developer Hub → Manage API Keys
npm run sign                           # --channel=unlisted, downloads the signed .xpi
```

Never commit those credentials; `.env` is gitignored, and passing them as environment
variables keeps them out of shell history files that record arguments.

Install the resulting signed `.xpi` via `about:addons` → gear → *Install Add-on From File*.

Things that will bite on submission:

* **Every upload needs a new `version`** in `manifest.json`. AMO rejects a version it has
  already seen, and there is no way to overwrite one.
* **The extension ID is permanent.** Once AMO has accepted a submission under
  `{72cee8b8-…}` that ID is spoken for; changing it later produces a different add-on that
  cannot inherit the old one's storage.
* **`optional_host_permissions: ["*://*/*"]` is deliberately broad** and may attract a
  human reviewer rather than only the automated one. It cannot be narrowed: the wiki URL
  is configured at runtime, so there is no host to declare up front. The extension asks
  for the single concrete origin at runtime from the options page.

Signed builds do not auto-update: bump `version`, rebuild, re-sign, reinstall. Self-hosted
updates are possible through `browser_specific_settings.gecko.update_url`, but that needs
somewhere to host an update manifest and is overkill for one user.

Once installed, open its options page, enter the wiki URL and the token, and press Save - Firefox will
ask for permission to reach that host, which the extension needs to make the request.
**Test connection** confirms the token works and shows the page notes will go to.

Then `Alt+Shift+N` (rebindable under `about:addons` → gear → Manage Extension Shortcuts)
or the toolbar button opens the note box. Type, and `Ctrl+Enter` saves. If the current tab
is a web page you can attach it as a link, and if you had text selected you can attach it
as a quote; both are pre-checked when available.

The extension sends the raw fields and lets the plugin build the wiki markup, so escaping
lives in exactly one place.

### Icons

`icon.svg` (dark ink) and `icon-light.svg` (light ink) are the sources; the `icon-*-NN.png`
files are generated from them and are what the toolbar actually uses. Regenerate with:

```sh
cd firefox
for size in 16 32 48; do
    inkscape icon.svg       -o icon-dark-$size.png  -w $size -h $size
    inkscape icon-light.svg -o icon-light-$size.png -w $size -h $size
done
```

Three things here are deliberate and easy to undo by accident:

* **Colours are presentation attributes, not CSS.** Firefox does not apply an SVG's
  `<style>` block when it rasterises a toolbar icon, so a colour that lives only in CSS
  silently falls back to the SVG default - stroked shapes disappear and filled ones turn
  black. The `prefers-color-scheme` rule in `icon.svg` is a bonus for contexts that do
  honour it (the about:addons listing), never what makes the icon visible.
* **`theme_icons` names refer to the theme's *text* colour.** `light` is the light-ink
  file, shown by dark themes. It reads backwards.
* **There is no `default_icon`.** Specifying one takes precedence over `theme_icons` under
  the Default theme, which follows the OS colour scheme - so setting it would put dark ink
  back on a dark toolbar for anyone not using an explicit theme.

## Tests

From the wiki's `_test/` directory, after `composer install` there:

```sh
./vendor/bin/phpunit --stderr --group plugin_quickjot
```
