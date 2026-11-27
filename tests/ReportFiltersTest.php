<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/tests/ReportFiltersTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReportFiltersTest
 *
 * @brief The filters of a request: only what belongs to the journal and to the user
 *        reaches a query.
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\tests;

use APP\plugins\generic\ojsbrSubmissionQueueReport\classes\QueueReport;
use APP\plugins\generic\ojsbrSubmissionQueueReport\classes\ReportFilters;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(ReportFilters::class)]
class ReportFiltersTest extends PKPTestCase
{
    protected const ALLOWED = [
        'views' => ['active', 'needs-editor', 'reviews-overdue'],
        'sectionIds' => [1, 2],
        'issueIds' => [5],
        'categoryIds' => [],
        'canFilterAssignedTo' => true,
    ];

    public function testDefaults(): void
    {
        $filters = ReportFilters::fromQuery([], self::ALLOWED);
        $this->assertSame([], $filters->views);
        $this->assertSame('dateSubmitted', $filters->orderBy);
        $this->assertSame('DESC', $filters->orderDirection);
        $this->assertSame(1, $filters->page);
        $this->assertFalse($filters->hasOtherFilters());
        $this->assertSame([], $filters->toQuery());
    }

    public function testKeepsOnlyTheViewsAndIdsOfTheJournal(): void
    {
        $filters = ReportFilters::fromQuery([
            'views' => ['active', 'declined', "x' OR 1=1"],
            'sectionIds' => ['1', '3', '-1', 'abc', '2'],
            'issueIds' => '5,6',
            'categoryIds' => ['1'],
            'mainSituations' => ['needs-editor', 'nope'],
        ], self::ALLOWED);

        $this->assertSame(['active'], $filters->views);
        $this->assertSame([1, 2], $filters->sectionIds);
        $this->assertSame([5], $filters->issueIds);
        $this->assertSame([], $filters->categoryIds);
        $this->assertSame(['needs-editor'], $filters->mainSituations);
        $this->assertContains('needs-editor', QueueReport::MAIN_SITUATIONS);
    }

    public function testAssignedToOnlyForWhoMayFilterIt(): void
    {
        $query = ['assignedTo' => ['7', '8']];
        $this->assertSame([7, 8], ReportFilters::fromQuery($query, self::ALLOWED)->assignedTo);
        $this->assertSame([], ReportFilters::fromQuery($query, ['canFilterAssignedTo' => false] + self::ALLOWED)->assignedTo);
    }

    public function testStagesOfOjsOnly(): void
    {
        $filters = ReportFilters::fromQuery(['stageIds' => ['1', '2', '3', '9']], self::ALLOWED);
        $this->assertSame([WORKFLOW_STAGE_ID_SUBMISSION, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW], $filters->stageIds);
    }

    public function testRangesAndDates(): void
    {
        $filters = ReportFilters::fromQuery([
            'daysInactive' => '999',
            'dateFrom' => '2026-12-31',
            'dateTo' => '2026-01-01',
            'searchPhrase' => str_repeat('a', 500),
            'page' => '-4',
        ], self::ALLOWED);
        $this->assertSame(ReportFilters::DAYS_INACTIVE_MAX, $filters->daysInactive);
        // A period given backwards is turned around, not emptied.
        $this->assertSame(['2026-01-01', '2026-12-31'], [$filters->dateFrom, $filters->dateTo]);
        $this->assertSame(ReportFilters::SEARCH_MAX_LENGTH, mb_strlen($filters->searchPhrase));
        $this->assertSame(1, $filters->page);
        $this->assertTrue($filters->hasOtherFilters());

        $invalid = ReportFilters::fromQuery(['dateFrom' => '2026-02-30', 'dateTo' => "2026-01-01' --", 'daysInactive' => '-3'], self::ALLOWED);
        $this->assertSame('', $invalid->dateFrom);
        $this->assertSame('', $invalid->dateTo);
        $this->assertSame(0, $invalid->daysInactive);
    }

    public function testOrderByListedColumnsOnly(): void
    {
        $filters = ReportFilters::fromQuery(['orderBy' => 'lastActivity', 'orderDirection' => 'asc'], self::ALLOWED);
        $this->assertSame(['lastActivity', 'ASC'], [$filters->orderBy, $filters->orderDirection]);
        $this->assertSame('s.date_last_activity', ReportFilters::ORDER_BY[$filters->orderBy]);

        $injected = ReportFilters::fromQuery(['orderBy' => 's.submission_id; DROP TABLE x', 'orderDirection' => 'sideways'], self::ALLOWED);
        $this->assertSame(['dateSubmitted', 'DESC'], [$injected->orderBy, $injected->orderDirection]);
    }

    public function testArraysOfArraysAreIgnored(): void
    {
        $filters = ReportFilters::fromQuery(['views' => [['active']], 'sectionIds' => [[1]], 'searchPhrase' => ['x']], self::ALLOWED);
        $this->assertSame([], $filters->views);
        $this->assertSame([], $filters->sectionIds);
        $this->assertSame('', $filters->searchPhrase);
    }
}
