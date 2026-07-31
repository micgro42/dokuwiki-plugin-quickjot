<?php

/**
 * Landing endpoint for Android's system share sheet
 *
 * A Web Share Target declared with method GET has the browser *replace* the query string
 * of the action URL with the shared fields, so the action cannot simply be
 * "doku.php?do=quickjot" - the "do" would be thrown away before DokuWiki ever saw it.
 * This bounces to the real action instead. The renaming of the shared fields that goes
 * with it lives in action_plugin_quickjot_share::shareParams(), where it can be tested.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Michael Große <mic.grosse+dokuwiki@googlemail.com>
 */

// phpcs:disable PSR1.Files.SideEffects
if (!defined('DOKU_INC')) define('DOKU_INC', __DIR__ . '/../../../');
require_once(DOKU_INC . 'inc/init.php');

// nothing here reads or writes the session, so do not hold it open across the redirect
session_write_close();

// an installed PWA keeps sending shares here after the plugin is disabled, and the
// autoloader will not hand out the class of a disabled plugin - answer with the wiki
// rather than a fatal error
if (!class_exists('action_plugin_quickjot_share')) send_redirect(DOKU_URL);

$params = action_plugin_quickjot_share::shareParams(
    $INPUT->get->str('title'),
    $INPUT->get->str('text'),
    $INPUT->get->str('url')
);

send_redirect(DOKU_URL . DOKU_SCRIPT . '?' . buildURLparams($params, '&'));
