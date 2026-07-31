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
                '',
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

        // useInput(false) throughout: left on, the elements prefill themselves straight
        // from the request and would overwrite everything decided above - normalizeShare()
        // in particular, whose whole job is to hand back something other than what came in
        $form->addTextarea('qj_text', $this->getLang('lbl_text'))
            ->useInput(false)
            ->val($values['text'])
            ->attr('rows', '6')
            ->attr('autofocus', 'autofocus');
        $form->addTextInput('qj_url', $this->getLang('lbl_url'))
            ->useInput(false)
            ->val($values['url'])
            ->attr('type', 'url');
        $form->addTextInput('qj_title', $this->getLang('lbl_title'))
            ->useInput(false)
            ->val($values['title']);
        $form->addButton('', $this->getLang('btn_save'))->attr('type', 'submit');

        return $form;
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
     * Sort the three shared fields into the three the plugin actually wants
     *
     * Android apps are inconsistent about which field a link goes in: most put the URL at
     * the end of "text" and the headline in "title", leaving "url" empty. Pulling that
     * trailing URL out means it becomes a proper link in the note instead of bare text
     * sitting in the middle of a sentence.
     *
     * Pure and static so the guessing can be tested without a request.
     *
     * @param string $title
     * @param string $text
     * @param string $url
     * @return array{title: string, text: string, url: string}
     */
    public static function normalizeShare($title, $text, $url)
    {
        $title = trim((string)$title);
        $text = trim((string)$text);
        $url = trim((string)$url);

        // only when the app did not tell us a URL itself - it knows better than we do
        if ($url === '' && $text !== '') {
            $words = preg_split('/\s+/', $text) ?: [];
            $last = (string)end($words);
            if (self::isHttpUrl($last)) {
                array_pop($words);
                $url = $last;
                // a share that was nothing but a link leaves the text empty, which is
                // the right thing: the user gets to type the note themselves
                $text = trim(implode(' ', $words));
            }
        }

        return ['title' => $title, 'text' => $text, 'url' => $url];
    }

    /**
     * Is this a plain http(s) URL?
     *
     * Conservative on purpose - a false negative just leaves the text alone, while a
     * false positive would tear a word off the end of somebody's note.
     *
     * @param string $candidate
     * @return bool
     */
    protected static function isHttpUrl($candidate)
    {
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

        // A share target only ever reaches the share sheet by way of an installed WebAPK,
        // and Chrome will not mint one without a raster icon of at least 192px - core
        // offers a 16px favicon and possibly an SVG, neither of which counts. These are
        // appended rather than substituted, so a wiki that already ships good icons keeps
        // them and simply gains two more candidates.
        if (!isset($event->data['icons']) || !is_array($event->data['icons'])) {
            $event->data['icons'] = [];
        }
        foreach ([192, 512] as $size) {
            $event->data['icons'][] = [
                'src' => DOKU_BASE . 'lib/plugins/quickjot/icon-' . $size . '.png',
                'sizes' => $size . 'x' . $size,
                'type' => 'image/png',
                'purpose' => 'any',
            ];
        }
    }
}
