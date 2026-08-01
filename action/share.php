<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Form\Form;
use dokuwiki\Logger;
use dokuwiki\plugin\quickjot\JournalPage;
use dokuwiki\plugin\quickjot\QuickJotException;

/**
 * quickjot: a compose form reachable from Android's share sheet
 *
 * This is a deliberately separate component from action.php. That one serves the AJAX
 * endpoint, whose defence against CSRF is that it accepts *only* a bearer token in a
 * header - something a cross-origin page cannot set. This one is the opposite world:
 * an ordinary server rendered page authenticated by the session cookie and guarded by
 * a form token. Mixing the two into one class is how the header-only rule would end up
 * relaxed by accident, so they stay apart.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Michael Große <mic.grosse+dokuwiki@googlemail.com>
 */
class action_plugin_quickjot_share extends ActionPlugin
{
    /** the $ACT this component answers to */
    public const ACT = 'quickjot';

    /** @inheritdoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handlePreprocess');
        $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, 'handleDisplay');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleHeader');
        $controller->register_hook('MANIFEST_SEND', 'BEFORE', $this, 'handleManifest');
    }

    /**
     * Claim the quickjot action and, on a submitted form, write the note
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handlePreprocess(Event $event, $param)
    {
        if (act_clean($event->data) !== self::ACT) return;

        global $INPUT;

        // checkSecurityToken() returns true for anonymous requests (inc/common.php), so
        // requiring a login is not merely about permissions - it is what gives the token
        // check below anything to check. It has to come first.
        if (!$INPUT->server->str('REMOTE_USER')) {
            // hand over to the login form. Whatever was shared is dropped, but it came
            // from another app and re-sharing after logging in costs one tap.
            $event->data = 'login';
            return;
        }

        // from here on the action is ours: preventing the default makes the router hand
        // us a dokuwiki\Action\Plugin, which fires TPL_ACT_UNKNOWN for handleDisplay()
        $event->preventDefault();

        if ($this->isSaveRequest()) $this->save();
    }

    /**
     * Was this request the compose form being submitted?
     *
     * @return bool
     */
    protected function isSaveRequest()
    {
        global $INPUT;
        return $INPUT->post->bool('quickjot_save');
    }

    /**
     * Write the submitted note, then redirect
     *
     * Errors deliberately do not redirect: falling through leaves the POST data in place
     * so handleDisplay() can re-render the form with what the user typed, rather than
     * throwing the note away along with the error.
     *
     * @return void
     */
    protected function save()
    {
        global $INPUT;

        // checkSecurityToken() emits its own message on failure
        if (!checkSecurityToken()) return;

        try {
            $journal = new JournalPage($this);
            // the phone decides what "today" is, not the server the wiki runs on
            $journal->useTimezone($INPUT->post->str('tz'), $INPUT->post->str('tzoffset'));

            $result = $journal->addNote(
                $INPUT->post->str('qj_text'),
                $INPUT->post->str('qj_quote'),
                $INPUT->post->str('qj_url'),
                $INPUT->post->str('qj_title')
            );
        } catch (QuickJotException $e) {
            msg(hsc($e->getMessage()), -1);
            return;
        } catch (\Throwable $e) {
            Logger::error('quickjot: ' . $e->getMessage(), $e, $e->getFile(), $e->getLine());
            msg($this->getLang('err_internal'), -1);
            return;
        }

        // Post/Redirect/Get, so that pulling to reload the page cannot append the note
        // a second time
        send_redirect(DOKU_URL . DOKU_SCRIPT . '?' . buildURLparams([
            'do' => self::ACT,
            'qj_saved' => $result['page'],
        ], '&'));
    }

