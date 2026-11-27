<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/classes/ReportExport.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReportExport
 *
 * @brief The columns of the report: the ones of the screen and the cells of the two
 *  shapes of the export (one row per submission, one row per review assignment).
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\classes;

class ReportExport
{
    /** Columns of the screen: id => [sortable by, shown at first]. */
    public const SCREEN_COLUMNS = [
        'id' => ['sort' => 'id', 'visible' => true],
        'title' => ['sort' => null, 'visible' => true],
        'mainSituation' => ['sort' => null, 'visible' => true],
        'views' => ['sort' => null, 'visible' => false],
        'stage' => ['sort' => null, 'visible' => false],
        'section' => ['sort' => null, 'visible' => true],
        'locale' => ['sort' => null, 'visible' => false],
        'dateSubmitted' => ['sort' => 'dateSubmitted', 'visible' => true],
        'lastActivity' => ['sort' => 'lastActivity', 'visible' => true],
        'authors' => ['sort' => null, 'visible' => true],
        'emails' => ['sort' => null, 'visible' => false],
        'affiliations' => ['sort' => null, 'visible' => false],
        'editors' => ['sort' => null, 'visible' => true],
        'round' => ['sort' => null, 'visible' => true],
        'reviewers' => ['sort' => null, 'visible' => true],
        'nextDue' => ['sort' => null, 'visible' => false],
        'lastDecision' => ['sort' => null, 'visible' => true],
        'issue' => ['sort' => null, 'visible' => false],
        'categories' => ['sort' => null, 'visible' => false],
        'doi' => ['sort' => null, 'visible' => false],
    ];

    /** @var array<string,string> Situation id => name */
    protected array $situationNames = [];

    public function __construct(protected QueueReport $report, protected ReportRows $rows)
    {
        foreach (QueueReport::MAIN_SITUATIONS as $id) {
            $this->situationNames[$id] = QueueReportHandler::getSituationName($id);
        }
        foreach ($report->getViews() as $id => $view) {
            $this->situationNames[$id] = $view->getData()['name'];
        }
    }

    /**
     * The columns of the screen, with their names.
     *
     * @return array<int,array{id:string,label:string,sort:?string,visible:bool}>
     */
    public static function getScreenColumns(): array
    {
        $columns = [];
        foreach (self::SCREEN_COLUMNS as $id => $column) {
            $columns[] = ['id' => $id, 'label' => self::label($id)] + $column;
        }
        return $columns;
    }

    /**
     * The header of an export.
     *
     * @return string[]
     */
    public function getHeader(string $mode): array
    {
        $ids = $mode === QueueReportHandler::MODE_REVIEWS
            ? ['id', 'url', 'title', 'mainSituation', 'stage', 'section', 'editors', 'round', 'roundStatus',
                'reviewer', 'reviewStatus', 'recommendation', 'dateAssigned', 'dateResponseDue', 'dateResponded', 'dateDue', 'dateCompleted', 'daysOverdue']
            : ['id', 'url', 'title', 'mainSituation', 'views', 'stage', 'locale', 'section', 'dateSubmitted',
                'daysSinceSubmission', 'lastActivity', 'daysInactive', 'authors', 'emails', 'affiliations', 'editors',
                'round', 'roundStatus', 'reviewsRequired', 'reviewsAccepted', 'reviewsCompleted', 'reviewsOverdue',
                'reviewsDeclined', 'nextDue', 'reviewers', 'lastDecision', 'lastDecisionDate', 'issue', 'categories', 'doi'];
        return array_map(self::label(...), $ids);
    }

    /**
     * The cells of some rows of the report.
     *
     * @return iterable<array<int,string|int|null>>
     */
    public function getRows(array $rows, string $mode): iterable
    {
        foreach ($rows as $row) {
            if ($mode === QueueReportHandler::MODE_REVIEWS) {
                foreach ($row['reviews'] as $review) {
                    yield [
                        $row['id'],
                        $row['url'],
                        $row['title'],
                        $this->situationNames[$row['mainSituation']] ?? $row['mainSituation'],
                        $row['stage'],
                        $row['section'],
                        implode("\n", $row['editors']),
                        $row['round'],
                        $row['roundStatus'],
                        $review['reviewer'],
                        $review['statusLabel'],
                        $review['recommendation'],
                        $review['dateAssigned'],
                        $review['dateResponseDue'],
                        $review['dateConfirmed'],
                        $review['dateDue'],
                        $review['dateCompleted'],
                        $review['daysOverdue'],
                    ];
                }
                continue;
            }

            yield [
                $row['id'],
                $row['url'],
                $row['title'],
                $this->situationNames[$row['mainSituation']] ?? $row['mainSituation'],
                implode("\n", array_map(fn ($id) => $this->situationNames[$id] ?? $id, $row['views'])),
                $row['stage'],
                $row['localeName'],
                $row['section'],
                $row['dateSubmitted'],
                $row['daysSinceSubmission'],
                $row['lastActivity'],
                $row['daysInactive'],
                implode("\n", array_column($row['authors'], 'name')),
                implode("\n", array_map(
                    fn (array $author) => $author['email'] . ($author['isPrimaryContact'] ? ' (' . __('plugins.generic.ojsbrSubmissionQueueReport.ui.contact') . ')' : ''),
                    $row['authors']
                )),
                implode("\n", array_column($row['authors'], 'affiliations')),
                implode("\n", $row['editors']),
                $row['round'],
                $row['roundStatus'],
                $row['reviewsRequired'],
                $row['round'] ? $row['reviewsAccepted'] : null,
                $row['round'] ? $row['reviewsCompleted'] : null,
                $row['round'] ? $row['reviewsOverdue'] : null,
                $row['round'] ? $row['reviewsDeclined'] : null,
                $row['nextDue'],
                implode("\n", array_map($this->reviewLine(...), $row['reviews'])),
                $row['lastDecision'],
                $row['lastDecisionDate'],
                $row['issue'],
                implode("\n", $row['categories']),
                $row['doi'],
            ];
        }
    }

    /**
     * One review assignment in one line: reviewer, status, recommendation, deadline.
     */
    protected function reviewLine(array $review): string
    {
        $parts = [$review['reviewer'], $review['statusLabel']];
        if ($review['recommendation'] !== '') {
            $parts[] = $review['recommendation'];
        }
        if ($review['dateDue'] !== '' && !$review['dateCompleted'] && !$review['declined'] && !$review['cancelled']) {
            $parts[] = __(
                'plugins.generic.ojsbrSubmissionQueueReport.ui.' . ($review['deadlineIsResponse'] ? 'responseDue' : 'due'),
                ['date' => $review['deadlineText']]
            );
        }
        if ($review['daysOverdue']) {
            $parts[] = __('plugins.generic.ojsbrSubmissionQueueReport.ui.daysOverdue', ['days' => $review['daysOverdue']]);
        }
        return implode(' — ', $parts);
    }

    /**
     * The name of a column.
     */
    public static function label(string $id): string
    {
        return __('plugins.generic.ojsbrSubmissionQueueReport.column.' . $id);
    }
}
