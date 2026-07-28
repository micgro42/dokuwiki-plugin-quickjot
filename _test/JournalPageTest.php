<?php

namespace dokuwiki\plugin\quickjot\test;

use dokuwiki\plugin\quickjot\JournalPage;
use dokuwiki\plugin\quickjot\QuickJotException;
use DokuWikiTest;

/**
 * Tests for the note building and page splicing logic
 *
 * @group plugin_quickjot
 * @group plugins
 */
class JournalPageTest extends DokuWikiTest
{
    protected $pluginsEnabled = ['quickjot'];

    /** @var JournalPage */
    protected $journal;

    public function setUp(): void
    {
        parent::setUp();
        $this->journal = new JournalPage(plugin_load('action', 'quickjot'));
    }

    /**
     * Override a plugin setting for the current test
     *
     * @param string $key
     * @param mixed $value
     */
    protected function setConf($key, $value)
    {
        global $conf;
        $conf['plugin']['quickjot'][$key] = $value;
    }

    public function testTodayIdKeepsLiteralNamespace()
    {
        $stamp = mktime(14, 32, 0, 7, 26, 2026);
        $this->assertSame('appreciation:2026:07:2026-07-26', $this->journal->getTodayId($stamp));
    }

    public function testTodayIdIsCleaned()
    {
        // uppercase and spaces are not valid in a page ID and must be normalised away
        $this->setConf('pagepattern', 'My Journal:%Y');
        $stamp = mktime(0, 0, 0, 1, 1, 2026);
        $this->assertSame('my_journal:2026', $this->journal->getTodayId($stamp));
    }

    /**
     * A fixed instant late enough in the UTC day that a positive offset lands on the next
     *
     * @return int
     */
    protected function lateUtcEvening()
    {
        return gmmktime(23, 30, 0, 7, 26, 2026);
    }

    public function testTimezoneDecidesWhichDayItIs()
    {
        $this->setConf('pagepattern', 'journal:%Y-%m-%d');
        $stamp = $this->lateUtcEvening();

        $this->journal->useTimezone('UTC');
        $this->assertSame('journal:2026-07-26', $this->journal->getTodayId($stamp));

        // 23:30 UTC is already half past one the next morning in Berlin
        $this->journal->useTimezone('Europe/Berlin');
        $this->assertSame('journal:2026-07-27', $this->journal->getTodayId($stamp));
    }

    public function testTimezoneStampsTheNote()
    {
        $this->journal->useTimezone('Asia/Kathmandu');
        $this->assertSame('05:15', $this->journal->renderTime($this->lateUtcEvening()));
    }

    public function testPlainOffsetIsAccepted()
    {
        $this->journal->useTimezone('', '+05:45');

        $this->assertSame('+05:45', $this->journal->timezone()->getName());
        $this->assertSame('05:15', $this->journal->renderTime($this->lateUtcEvening()));
    }

    public function testOffsetCoversAnUnknownTimezoneName()
    {
        // a server with an outdated timezone database must still get the time right
        $this->journal->useTimezone('Mars/Olympus', '+02:00');
        $this->assertSame('01:30', $this->journal->renderTime($this->lateUtcEvening()));
    }

    public function testUnusableTimezoneIsRejected()
    {
        $this->expectException(QuickJotException::class);
        $this->journal->useTimezone('Mars/Olympus', 'not an offset');
    }

    public function testConfiguredTimezoneAppliesWithoutAClient()
    {
        $this->setConf('timezone', 'Europe/Berlin');

        $this->assertSame('Europe/Berlin', $this->journal->timezone()->getName());
        $this->assertSame('01:30', $this->journal->renderTime($this->lateUtcEvening()));
    }

    public function testClientTimezoneBeatsTheConfiguredOne()
    {
        $this->setConf('timezone', 'Europe/Berlin');
        $this->journal->useTimezone('UTC');

        $this->assertSame('23:30', $this->journal->renderTime($this->lateUtcEvening()));
    }

    public function testParseHeading()
    {
        $this->assertSame(['Notes', 2], $this->journal->parseHeading());

        $this->setConf('notesheading', '====== Jots ======');
        $this->assertSame(['Jots', 1], $this->journal->parseHeading());
    }

    public function testFindSectionEndAtEndOfPage()
    {
        $text = "====== Day ======\n\nsome prose\n\n===== Notes =====\n\n  * an earlier note\n\n";
        $pos = $this->journal->findSectionEnd($text);

        $this->assertNotNull($pos);
        $this->assertSame("  * an earlier note", substr($text, $pos - 19, 19));
    }

    public function testFindSectionEndStopsAtNextHeading()
    {
        $text = "===== Notes =====\n\n  * a note\n\n===== Later =====\n\nmore prose\n";
        $pos = $this->journal->findSectionEnd($text);

        $this->assertNotNull($pos);
        $this->assertStringEndsWith('  * a note', substr($text, 0, $pos));
    }

