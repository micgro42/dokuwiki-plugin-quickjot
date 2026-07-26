<?php

namespace dokuwiki\plugin\quickjot\test;

use dokuwiki\Ajax;
use dokuwiki\Input\Input;
use dokuwiki\JWT;
use DokuWikiTest;

/**
 * End to end tests for the quickjot AJAX endpoint
 *
 * These drive the AJAX dispatcher directly rather than through TestRequest, because
 * lib/exe/ajax.php pulls in inc/init.php with require_once - already satisfied by the
 * test bootstrap, so a sub-request would never re-run auth_setup() and could not
 * authenticate at all.
 *
 * @group plugin_quickjot
 * @group plugins
 */
class AjaxTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['quickjot'];

    /** @var string a valid token for testuser */
    protected $token;

    public function setUp(): void
    {
        parent::setUp();

        global $conf;
        $conf['useacl'] = 1;
        $conf['plugin']['quickjot']['pagepattern'] = 'quickjot:test';

        $this->token = JWT::fromUser('testuser')->getToken();

        // pages survive between tests of the same class, start each one clean
        foreach (['quickjot:test', 'private:journal'] as $id) {
            @unlink(wikiFN($id));
            @unlink(wikiLockFN($id));
        }
    }

    public function tearDown(): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_POST = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    /**
     * Re-bind the plugin to the current $conf
     *
     * Plugin instances outlive a single test while DokuWikiTest replaces $conf wholesale,
     * so getConf() would otherwise keep reading the array a previous test bound it to.
     */
    protected function reloadPluginConfig()
    {
        $plugin = plugin_load('action', 'quickjot');
        $this->assertNotNull($plugin, 'the quickjot plugin is not loaded');
        $plugin->loadConfig();
    }

    /**
     * Fire a call at the AJAX dispatcher and decode the answer
     *
     * @param string $call the ajax call name
     * @param array $post post data
     * @param string|null $token token to send, null to send no token header
     * @return array the decoded JSON response
     */
    protected function callApi($call, array $post = [], $token = null)
    {
        global $INPUT;

        if ($token === null) {
            unset($_SERVER['HTTP_AUTHORIZATION']);
        } else {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $_POST = $post;
        $_GET = [];
        $_REQUEST = $post;
        $INPUT = new Input();
        $this->reloadPluginConfig();

        // let the real auth stack decide who, if anyone, is logged in
        $_SESSION = [];
        auth_setup();

        ob_start();
        new Ajax($call);
        $out = ob_get_clean();

        $json = json_decode($out, true);
        $this->assertIsArray($json, 'endpoint did not answer with JSON: ' . $out);
        return $json;
    }

    public function testMissingTokenIsRejected()
    {
        $json = $this->callApi('plugin_quickjot', ['text' => 'nope']);

        $this->assertFalse($json['ok']);
        $this->assertFalse(page_exists('quickjot:test'), 'nothing may be written without a token');
    }

    public function testInvalidTokenIsRejected()
    {
        $json = $this->callApi('plugin_quickjot', ['text' => 'nope'], 'not.a.token');

        $this->assertFalse($json['ok']);
        $this->assertFalse(page_exists('quickjot:test'));
    }

    public function testTokenOfAnotherUserIsRejected()
    {
        // a syntactically valid token that was never issued for a real user
        $forged = JWT::fromUser('nosuchuser')->getToken();
        $json = $this->callApi('plugin_quickjot', ['text' => 'nope'], $forged);

        $this->assertFalse($json['ok']);
        $this->assertFalse(page_exists('quickjot:test'));
    }

    public function testSessionAloneIsRejected()
    {
        // a logged in browser session, but no token header: must not be enough
        global $INPUT;
        $_POST = ['text' => 'nope'];
        $_REQUEST = $_POST;
        $INPUT = new Input();
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $INPUT->server->set('REMOTE_USER', 'testuser');
        $this->reloadPluginConfig();

        ob_start();
        new Ajax('plugin_quickjot');
        $json = json_decode(ob_get_clean(), true);

        $this->assertIsArray($json);
        $this->assertFalse($json['ok']);
        $this->assertFalse(page_exists('quickjot:test'), 'cookie auth alone must not be able to write');
    }

    public function testStatusReportsTargetPage()
    {
        $json = $this->callApi('plugin_quickjot_status', [], $this->token);

        $this->assertTrue($json['ok'], $json['error'] ?? '');
        $this->assertSame('testuser', $json['user']);
        $this->assertSame('quickjot:test', $json['page']);
        $this->assertFalse($json['exists']);
    }

    public function testAddNoteCreatesPage()
    {
        $json = $this->callApi('plugin_quickjot', ['text' => 'first note'], $this->token);

        $this->assertTrue($json['ok'], $json['error'] ?? '');
        $this->assertTrue($json['created']);
        $this->assertSame('quickjot:test', $json['page']);

        $text = rawWiki('quickjot:test');
        $this->assertStringContainsString('===== Notes =====', $text);
        $this->assertMatchesRegularExpression('/^  \* \*\*\d\d:\d\d\*\* first note$/m', $text);
    }

    public function testSecondNoteAppendsInsideSection()
    {
        saveWikiText(
            'quickjot:test',
            "====== Day ======\n\nprose\n\n===== Notes =====\n\n  * older\n\n====== Tail ======\n\nend\n",
            'setup'
        );

        $json = $this->callApi('plugin_quickjot', ['text' => 'newer'], $this->token);
        $this->assertTrue($json['ok'], $json['error'] ?? '');
        $this->assertFalse($json['created']);

        $text = rawWiki('quickjot:test');
        $notePos = strpos($text, 'newer');
        $this->assertNotFalse($notePos);
        $this->assertGreaterThan(strpos($text, '  * older'), $notePos, 'note must follow the existing one');
        $this->assertLessThan(strpos($text, '====== Tail ======'), $notePos, 'note must stay inside the section');
        $this->assertStringContainsString("end\n", $text, 'the rest of the page must survive');
    }

    public function testAddNoteWithLinkAndQuote()
    {
        $json = $this->callApi('plugin_quickjot', [
            'text' => 'worth reading',
            'quote' => 'the interesting part',
            'url' => 'https://example.com/article',
            'title' => 'An Article',
        ], $this->token);

        $this->assertTrue($json['ok'], $json['error'] ?? '');

        $text = rawWiki('quickjot:test');
        $this->assertStringContainsString('[[https://example.com/article|An Article]]', $text);
        $this->assertStringContainsString('> the interesting part', $text);
    }

    public function testEmptyTextIsRejected()
    {
        $json = $this->callApi('plugin_quickjot', ['text' => '  '], $this->token);

        $this->assertFalse($json['ok']);
        $this->assertFalse(page_exists('quickjot:test'));
    }

    public function testNonHttpUrlIsRejected()
    {
        $json = $this->callApi('plugin_quickjot', [
            'text' => 'x',
            'url' => 'javascript:alert(1)',
        ], $this->token);

        $this->assertFalse($json['ok']);
        $this->assertFalse(page_exists('quickjot:test'));
    }

    public function testWriteDeniedByAcl()
    {
        global $conf, $auth;

        // testuser is the configured superuser and would sail past any ACL, so this
        // needs an ordinary account. Note $conf['superuser'] cannot simply be blanked:
        // auth_ismanager() memoises its answer in a static that outlives the test.
        $auth->createUser('jotuser', 'jotpass', 'Jot User', 'jot@example.com');

        $conf['plugin']['quickjot']['pagepattern'] = 'private:journal';

        // the shipped test ACL denies @ALL everything below private:
        $json = $this->callApi('plugin_quickjot', ['text' => 'nope'], JWT::fromUser('jotuser')->getToken());

        $this->assertFalse($json['ok']);
        $this->assertFalse(page_exists('private:journal'));
    }

    public function testOrdinaryUserMayStillWriteElsewhere()
    {
        global $auth;
        $auth->createUser('jotuser2', 'jotpass', 'Jot User', 'jot2@example.com');

        $json = $this->callApi('plugin_quickjot', ['text' => 'allowed'], JWT::fromUser('jotuser2')->getToken());

        $this->assertTrue($json['ok'], $json['error'] ?? '');
        $this->assertStringContainsString('allowed', rawWiki('quickjot:test'));
    }

    public function testLockedPageIsReported()
    {
        saveWikiText('quickjot:test', "===== Notes =====\n\n  * older\n", 'setup');
        io_saveFile(wikiLockFN('quickjot:test'), 'someoneelse');

        $json = $this->callApi('plugin_quickjot', ['text' => 'nope'], $this->token);

        $this->assertFalse($json['ok']);
        $this->assertStringNotContainsString('nope', rawWiki('quickjot:test'));
    }

    public function testCreationCanBeDisabled()
    {
        global $conf;
        $conf['plugin']['quickjot']['createpage'] = 0;

        $json = $this->callApi('plugin_quickjot', ['text' => 'nope'], $this->token);

        $this->assertFalse($json['ok']);
        $this->assertFalse(page_exists('quickjot:test'));
    }

    public function testUnknownCallIsUntouched()
    {
        ob_start();
        new Ajax('plugin_quickjot_nonesuch');
        $out = ob_get_clean();

        $this->assertStringContainsString('unknown', $out);
    }
}
