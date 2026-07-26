<?php

/**
 * English settings labels for the quickjot plugin
 *
 * @author Michael Große <mic.grosse+dokuwiki@googlemail.com>
 */

$lang['pagepattern']  = 'Page ID of today\'s journal page. <code>%</code> placeholders are expanded like ' .
    '<code>strftime()</code>, everything else is taken literally.';
$lang['notesheading'] = 'The heading notes are appended under, written exactly as it appears in the wiki text. ' .
    'It is created if the page does not have it yet.';
$lang['timeformat']   = 'Format of the timestamp each note starts with (<code>strftime()</code> placeholders).';
$lang['createpage']   = 'Create today\'s page (from the namespace template, if any) when it does not exist yet';
