<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/tests/LocaleFilesTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LocaleFilesTest
 *
 * @brief Translations. A key missing from a locale is rendered as ##key##, so an
 *        incomplete file is worse than none.
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PKP\tests\PKPTestCase;

#[CoversNothing]
class LocaleFilesTest extends PKPTestCase
{
    /** Locale codes shipped by the plugin, as OJS 3.5 names them. */
    public const LOCALES = [
        'ar', 'az', 'bg', 'ca', 'cs', 'da', 'de', 'el', 'en', 'es', 'eu', 'fa', 'fi', 'fr', 'fr_CA', 'gl', 'hu', 'hy', 'id',
        'it', 'ja', 'ka', 'mk', 'ms', 'nb_NO', 'nl', 'pl', 'pt', 'pt_BR', 'ro', 'ru', 'sl', 'sr_Latn', 'sv', 'tr', 'uk', 'vi', 'zh_Hans',
    ];

    /** Locales reviewed in full by the team. */
    public const REVIEWED = ['en', 'es', 'pt', 'pt_BR'];

    public const MASTER = 'en';

    public const PREFIX = 'plugins.generic.ojsbrSubmissionQueueReport.';

    protected function localeDir(): string
    {
        return dirname(__DIR__) . '/locale';
    }

    /** @return array<string, PoFile> */
    protected function files(): array
    {
        $files = [];
        foreach (self::LOCALES as $locale) {
            $files[$locale] = new PoFile($this->localeDir() . "/{$locale}/locale.po");
        }
        return $files;
    }

    public function testShipsExactlyTheLocaleCodesOfThisLine(): void
    {
        $dirs = array_map('basename', glob($this->localeDir() . '/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);
        $this->assertSame(self::LOCALES, $dirs);
    }

    public function testEveryLocaleHasExactlyTheKeysOfTheMaster(): void
    {
        $files = $this->files();
        $master = array_keys($files[self::MASTER]->entries);
        $this->assertGreaterThan(100, count($master));
        foreach ($files as $locale => $file) {
            $this->assertSame($master, array_keys($file->entries), "Keys of {$locale} differ from " . self::MASTER . '.');
        }
    }

    public function testEveryKeyTheCodeUsesExists(): void
    {
        $root = dirname(__DIR__);
        $sources = '';
        foreach (array_merge(glob($root . '/*.php'), glob($root . '/classes/*.php'), glob($root . '/templates/*.tpl')) as $file) {
            $sources .= file_get_contents($file);
        }
        preg_match_all('/plugins\.generic\.ojsbrSubmissionQueueReport\.[a-zA-Z0-9_.]*[a-zA-Z0-9_]/', $sources, $m);
        $keys = array_keys($this->files()[self::MASTER]->entries);
        foreach (array_unique($m[0]) as $key) {
            $this->assertTrue(in_array($key, $keys, true) || $this->isKeyPrefix($key, $keys), "{$key} is used but not translated.");
        }

        // Keys built at runtime: the column names, the statuses of a review and the texts of the page.
        foreach (\APP\plugins\generic\ojsbrSubmissionQueueReport\classes\ReportExport::SCREEN_COLUMNS as $id => $column) {
            $this->assertContains(self::PREFIX . 'column.' . $id, $keys);
        }
        foreach (array_unique(\APP\plugins\generic\ojsbrSubmissionQueueReport\classes\ReportRows::REVIEW_STATUS_KEYS) as $status) {
            $this->assertContains(self::PREFIX . 'reviewStatus.' . $status, $keys);
        }
    }

    public function testEveryTextOfThePageExists(): void
    {
        $handler = (string) file_get_contents(dirname(__DIR__) . '/classes/QueueReportHandler.php');
        $this->assertSame(1, preg_match('/\$keys = \[(.*?)\];/s', $handler, $m));
        preg_match_all("/'([a-zA-Z]+)'/", $m[1], $names);
        $keys = array_keys($this->files()[self::MASTER]->entries);
        $this->assertGreaterThan(30, count($names[1]));
        foreach ($names[1] as $name) {
            $this->assertContains(self::PREFIX . 'ui.' . $name, $keys);
        }

        // Every text the page script reads is sent by the handler.
        $script = (string) file_get_contents(dirname(__DIR__) . '/js/submissionQueueReport.js');
        preg_match_all('/\bt\.([a-zA-Z]+)\b/', $script, $used);
        foreach (array_unique($used[1]) as $name) {
            $this->assertContains($name, $names[1], "The page uses t.{$name}, which the handler does not send.");
        }
    }

    protected function isKeyPrefix(string $key, array $keys): bool
    {
        foreach ($keys as $known) {
            if (str_starts_with($known, $key . '.')) {
                return true;
            }
        }
        return false;
    }

    public function testNoTranslationIsEmptyAndOnlyUnreviewedOnesAreFuzzy(): void
    {
        foreach ($this->files() as $locale => $file) {
            foreach ($file->entries as $key => $value) {
                $this->assertNotEmpty(trim($value), "Empty translation for {$key} in {$locale}.");
                // Reviewed by the team in full; the others are translated and flagged for a speaker.
                $this->assertSame(!in_array($locale, self::REVIEWED, true), $file->fuzzy[$key] ?? false, "Fuzzy flag of {$key} in {$locale}.");
            }
        }
    }

    public function testHeaderDeclaresTheLocaleAndTheTeam(): void
    {
        foreach ($this->files() as $locale => $file) {
            $this->assertStringContainsString("Language: {$locale}\n", $file->header, "Wrong Language header in {$locale}.");
            $this->assertStringContainsString("Last-Translator: OJSBR\n", $file->header);
            $this->assertStringContainsString("Language-Team: OJSBR\n", $file->header);
        }
    }

    public function testPlaceholdersAreTheSameInEveryLocale(): void
    {
        $files = $this->files();
        foreach ($files[self::MASTER]->entries as $key => $master) {
            preg_match_all('/\{\$[a-zA-Z0-9_]+\}/', $master, $m);
            $expected = array_values(array_unique($m[0]));
            sort($expected);
            foreach ($files as $locale => $file) {
                preg_match_all('/\{\$[a-zA-Z0-9_]+\}/', $file->entries[$key], $m);
                $found = array_values(array_unique($m[0]));
                sort($found);
                $this->assertSame($expected, $found, "Placeholders of {$key} in {$locale}.");
                // "count" is reserved by the translator (plural rules).
                $this->assertStringNotContainsString('{$count}', $file->entries[$key]);
            }
        }
    }

    public function testTheDescriptionCarriesNoCredit(): void
    {
        foreach ($this->files() as $locale => $file) {
            $this->assertStringNotContainsString('OJSBR', $file->entries[self::PREFIX . 'description'], "Credit in the description ({$locale}).");
        }
    }
}