    /**
     * Render the compose form
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleDisplay(Event $event, $param)
    {
        if ($event->data !== self::ACT) return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT;

        echo '<div class="plugin_quickjot_share">';
        echo '<h1>' . hsc($this->getLang('share_title')) . '</h1>';

        $saved = cleanID($INPUT->str('qj_saved'));
        if ($saved !== '') {
            // the format string is escaped, the substitution is already safe markup
            echo '<p>' . sprintf(hsc($this->getLang('saved_ok')), html_wikilink($saved)) . '</p>';
        }

        echo $this->buildForm()->toHTML();
        echo $this->timezoneScript();
        echo '</div>';
    }

    /**
     * The compose form, prefilled with whatever we know
     *
     * @return Form
     */
    protected function buildForm()
    {
        global $INPUT;

        if ($this->isSaveRequest()) {
            // re-render after a failed save: give back exactly what was submitted
            $values = [
                'title' => $INPUT->post->str('qj_title'),
                'text' => $INPUT->post->str('qj_text'),
                'quote' => $INPUT->post->str('qj_quote'),
                'url' => $INPUT->post->str('qj_url'),
            ];
        } else {
            $values = self::normalizeShare(
                $INPUT->get->str('qj_title'),
                $INPUT->get->str('qj_text'),
                $INPUT->get->str('qj_url')
            );
        }

        // the action has to be spelled out: Form's default bakes the current $_GET into
        // it, which here would round-trip the shared text through the POST URL as well
        $form = new Form([
            'action' => DOKU_BASE . DOKU_SCRIPT,
            'method' => 'post',
            'id' => 'plugin__quickjot_share_form',
        ]);

        $form->setHiddenField('do', self::ACT);
        $form->setHiddenField('quickjot_save', '1');
        // filled in by the script below; empty means "server decides"
        $form->setHiddenField('tz', '');
        $form->setHiddenField('tzoffset', '');

        $this->addSaveButton($form);

        // useInput(false) throughout: left on, the elements prefill themselves straight
        // from the request and would overwrite everything decided above - normalizeShare()
        // in particular, whose whole job is to hand back something other than what came in
        $form->addTextarea('qj_text', $this->getLang('lbl_text'))
            ->useInput(false)
            ->val($values['text'])
            ->attr('rows', '4')
            ->attr('autofocus', 'autofocus');
        // below the note, because the note is what the user came here to write - the quote
        // is already filled in and only wants reading over
        $form->addTextarea('qj_quote', $this->getLang('lbl_quote'))
            ->useInput(false)
            ->val($values['quote'])
            ->attr('rows', '4');
        $form->addTextInput('qj_url', $this->getLang('lbl_url'))
            ->useInput(false)
            ->val($values['url'])
            ->attr('type', 'url');
        $form->addTextInput('qj_title', $this->getLang('lbl_title'))
            ->useInput(false)
            ->val($values['title']);
        $this->addSaveButton($form);

        return $form;
    }

    /**
     * Add a save button to the form
     *
     * Called twice, so the form is bracketed by one. The note field takes focus by
     * itself, which on a phone raises the on screen keyboard over the bottom half of the
     * screen - and with it the button at the foot of the form, so saving a one line note
     * meant dismissing the keyboard first just to reach the thing that saves. The copy
     * above the fields is in view from the moment the form opens.
     *
     * Which one is pressed makes no difference: the form is recognised by its
     * quickjot_save hidden field, not by the button, so neither carries a name of its own.
     *
     * @param Form $form
     * @return void
     */
    protected function addSaveButton(Form $form)
    {
        $form->addButton('', $this->getLang('btn_save'))->attr('type', 'submit');
    }

    /**
     * Add the compose form's stylesheet to the page head
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleHeader(Event $event, $param)
    {
        global $ACT;
        if (act_clean($ACT) !== self::ACT) return;

        $event->data['style'][] = [
            'type' => 'text/css',
            '_data' => $this->styleSheet(),
        ];
    }

    /**
     * The compose form's stylesheet, laying it out as a single column
     *
     * Inline for the same reason as the script below - a style.css would be concatenated
     * into the CSS every wiki page loads, for the sake of one form almost nobody visits -
     * but in the head rather than beside the form, so the phone never paints the jumble
     * described below before the rules arrive.
     *
     * Form wraps each field in a <label> holding a <span> caption and the control, and
     * gives that label no class of its own - so it stays inline, and all four fields run
     * together into one wrapping line with the captions stranded wherever the line
     * happens to break. (DokuWiki's own forms avoid this by calling addClass('block') on
     * every element, which the default template then right-aligns; that is tidier but
     * still leaves each control at its natural width, and is a rule only that template
     * carries.) Stacking the caption above a full-width control instead gives every field
     * the same two edges under any template, and is the shape a phone screen wants anyway.
     *
     * @return string
     */
    protected function styleSheet()
    {
        return <<<'CSS'
.plugin_quickjot_share form {
    display: block;
    max-width: 40em;
}
.plugin_quickjot_share form label {
    display: block;
    text-align: left;
    margin: 0 0 1em 0;
}
.plugin_quickjot_share form label > span {
    display: block;
    margin-bottom: 0.2em;
}
.plugin_quickjot_share form label input,
.plugin_quickjot_share form label textarea {
    display: block;
    /* !important only to out-shout the default template's phone stylesheet, which forces
       every textarea back to width:auto with an !important of its own */
    width: 100% !important;
    max-width: 100% !important;
    box-sizing: border-box;
}
.plugin_quickjot_share button {
    padding: 0.5em 1.5em;
}
/* the copy above the fields, which needs air between it and the first caption. The
   hidden inputs come first in the form, so this cannot be :first-child */
.plugin_quickjot_share form button:first-of-type {
    margin-bottom: 1em;
}
CSS;
    }

