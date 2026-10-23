<?php

namespace dokuwiki\plugin\quickjot;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use dokuwiki\Extension\Plugin;
use dokuwiki\Search\Indexer;
use dokuwiki\Utf8\PhpString;

use function PHP81_BC\strftime;

/**
 * All the logic behind appending a note to today's journal page
 *
 * Kept separate from the action plugin so it can be exercised without a request.
 */
class JournalPage
{
    /** @var Plugin provides configuration and language strings */
    protected $plugin;

    /** @var DateTimeZone|null zone dates are rendered in, resolved on first use */
    protected $tz;

    /**
     * @param Plugin $plugin the quickjot plugin instance, used for getConf()/getLang()
     */
    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Render dates in the timezone the calling device lives in
     *
     * A wiki running on UTC would otherwise roll over to the next page in the middle of
     * the evening and stamp every note with the wrong time. Note that only the *zone*
     * comes from the client - the instant is still the server's clock, which is both
     * correct by definition and not something a client can get wrong.
     *
     * @param string $name IANA timezone name, e.g. "Europe/Berlin"
     * @param string $offset UTC offset such as "+02:00", used when the name is unusable
     * @throws QuickJotException if the client sent something, but nothing usable
     * @return void
     */
    public function useTimezone($name = '', $offset = '')
    {
        $this->tz = $this->resolveTimezone($name, $offset);
    }

    /**
     * The timezone dates are being rendered in
     *
     * @return DateTimeZone
     */
    public function timezone()
    {
        return $this->tz ??= $this->resolveTimezone('', '');
    }

    /**
     * Pick the timezone to use, most specific source first
     *
     * A name the server's timezone database does not know is not by itself fatal: for
     * "now" a plain UTC offset says the same thing, so a client that sent both stays
     * usable on a host with outdated tzdata.
     *
     * @param string $name
     * @param string $offset
     * @throws QuickJotException
     * @return DateTimeZone
     */
    protected function resolveTimezone($name, $offset)
    {
        $name = trim((string)$name);
        $offset = trim((string)$offset);

        foreach ([$name, $offset] as $candidate) {
            if ($candidate === '') continue;
            $zone = $this->makeTimezone($candidate);
            if ($zone instanceof DateTimeZone) return $zone;
        }

        // the client asked for a timezone and we could not honour any of it - saying so
        // beats silently stamping notes in the wrong zone, which is the bug being avoided
        if ($name !== '' || $offset !== '') {
            throw new QuickJotException($this->plugin->getLang('err_badtz'), 400);
        }

        // clients that do not care fall back to the wiki's own setting, then the server's
        $zone = $this->makeTimezone(trim((string)$this->plugin->getConf('timezone')));
        return $zone ?? new DateTimeZone(date_default_timezone_get());
    }

