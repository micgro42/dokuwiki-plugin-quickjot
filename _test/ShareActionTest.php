<?php

namespace dokuwiki\plugin\quickjot\test;

use action_plugin_quickjot_share;
use dokuwiki\Extension\Event;
use DokuWikiTest;
use TestRequest;

/**
 * Tests for the share sheet compose form
 *
 * The counterpart to AjaxTest, which covers the token authenticated endpoint. These two
 * reach the same JournalPage through deliberately different front doors, and the tests
 * are kept apart for the same reason the code is.
 *
 * @group plugin_quickjot
 * @group plugins
 */
class ShareActionTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['quickjot'];

    public function setUp(): void
    {
        parent::setUp();

        global $conf;
        $conf['useacl'] = 1;
        $conf['plugin']['quickjot']['pagepattern'] = 'quickjot:test';

        foreach (['quickjot:test', 'private:journal'] as $id) {
            @unlink(wikiFN($id));
            @unlink(wikiLockFN($id));
        }
    }

    /**
     * A request to the compose form, as the given user
     *
     * @param string|null $user null for an anonymous visitor
     * @param array $get query parameters besides do=quickjot
     * @param array|null $post post data, null for a GET request
     * @return \TestResponse
     */
    protected function request($user, array $get = [], ?array $post = null)
    {
        $request = new TestRequest();
        if ($user !== null) $request->setServer('REMOTE_USER', $user);

        // the plugin reads its config through the instance the controller cached, which
        // outlives the $conf array DokuWikiTest replaces for every test
        $plugin = plugin_load('action', 'quickjot_share');
        $this->assertNotNull($plugin, 'the quickjot share component is not loaded');
        $plugin->loadConfig();

        $get['do'] = 'quickjot';
        if ($post === null) return $request->get($get, '/doku.php');

        foreach ($get as $key => $value) {
            $request->setGet($key, $value);
        }
        return $request->post($post, '/doku.php');
    }

    /**
     * A security token valid for the given user in this process
     *
     * getSecurityToken() derives the token from the logged in user, so it has to be
     * computed while that user appears to be logged in - without leaving REMOTE_USER
     * behind in the globals the rest of the test relies on.
     *
     * @param string $user
     * @return string
     */
    protected function tokenFor($user)
    {
        global $INPUT;
        $oldServer = $_SERVER;
        $oldInput = $INPUT;

        $_SERVER['REMOTE_USER'] = $user;
        $INPUT = new \dokuwiki\Input\Input();
        $token = getSecurityToken();

        $_SERVER = $oldServer;
        $INPUT = $oldInput;
        return $token;
    }

    /**
     * The value a field of the compose form came back with
     *
     * A textarea's content starts with the newline that follows the opening tag, which is
     * markup rather than part of the value.
     *
     * @param \TestResponse $response
     * @param string $name
     * @return string
     */
    protected function fieldValue($response, $name)
    {
        $textarea = $response->queryHTML('#plugin__quickjot_share_form textarea[name=' . $name . ']');
        if ($textarea->count()) return ltrim($textarea->text(), "\n");

        return (string)$response
            ->queryHTML('#plugin__quickjot_share_form input[name=' . $name . ']')
            ->attr('value');
    }

    /**
     * The URLs the request redirected to
     *
     * send_redirect() collects rather than exits under DOKU_UNITTEST, so this is a list.
     *
     * @param \TestResponse $response
     * @return array
     */
    protected function redirects($response)
    {
        return $response->getData('send_redirect');
    }

    public function testAnonymousVisitorGetsTheLoginForm()
    {
        $response = $this->request(null);

        $this->assertSame(1, $response->queryHTML('#dw__login')->count(), 'expected the login form');
        $this->assertSame(0, $response->queryHTML('#plugin__quickjot_share_form')->count());
    }

    public function testLoggedInUserGetsTheForm()
    {
        $response = $this->request('testuser');

        $form = $response->queryHTML('#plugin__quickjot_share_form');
        $this->assertSame(1, $form->count(), 'expected the compose form');
        $this->assertSame(
            1,
            $response->queryHTML('#plugin__quickjot_share_form input[name=sectok]')->count(),
            'the form must carry a security token'
        );
        $this->assertSame(
            1,
            $response->queryHTML('#plugin__quickjot_share_form input[name=tz]')->count(),
            'the timezone field the inline script fills must exist'
        );
    }

    /**
     * The phone's keyboard covers the foot of the form as soon as the note field takes
     * focus, so there has to be a save button above the fields as well as below them.
     */
    public function testFormIsBracketedBySaveButtons()
    {
        $response = $this->request('testuser');

        $buttons = $response->queryHTML('#plugin__quickjot_share_form button');
        $this->assertSame(2, $buttons->count(), 'one save button above the fields, one below');

        // whichever is pressed, the form is recognised by its hidden field rather than
        // the button, so both have to submit
        foreach ($buttons as $button) {
            $this->assertSame('submit', $button->getAttribute('type'));
        }

        // the first one has to come before the note field, or it is no help at all
        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'name="qj_text"'),
            strpos($html, '<button'),
            'the first save button must precede the note field'
        );
    }

    public function testSharedTextBecomesTheQuoteAndLeavesTheNoteEmpty()
    {
        $response = $this->request('testuser', [
            'qj_text' => 'the passage I highlighted',
            'qj_url' => 'https://example.com/article',
            'qj_title' => 'An Article',
        ]);

        $this->assertSame(
            '',
            $this->fieldValue($response, 'qj_text'),
            'the note is for the user\'s own words'
        );
        $this->assertSame('the passage I highlighted', $this->fieldValue($response, 'qj_quote'));
        $this->assertSame('https://example.com/article', $this->fieldValue($response, 'qj_url'));
        $this->assertSame('An Article', $this->fieldValue($response, 'qj_title'));
    }

    public function testABareLinkShareBecomesTheLink()
    {
        // sharing a page from the browser: the URL arrives as the text, nothing else
        $response = $this->request('testuser', [
            'qj_text' => 'https://example.com/article',
            'qj_title' => 'An Article',
        ]);

        $this->assertSame('https://example.com/article', $this->fieldValue($response, 'qj_url'));
        $this->assertSame('', $this->fieldValue($response, 'qj_quote'));
        $this->assertSame('', $this->fieldValue($response, 'qj_text'));
    }

    /**
     * A link inside a highlighted passage belongs to the passage. Promoting it would cut
     * it out of the quote and claim it was the page the share came from.
     */
    public function testALinkInsideSharedTextStaysInTheQuote()
    {
        $response = $this->request('testuser', [
            'qj_text' => 'they cite https://example.com/source for this',
            'qj_url' => 'https://example.com/article',
        ]);

        $this->assertSame(
            'they cite https://example.com/source for this',
            $this->fieldValue($response, 'qj_quote')
        );
        $this->assertSame(
            'https://example.com/article',
            $this->fieldValue($response, 'qj_url'),
            'the link field must still be the page that was shared from'
        );
    }

    public function testATrailingLinkIsNotTornOffTheQuote()
    {
        $response = $this->request('testuser', [
            'qj_text' => 'worth reading https://example.com/source',
        ]);

        $this->assertSame(
            'worth reading https://example.com/source',
            $this->fieldValue($response, 'qj_quote')
        );
        $this->assertSame('', $this->fieldValue($response, 'qj_url'));
    }

    public function testTheFormDoesNotPostBackToTheSharedUrl()
    {
        // Form's default action bakes $_GET in; here that would round-trip the shared
        // text through the POST URL as well as the body
        $response = $this->request('testuser', ['qj_text' => 'secret thought']);

        $action = $response->queryHTML('#plugin__quickjot_share_form')->attr('action');
        $this->assertStringNotContainsString('secret', $action);
        $this->assertStringNotContainsString('qj_text', $action);
    }

    public function testValidPostSavesTheNoteAndRedirects()
    {
        $response = $this->request('testuser', [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'sectok' => $this->tokenFor('testuser'),
            'qj_text' => 'a shared note',
            'qj_url' => 'https://example.com/article',
            'qj_title' => 'An Article',
            'tz' => 'Europe/Berlin',
        ]);

        $text = rawWiki('quickjot:test');
        $this->assertStringContainsString('a shared note', $text);
        $this->assertStringContainsString('[[https://example.com/article|An Article]]', $text);

        $redirects = $this->redirects($response);
        $this->assertCount(1, $redirects, 'a successful save must redirect exactly once');
        $this->assertStringContainsString('qj_saved=quickjot%3Atest', $redirects[0]);
    }

    public function testQuoteIsSavedUnderTheNote()
    {
        $this->request('testuser', [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'sectok' => $this->tokenFor('testuser'),
            'qj_text' => 'my own thought',
            'qj_quote' => 'the passage I highlighted',
            'qj_url' => 'https://example.com/article',
        ]);

        $text = rawWiki('quickjot:test');
        $this->assertStringContainsString('my own thought', $text);
        $this->assertStringContainsString('> the passage I highlighted', $text);
        $this->assertLessThan(
            strpos($text, '> the passage I highlighted'),
            strpos($text, 'my own thought'),
            'the quote belongs under the note'
        );
    }

    /**
     * Sharing a passage worth keeping is a complete thought; commentary is optional.
     */
    public function testAQuoteAloneCanBeSaved()
    {
        $response = $this->request('testuser', [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'sectok' => $this->tokenFor('testuser'),
            'qj_text' => '',
            'qj_quote' => 'a passage on its own',
            'qj_url' => 'https://example.com/article',
        ]);

        $this->assertCount(1, $this->redirects($response), 'a quote alone must be saveable');
        $text = rawWiki('quickjot:test');
        $this->assertStringContainsString('> a passage on its own', $text);
        $this->assertStringContainsString('[[https://example.com/article|', $text);
        $this->assertDoesNotMatchRegularExpression(
            '/\*\* +$/m',
            $text,
            'a note with no words must not leave a dangling timestamp'
        );
    }

    public function testAnEmptyFormSavesNothing()
    {
        $response = $this->request('testuser', [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'sectok' => $this->tokenFor('testuser'),
            'qj_text' => '',
            'qj_quote' => '',
        ]);

        $this->assertFalse(page_exists('quickjot:test'));
        $this->assertSame([], $this->redirects($response));
    }

    public function testFailedSaveKeepsTheQuoteToo()
    {
        $response = $this->request('testuser', [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'sectok' => 'not-the-token',
            'qj_text' => 'my thought',
            'qj_quote' => 'the passage',
        ]);

        $this->assertSame('my thought', $this->fieldValue($response, 'qj_text'));
        $this->assertSame('the passage', $this->fieldValue($response, 'qj_quote'));
    }

    public function testManifestOffersANewNoteShortcut()
    {
        $manifest = [];
        Event::createAndTrigger('MANIFEST_SEND', $manifest);

        $this->assertArrayHasKey('shortcuts', $manifest);
        $this->assertCount(1, $manifest['shortcuts']);
        $this->assertStringContainsString('do=quickjot', $manifest['shortcuts'][0]['url']);
        $this->assertNotSame('', $manifest['shortcuts'][0]['name']);
    }

    public function testManifestKeepsExistingShortcuts()
    {
        $mine = ['name' => 'Search', 'url' => '/doku.php?do=search'];
        $manifest = ['shortcuts' => [$mine]];
        Event::createAndTrigger('MANIFEST_SEND', $manifest);

        $this->assertSame($mine, $manifest['shortcuts'][0], 'existing shortcuts must not be touched');
        $this->assertCount(2, $manifest['shortcuts']);
    }

    public function testBadSecurityTokenSavesNothing()
    {
        $response = $this->request('testuser', [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'sectok' => 'not-the-token',
            'qj_text' => 'nope',
        ]);

        $this->assertFalse(page_exists('quickjot:test'), 'a bad token must not write');
        $this->assertSame([], $this->redirects($response));
        $this->assertSame(
            1,
            $response->queryHTML('#plugin__quickjot_share_form')->count(),
            'the form must come back so the note is not lost'
        );
    }

    /**
     * checkSecurityToken() passes anonymous requests through, so an anonymous POST would
     * sail past the CSRF gate if the login check were not the first thing that happens.
     */
    public function testAnonymousPostSavesNothing()
    {
        $response = $this->request(null, [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'qj_text' => 'nope',
        ]);

        $this->assertFalse(page_exists('quickjot:test'), 'an anonymous POST must not write');
        $this->assertSame(1, $response->queryHTML('#dw__login')->count());
    }

    public function testFailedSaveKeepsWhatWasTyped()
    {
        global $conf;
        // the shipped test ACL denies @ALL everything below private:
        $conf['plugin']['quickjot']['pagepattern'] = 'private:journal';

        global $auth;
        $auth->createUser('shareuser', 'sharepass', 'Share User', 'share@example.com');

        $response = $this->request('shareuser', [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'sectok' => $this->tokenFor('shareuser'),
            'qj_text' => 'denied but precious',
        ]);

        $this->assertFalse(page_exists('private:journal'));
        $this->assertSame([], $this->redirects($response));
        $this->assertSame(
            'denied but precious',
            $this->fieldValue($response, 'qj_text'),
            'the note must survive a rejected save'
        );
    }

    public function testUnusableTimezoneSavesNothing()
    {
        $response = $this->request('testuser', [], [
            'do' => 'quickjot',
            'quickjot_save' => '1',
            'sectok' => $this->tokenFor('testuser'),
            'qj_text' => 'nope',
            'tz' => 'Mars/Olympus',
        ]);

        $this->assertFalse(page_exists('quickjot:test'), 'a wrong timezone must not silently write');
        $this->assertSame([], $this->redirects($response));
    }

    public function testManifestAdvertisesTheShareTarget()
    {
        $manifest = [];
        Event::createAndTrigger('MANIFEST_SEND', $manifest);

        $this->assertArrayHasKey('share_target', $manifest);
        $this->assertSame('GET', $manifest['share_target']['method']);
        $this->assertStringContainsString('quickjot/share.php', $manifest['share_target']['action']);
        $this->assertSame(
            ['title' => 'title', 'text' => 'text', 'url' => 'url'],
            $manifest['share_target']['params']
        );
    }

    /**
     * The manifest's top level icons are the whole app's identity - launcher icon, splash
     * screen, share sheet entry - and belong to the wiki. quickjot's notepad goes on the
     * shortcut that is actually quickjot's, and nowhere else.
     *
     * @dataProvider provideIconSets
     */
    public function testManifestNeverTouchesTheAppIcon($icons)
    {
        $manifest = $icons === null ? [] : ['icons' => $icons];
        $before = $manifest;
        Event::createAndTrigger('MANIFEST_SEND', $manifest);

        $this->assertSame(
            $before['icons'] ?? null,
            $manifest['icons'] ?? null,
            'the app icon is the wiki\'s to decide'
        );
    }

    public function provideIconSets()
    {
        return [
            'no icons member at all' => [null],
            'an empty list' => [[]],
            'only a favicon' => [[['src' => '/favicon.ico', 'sizes' => '16x16']]],
            'a logo of its own' => [[['src' => '/logo.png', 'sizes' => '256x256']]],
        ];
    }

    public function testShortcutWearsTheQuickjotIcon()
    {
        $manifest = [];
        Event::createAndTrigger('MANIFEST_SEND', $manifest);

        $sources = array_column($manifest['shortcuts'][0]['icons'], 'src');
        $this->assertNotSame([], $sources, 'the shortcut is the one place the notepad belongs');
        foreach ($sources as $src) {
            $this->assertStringContainsString('quickjot/icon-', $src);
        }
    }

    /**
     * The icon files the shortcut points at have to actually be there, and be the size
     * they claim - a browser that fetches one and finds nothing drops the shortcut's icon
     * without saying so.
     */
    public function testAdvertisedIconsExist()
    {
        foreach ([192, 512] as $size) {
            $file = DOKU_PLUGIN . 'quickjot/icon-' . $size . '.png';
            $this->assertFileExists($file);
            $this->assertSame([$size, $size], array_slice(getimagesize($file), 0, 2));
        }
    }

    /**
     * @dataProvider provideShares
     */
    public function testNormalizeShare($title, $text, $url, $expected)
    {
        $this->assertSame($expected, action_plugin_quickjot_share::normalizeShare($title, $text, $url));
    }

    public static function provideShares()
    {
        return [
            'shared text becomes the quote' => [
                'A Title', 'a passage', '',
                ['title' => 'A Title', 'text' => '', 'quote' => 'a passage', 'url' => ''],
            ],
            'text is nothing but a link' => [
                'A Title', 'https://example.com/a', '',
                ['title' => 'A Title', 'text' => '', 'quote' => '', 'url' => 'https://example.com/a'],
            ],
            'a link at the end stays in the quote' => [
                '', 'read this https://example.com/a', '',
                [
                    'title' => '',
                    'text' => '',
                    'quote' => 'read this https://example.com/a',
                    'url' => '',
                ],
            ],
            'a link on its own line stays in the quote' => [
                '', "read this\nhttps://example.com/a", '',
                [
                    'title' => '',
                    'text' => '',
                    'quote' => "read this\nhttps://example.com/a",
                    'url' => '',
                ],
            ],
            'an explicit url wins over a bare link share' => [
                '', 'https://example.com/a', 'https://example.com/b',
                [
                    'title' => '',
                    'text' => '',
                    'quote' => 'https://example.com/a',
                    'url' => 'https://example.com/b',
                ],
            ],
            'a link in the middle stays put' => [
                '', 'see https://example.com/a for details', '',
                [
                    'title' => '',
                    'text' => '',
                    'quote' => 'see https://example.com/a for details',
                    'url' => '',
                ],
            ],
            'a non http scheme is not a link share' => [
                '', 'tel:+123456', '',
                ['title' => '', 'text' => '', 'quote' => 'tel:+123456', 'url' => ''],
            ],
            'everything is trimmed' => [
                '  T  ', "  note  \n", '  ',
                ['title' => 'T', 'text' => '', 'quote' => 'note', 'url' => ''],
            ],
            'an empty share stays empty' => [
                '', '', '',
                ['title' => '', 'text' => '', 'quote' => '', 'url' => ''],
            ],
        ];
    }

    /**
     * @dataProvider provideShareParams
     */
    public function testShareParams($title, $text, $url, $expected)
    {
        $this->assertSame($expected, action_plugin_quickjot_share::shareParams($title, $text, $url));
    }

    public static function provideShareParams()
    {
        return [
            'all three' => [
                'T', 'note', 'https://example.com',
                [
                    'do' => 'quickjot',
                    'qj_title' => 'T',
                    'qj_text' => 'note',
                    'qj_url' => 'https://example.com',
                ],
            ],
            'empty fields are dropped' => [
                '', 'note', '  ',
                ['do' => 'quickjot', 'qj_text' => 'note'],
            ],
            'a share of nothing still names the action' => [
                '', '', '',
                ['do' => 'quickjot'],
            ],
            'core parameters cannot be smuggled in' => [
                '', 'id=start&do=edit', '',
                ['do' => 'quickjot', 'qj_text' => 'id=start&do=edit'],
            ],
        ];
    }
}