    /**
     * Inline script filling the timezone fields from the browser
     *
     * Inline on purpose: the plugin's script.js would be concatenated into the JS every
     * wiki page loads, for the sake of one form almost nobody visits. Mirrors
     * firefox/api.js - the IANA name is the accurate one, the offset is the fallback for
     * a server whose timezone database does not know the name.
     *
     * @return string
     */
    protected function timezoneScript()
    {
        return <<<'HTML'
<script>
(function () {
    var form = document.getElementById('plugin__quickjot_share_form');
    if (!form) return;
    try {
        var name = '';
        try {
            name = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (e) {
            // no Intl data, the offset alone will do
        }
        // getTimezoneOffset() counts minutes behind UTC, ISO 8601 counts them ahead
        var minutes = -new Date().getTimezoneOffset();
        var pad = function (n) {
            return String(Math.floor(Math.abs(n))).padStart(2, '0');
        };
        form.elements.tz.value = name;
        form.elements.tzoffset.value =
            (minutes < 0 ? '-' : '+') + pad(minutes / 60) + ':' + pad(minutes % 60);
    } catch (e) {
        // leaving both empty falls back to the wiki's configured zone
    }
})();
</script>
HTML;
    }

    /**
     * Query parameters share.php should redirect the browser on to
     *
     * The shared fields are renamed to a qj_ prefix, which keeps text a user shared from
     * some other app away from the parameters core inspects itself ("id", "do", "rev",
     * ...). A note that happened to read "id=start" would otherwise decide which page the
     * wiki thinks it is on. Empty fields are dropped so they do not clutter the URL.
     *
     * @param string $title
     * @param string $text
     * @param string $url
     * @return array
     */
    public static function shareParams($title, $text, $url)
    {
        $params = ['do' => self::ACT];
        foreach (['title' => $title, 'text' => $text, 'url' => $url] as $field => $value) {
            if (trim((string)$value) !== '') $params['qj_' . $field] = $value;
        }
        return $params;
    }

    /**
     * Sort the shared fields into the fields of the compose form
     *
     * Whatever arrives through the share sheet was written by somebody else - it is the
     * passage the user highlighted, or the page they were reading. So it becomes the
     * quote, and the note itself stays empty for the user's own words. Both are editable,
     * so a share that was really meant as a plain thought is one cut and paste away.
     *
     * Sharing a bare link is the one case where the URL arrives as the text, because
     * there is nothing else to send. A link *inside* a longer selection is left where it
     * is: it belongs to the passage, and promoting it would both cut it out of the quote
     * and claim it was the source of the share, which it was not. DokuWiki renders a bare
     * URL in wiki text as a link anyway.
     *
     * Pure and static so the guessing can be tested without a request.
     *
     * @param string $title
     * @param string $text
     * @param string $url
     * @return array{title: string, text: string, quote: string, url: string}
     */
    public static function normalizeShare($title, $text, $url)
    {
        $title = trim((string)$title);
        $text = trim((string)$text);
        $url = trim((string)$url);

        // only when the app did not name a URL itself - it knows better than we do
        if ($url === '' && self::isHttpUrl($text)) {
            $url = $text;
            $text = '';
        }

        return ['title' => $title, 'text' => '', 'quote' => $text, 'url' => $url];
    }

    /**
     * Is this string nothing but a plain http(s) URL?
     *
     * Deliberately whole-string: a value carrying anything besides the URL is a passage
     * that happens to contain a link, not a link.
     *
     * @param string $candidate
     * @return bool
     */
    protected static function isHttpUrl($candidate)
    {
        if ($candidate === '' || preg_match('/\s/', $candidate)) return false;
        if (!preg_match('#^https?://#i', $candidate)) return false;
        $host = parse_url($candidate, PHP_URL_HOST);
        return is_string($host) && $host !== '';
    }

    /**
     * Advertise the share target in the web app manifest
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleManifest(Event $event, $param)
    {
        $event->data['share_target'] = [
            'action' => DOKU_BASE . 'lib/plugins/quickjot/share.php',
            'method' => 'GET',
            'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url'],
        ];

        // Long-pressing the installed app offers this, which is the way in for a thought
        // that did not come from anywhere - no other app to share from, so the share sheet
        // cannot help. Appended, so a wiki declaring its own shortcuts keeps them.
        if (!isset($event->data['shortcuts']) || !is_array($event->data['shortcuts'])) {
            $event->data['shortcuts'] = [];
        }
        $event->data['shortcuts'][] = [
            'name' => $this->getLang('shortcut_new'),
            'short_name' => $this->getLang('shortcut_new_short'),
            'description' => $this->getLang('shortcut_new_desc'),
            'url' => DOKU_BASE . DOKU_SCRIPT . '?do=' . self::ACT,
            // a shortcuts entry carries its own icons, which is the one place a notepad
            // belongs: it labels this shortcut and nothing else. The manifest's top level
            // "icons" is deliberately left alone - that is the whole app's identity, its
            // launcher icon and splash screen and its face in the share sheet, and it is
            // the wiki's to decide, not a note plugin's. See README on what to do when the
            // app will not install for want of one.
            'icons' => [
                [
                    'src' => DOKU_BASE . 'lib/plugins/quickjot/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => DOKU_BASE . 'lib/plugins/quickjot/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                ],
            ],
        ];
    }
}