    /**
     * @param string $spec timezone name or UTC offset
     * @return DateTimeZone|null null when it is not a timezone we can use
     */
    protected function makeTimezone($spec)
    {
        if ($spec === '') return null;
        // ISO 8601 spells UTC "Z", which DateTimeZone does not accept
        if (strcasecmp($spec, 'Z') === 0) $spec = '+00:00';

        try {
            return new DateTimeZone($spec);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The moment a note is being written, in the client's timezone
     *
     * @param DateTimeInterface|int|null $now a ready made moment, a timestamp, or now
     * @return DateTimeInterface
     */
    public function moment($now = null)
    {
        if ($now instanceof DateTimeInterface) return $now;
        $timestamp = $now === null ? time() : (int)$now;

        // "@timestamp" is always parsed as UTC, so the zone has to be applied afterwards
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone());
    }

    /**
     * Expand a configured strftime pattern for a given moment
     *
     * @param string $pattern
     * @param DateTimeInterface|int|null $now
     * @throws QuickJotException on a pattern the formatter cannot read
     * @return string
     */
    protected function format($pattern, $now = null)
    {
        try {
            return strftime($pattern, $this->moment($now));
        } catch (QuickJotException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new QuickJotException(
                'Invalid date format in the quickjot configuration: ' . $e->getMessage(),
                500
            );
        }
    }

    /**
     * The timestamp a note written at the given moment would carry
     *
     * @param DateTimeInterface|int|null $now
     * @return string
     */
    public function renderTime($now = null)
    {
        return $this->format($this->plugin->getConf('timeformat'), $now);
    }

    /**
     * The page ID today's notes belong on
     *
     * The configured pattern is expanded with strftime placeholders - anything that is not
     * a % sequence is taken literally, which is what allows a namespace like "appreciation"
     * to survive (PHP's date() would mangle every one of those letters).
     *
     * @param DateTimeInterface|int|null $now moment to resolve, defaults to now
     * @return string
     */
    public function getTodayId($now = null)
    {
        $id = cleanID($this->format($this->plugin->getConf('pagepattern'), $now));
        if ($id === '') {
            throw new QuickJotException('The configured page pattern does not produce a valid page ID', 500);
        }
        return $id;
    }

    /**
     * Title and level of the configured notes heading
     *
     * The level follows the parser's own rule (see ParserMode\Header), where more equal
     * signs mean a higher level heading: "======" is level 1, "=====" is level 2.
     *
     * @return array [string $title, int $level]
     */
    public function parseHeading()
    {
        $line = trim($this->plugin->getConf('notesheading'));
        $level = 7 - strspn($line, '=');
        if ($level < 1) $level = 1;
        return [trim($line, "= \t"), $level];
    }

    /**
     * Byte offset in $text at which a new note should be inserted
     *
     * Works off the parsed instructions rather than a regex so that a heading appearing
     * inside a code block or otherwise escaped is correctly ignored. The offset is the end
     * of the section's content, with trailing whitespace backed over.
     *
     * Positions reported by the parser are one byte ahead of the raw text, because
     * Parser::parse() pads the document with a leading newline before lexing. This is the
     * same offset rawWikiSlices() cancels out by treating its ranges as 1-based.
     *
     * @param string $text raw wiki text
     * @return int|null null if the page has no such heading
     */
    public function findSectionEnd($text)
    {
        [$title, $level] = $this->parseHeading();

        $found = false;
        $end = strlen($text);
        foreach (p_get_instructions($text) as $instruction) {
            if ($instruction[0] !== 'header') continue;
            [$foundTitle, $foundLevel, $pos] = $instruction[1];

            if ($found) {
                // the first heading that closes our section ends it
                if ($foundLevel <= $level) {
                    $end = max(0, $pos - 1);
                    break;
                }
                continue;
            }

            if (PhpString::strtolower(trim($foundTitle)) === PhpString::strtolower($title)) {
                $found = true;
            }
        }

        if (!$found) return null;
        return strlen(rtrim(substr($text, 0, $end)));
    }

    /**
     * Build the wiki markup for a single note
     *
     * @param string $text the note itself, required unless there is a quote
     * @param string $quote text quoted from the source page, optional
     * @param string $url URL to attach as a link, optional
     * @param string $title link title, falls back to the URL itself
     * @param DateTimeInterface|int|null $now moment to stamp the note with, defaults to now
     * @return string
     */
    public function renderNote($text, $quote = '', $url = '', $title = '', $now = null)
    {
        $text = $this->normalize($text);
        $quote = $this->normalize($quote);

        // a note has to say something, but a passage worth keeping says plenty on its own -
        // demanding commentary before a quote may be saved would only invite filler
        if ($text === '' && $quote === '') {
            throw new QuickJotException($this->plugin->getLang('err_notext'), 400);
        }

        // keep the note on a single line so it stays one list item
        $parts = [];
        if ($text !== '') $parts[] = implode(' \\\\ ', explode("\n", $text));

        $link = $this->renderLink($url, $title);
        if ($link !== '') $parts[] = $link;

        // rtrim, because a quote-only note has nothing following the timestamp
        $markup = rtrim('  * **' . $this->renderTime($now) . '** ' . implode(' ', $parts));

        if ($quote !== '') {
            foreach (explode("\n", $quote) as $quoteLine) {
                $markup .= "\n" . rtrim('> ' . $quoteLine);
            }
        }

        return $markup;
    }

    /**
     * Append a note to today's page
     *
     * Follows the same sequence as the remote API's savePage(), which is what makes the
     * changelog, attic, locking and spam checks behave the way a normal edit would.
     *
     * @param string $text the note itself
     * @param string $quote text quoted from the source page
     * @param string $url URL to attach as a link
     * @param string $title link title
     * @return array details of what was written, for the client to report back
     */
    public function addNote($text, $quote = '', $url = '', $title = '')
    {
        global $TEXT;

        // one moment for both the page it lands on and the stamp it carries, so a note
        // written across midnight cannot end up dated differently from its page
        $now = $this->moment();
        $id = $this->getTodayId($now);
        $exists = page_exists($id);

        $needed = $exists ? AUTH_EDIT : AUTH_CREATE;
        if (auth_quickaclcheck($id) < $needed) {
            throw new QuickJotException($this->plugin->getLang('err_denied'), 403);
        }

        if (!$exists && !$this->plugin->getConf('createpage')) {
            throw new QuickJotException($this->plugin->getLang('err_nocreate'), 404);
        }

        // build the note before touching the page, so a bad note fails without a lock
        $note = $this->renderNote($text, $quote, $url, $title, $now);

        // check the lock before reading, so the read-modify-write window is as small as
        // it can be without holding a lock across the whole operation
        if (checklock($id)) {
            throw new QuickJotException($this->plugin->getLang('err_locked'), 409);
        }

        // a page that does not exist yet starts from the namespace template, exactly as
        // the editor would have offered it
        $old = $exists ? rawWiki($id) : pageTemplate($id);
        $new = $this->insert($old, $note);

        // checkwordblock() reads the globals when called without arguments
        $TEXT = $new;
        if (checkwordblock()) {
            throw new QuickJotException($this->plugin->getLang('err_blocked'), 400);
        }

        lock($id);
        saveWikiText($id, $new, $this->plugin->getLang('summary'));
        unlock($id);

        // a page that was never indexed would otherwise stay invisible to search
        try {
            (new Indexer())->addPage($id);
        } catch (\Exception) {
            // indexing failure must not fail the note, the page is already saved
        }

        return [
            'page' => $id,
            'created' => !$exists,
            'time' => $this->renderTime($now),
            'timezone' => $this->timezone()->getName(),
            'url' => wl($id, '', true),
        ];
    }

    /**
     * Splice a note into the notes section of a page, creating the heading if needed
     *
     * @param string $text raw wiki text of the page
     * @param string $note markup of the note to insert
     * @return string
     */
    public function insert($text, $note)
    {
        $text = cleanText($text);
        $pos = $this->findSectionEnd($text);

        if ($pos === null) {
            // no notes heading yet, start one at the end of the page
            $text = rtrim($text);
            if ($text !== '') $text .= "\n\n";
            $text .= trim($this->plugin->getConf('notesheading'));
            $pos = strlen($text);
        }

        $after = substr($text, $pos);
        if ($after === '') $after = "\n";

        return substr($text, 0, $pos) . "\n" . $note . $after;
    }

    /**
     * Render an external link, rejecting anything that is not plain http(s)
     *
     * @param string $url
     * @param string $title falls back to the URL
     * @return string markup, empty when no URL was given
     */
    protected function renderLink($url, $title)
    {
        $url = trim($url);
        if ($url === '') return '';

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new QuickJotException($this->plugin->getLang('err_badurl'), 400);
        }

        // "]]" would close the link early, "|" would be read as the title separator
        $url = str_replace([']', '|'], ['%5D', '%7C'], $url);

        $title = $this->normalize($title);
        $title = str_replace("\n", ' ', $title);
        if ($title === '') $title = $url;
        $title = str_replace(']]', '] ]', $title);

        return '[[' . $url . '|' . $title . ']]';
    }

    /**
     * Normalise client supplied text to plain trimmed UTF-8 lines
     *
     * @param string $text
     * @return string
     */
    protected function normalize($text)
    {
        $text = cleanText((string)$text);
        // control characters other than newline have no business in wiki text
        $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text) ?? '';

        $lines = array_map(trim(...), explode("\n", $text));
        return trim(implode("\n", $lines));
    }
}
