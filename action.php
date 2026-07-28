<?php

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\JWT;
use dokuwiki\Logger;
use dokuwiki\plugin\quickjot\JournalPage;
use dokuwiki\plugin\quickjot\QuickJotException;

/**
 * quickjot: an authenticated endpoint for appending notes to today's journal page
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Michael Große <mic.grosse+dokuwiki@googlemail.com>
 */
class action_plugin_quickjot extends ActionPlugin
{
    public const CALL_ADD = 'plugin_quickjot';
    public const CALL_STATUS = 'plugin_quickjot_status';

    /** @inheritdoc */
    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjaxCall');
    }

    /**
     * Serve the two quickjot API calls
     *
     * @param Event $event
     * @param mixed $param
     * @return void
     */
    public function handleAjaxCall(Event $event, $param)
    {
        if ($event->data !== self::CALL_ADD && $event->data !== self::CALL_STATUS) return;
        $event->stopPropagation();
        $event->preventDefault();

        global $INPUT;
        header('Content-Type: application/json; charset=utf-8');

        try {
            $this->authenticate();
            $journal = new JournalPage($this);

            // the wiki's clock may well be in a different timezone than the device the
            // note is written on, and it is the latter that decides what "today" is
            $journal->useTimezone($INPUT->str('tz'), $INPUT->str('tzoffset'));

            if ($event->data === self::CALL_STATUS) {
                $now = $journal->moment();
                $id = $journal->getTodayId($now);
                $this->respond(200, [
                    'ok' => true,
                    'user' => $INPUT->server->str('REMOTE_USER'),
                    'page' => $id,
                    'exists' => page_exists($id),
                    'time' => $journal->renderTime($now),
                    'timezone' => $journal->timezone()->getName(),
                    'url' => wl($id, '', true),
                ]);
                return;
            }

            $result = $journal->addNote(
                $INPUT->post->str('text'),
                $INPUT->post->str('quote'),
                $INPUT->post->str('url'),
                $INPUT->post->str('title')
            );
            $this->respond(200, ['ok' => true] + $result);
        } catch (QuickJotException $e) {
            $this->respond($e->getStatus(), ['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Logger::error('quickjot: ' . $e->getMessage(), $e, $e->getFile(), $e->getLine());
            $this->respond(500, ['ok' => false, 'error' => 'Internal error, see the DokuWiki error log']);
        }
    }

    /**
     * Require that this request authenticated with an API token
     *
     * auth_setup() has already run by the time an AJAX call is dispatched, so a valid
     * Bearer token has logged the user in for us. It would also have accepted a session
     * cookie though, and this endpoint deliberately does not: requiring a header that a
     * cross-origin page cannot set is what makes it immune to CSRF.
     *
     * @throws QuickJotException
     * @return void
     */
    protected function authenticate()
    {
        global $INPUT;

        $token = $this->getToken();
        if ($token === '') {
            throw new QuickJotException($this->getLang('err_notoken'), 401);
        }

        try {
            $jwt = JWT::validate($token);
        } catch (\Exception) {
            throw new QuickJotException($this->getLang('err_badtoken'), 401);
        }

        if ($jwt->getUser() === '' || $jwt->getUser() !== $INPUT->server->str('REMOTE_USER')) {
            throw new QuickJotException($this->getLang('err_badtoken'), 401);
        }
    }

    /**
     * Read the API token off the request headers
     *
     * Accepts the same two headers auth_tokenlogin() does.
     *
     * @return string empty when no token was supplied
     */
    protected function getToken()
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) $headers = array_change_key_case($headers);
        }
        if (!$headers) {
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $headers[strtolower(strtr(substr($key, 5), '_', '-'))] = $value;
                }
            }
        }

        if (!empty($headers['x-dokuwiki-token'])) {
            return trim($headers['x-dokuwiki-token']);
        }
        if (!empty($headers['authorization'])) {
            [$type, $token] = sexplode(' ', $headers['authorization'], 2, '');
            if ($type === 'Bearer') return trim($token);
        }

        return '';
    }

    /**
     * Send a JSON response with the given status
     *
     * @param int $status HTTP status code
     * @param array $data payload
     * @return void
     */
    protected function respond($status, array $data)
    {
        http_status($status);
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