    public function testFindSectionEndSkipsDeeperHeadings()
    {
        $text = "===== Notes =====\n\n  * a note\n\n==== Sub ====\n\n  * nested note\n\n===== Later =====\n";
        $pos = $this->journal->findSectionEnd($text);

        $this->assertNotNull($pos);
        $this->assertStringEndsWith('  * nested note', substr($text, 0, $pos));
    }

    public function testFindSectionEndIgnoresHeadingInCodeBlock()
    {
        $text = "====== Day ======\n\n<code>\n===== Notes =====\n</code>\n";
        $this->assertNull($this->journal->findSectionEnd($text));
    }

    public function testFindSectionEndMissing()
    {
        $this->assertNull($this->journal->findSectionEnd("====== Day ======\n\nsome prose\n"));
    }

    public function testRenderNoteMinimal()
    {
        $stamp = mktime(14, 32, 0, 7, 26, 2026);
        $this->assertSame(
            '  * **14:32** picked up the parcel',
            $this->journal->renderNote('picked up the parcel', '', '', '', $stamp)
        );
    }

    public function testRenderNoteWithLinkAndQuote()
    {
        $stamp = mktime(9, 5, 0, 7, 26, 2026);
        $this->assertSame(
            "  * **09:05** worth reading [[https://example.com/a|An Example]]\n> the quoted bit",
            $this->journal->renderNote('worth reading', 'the quoted bit', 'https://example.com/a', 'An Example', $stamp)
        );
    }

    public function testRenderNoteJoinsMultipleLines()
    {
        $stamp = mktime(9, 5, 0, 7, 26, 2026);
        $note = $this->journal->renderNote("first line\nsecond line", '', '', '', $stamp);

        $this->assertSame('  * **09:05** first line \\\\ second line', $note);
        // still exactly one line, so it stays one list item
        $this->assertStringNotContainsString("\n", $note);
    }

    public function testRenderNoteQuotesEveryLine()
    {
        $stamp = mktime(9, 5, 0, 7, 26, 2026);
        $note = $this->journal->renderNote('see', "line one\nline two", '', '', $stamp);

        $this->assertSame("  * **09:05** see\n> line one\n> line two", $note);
    }

    public function testRenderNoteNeutralisesLinkTitle()
    {
        $stamp = mktime(9, 5, 0, 7, 26, 2026);
        $note = $this->journal->renderNote('x', '', 'https://example.com/', 'evil]] break', $stamp);

        // the "]]" must not be able to close the link early
        $this->assertSame('  * **09:05** x [[https://example.com/|evil] ] break]]', $note);
    }

    public function testRenderNoteEncodesUrl()
    {
        $stamp = mktime(9, 5, 0, 7, 26, 2026);
        $note = $this->journal->renderNote('x', '', 'https://example.com/a]b|c', 'T', $stamp);

        $this->assertSame('  * **09:05** x [[https://example.com/a%5Db%7Cc|T]]', $note);
    }

    public function testRenderNoteFallsBackToUrlAsTitle()
    {
        $stamp = mktime(9, 5, 0, 7, 26, 2026);
        $note = $this->journal->renderNote('x', '', 'https://example.com/', '', $stamp);

        $this->assertSame('  * **09:05** x [[https://example.com/|https://example.com/]]', $note);
    }

    public function testRenderNoteRejectsNonHttpUrl()
    {
        $this->expectException(QuickJotException::class);
        $this->journal->renderNote('x', '', 'javascript:alert(1)', 'T');
    }

    public function testRenderNoteRejectsEmptyText()
    {
        $this->expectException(QuickJotException::class);
        $this->journal->renderNote("   \n  ");
    }

    public function testInsertIntoExistingSection()
    {
        $text = "====== Day ======\n\nprose\n\n===== Notes =====\n\n  * older\n\n===== Later =====\n\ntail\n";
        $new = $this->journal->insert($text, '  * newer');

        $this->assertSame(
            "====== Day ======\n\nprose\n\n===== Notes =====\n\n  * older\n  * newer\n\n===== Later =====\n\ntail\n",
            $new
        );
    }

    public function testInsertIntoEmptySection()
    {
        $new = $this->journal->insert("===== Notes =====\n", '  * first');
        $this->assertSame("===== Notes =====\n  * first\n", $new);
    }

    public function testInsertCreatesMissingHeading()
    {
        $new = $this->journal->insert("====== Day ======\n\nprose\n", '  * first');
        $this->assertSame("====== Day ======\n\nprose\n\n===== Notes =====\n  * first\n", $new);
    }

    public function testInsertIntoEmptyPage()
    {
        $new = $this->journal->insert('', '  * first');
        $this->assertSame("===== Notes =====\n  * first\n", $new);
    }
}
