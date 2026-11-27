<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/tests/QueueReportTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QueueReportTest
 *
 * @brief The report against the database of the installation (read only): for every
 *        journal, as a manager and as a section editor, each total is the number core
 *        gives the side menu of the dashboard, the main situations add up, nobody sees
 *        a submission they are not assigned to and no submission is listed twice.
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\tests;

use APP\core\Application;
use APP\core\PageRouter;
use APP\facades\Repo;
use APP\plugins\generic\ojsbrSubmissionQueueReport\classes\QueueReport;
use APP\plugins\generic\ojsbrSubmissionQueueReport\classes\ReportFilters;
use APP\plugins\generic\ojsbrSubmissionQueueReport\classes\ReportRows;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\context\Context;
use PKP\core\Dispatcher;
use PKP\core\Registry;
use PKP\security\Role;
use PKP\submission\DashboardView;
use PKP\tests\PKPTestCase;

#[CoversClass(QueueReport::class)]
#[CoversClass(ReportRows::class)]
class QueueReportTest extends PKPTestCase
{
    protected function getMockedRegistryKeys(): array
    {
        return [...parent::getMockedRegistryKeys(), 'request', 'user'];
    }

    /**
     * Journals with users of a role, as [context, user id] pairs.
     *
     * @return array<int,array{0:Context,1:int}>
     */
    protected function usersWithRole(int $roleId, bool $withAssignments = false): array
    {
        $pairs = [];
        foreach (Application::getContextDAO()->getAll(true)->toIterator() as $context) {
            $q = DB::table('user_user_groups AS uug')
                ->join('user_groups AS ug', 'ug.user_group_id', '=', 'uug.user_group_id')
                ->join('users AS u', 'u.user_id', '=', 'uug.user_id')
                ->where('ug.context_id', $context->getId())
                ->where('ug.role_id', $roleId)
                ->where('u.disabled', 0)
                ->where(fn ($q) => $q->whereNull('uug.date_start')->orWhere('uug.date_start', '<=', now()))
                ->where(fn ($q) => $q->whereNull('uug.date_end')->orWhere('uug.date_end', '>', now()));
            if ($withAssignments) {
                $q->whereExists(fn ($sub) => $sub->from('stage_assignments AS sa')->whereColumn('sa.user_id', 'uug.user_id'));
            }
            if ($roleId !== Role::ROLE_ID_MANAGER) {
                // Someone who is also a manager of the journal (or an admin) sees every submission.
                $q->whereNotExists(fn ($sub) => $sub->from('user_user_groups AS uug2')
                    ->join('user_groups AS ug2', 'ug2.user_group_id', '=', 'uug2.user_group_id')
                    ->whereColumn('uug2.user_id', 'uug.user_id')
                    ->whereIn('ug2.role_id', QueueReport::UNASSIGNED_ACCESS_ROLES)
                    ->where(fn ($w) => $w->where('ug2.context_id', $context->getId())->orWhereNull('ug2.context_id')));
            }
            $userId = $q->orderBy('uug.user_id')->value('uug.user_id');
            if ($userId) {
                $pairs[] = [$context, (int) $userId];
            }
        }
        return $pairs;
    }

    /** A page request of the journal, signed in as the user, as the report runs. */
    protected function report(Context $context, int $userId): QueueReport
    {
        Registry::delete('request');
        Registry::delete('user');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PATH_INFO'] = '/' . $context->getPath() . '/submissionQueueReport';
        $application = Application::get();
        $request = $application->getRequest();
        $router = new PageRouter();
        $router->setApplication($application);
        $dispatcher = new Dispatcher();
        $dispatcher->addRouterName('\APP\core\PageRouter', Application::ROUTE_PAGE);
        $dispatcher->setApplication($application);
        $router->setDispatcher($dispatcher);
        $request->setRouter($router);
        $request->getSessionGuard()->setUserId($userId);

        $roleIds = DB::table('user_user_groups AS uug')
            ->join('user_groups AS ug', 'ug.user_group_id', '=', 'uug.user_group_id')
            ->where('uug.user_id', $userId)
            ->where('ug.context_id', $context->getId())
            ->pluck('ug.role_id')->map(intval(...))->all();
        return new QueueReport($context, Repo::user()->get($userId, true), array_values(array_intersect(QueueReport::EDITORIAL_ROLES, $roleIds)));
    }

    protected function noFilters(QueueReport $report, array $views = []): ReportFilters
    {
        return ReportFilters::fromQuery(['views' => $views], $report->getAllowedIds() + [
            'views' => array_keys($report->getViews()),
            'canFilterAssignedTo' => $report->canAccessAll(),
        ]);
    }

    /** The users the counts are checked for: a manager and a section editor of each journal. */
    protected function users(): array
    {
        $users = array_merge($this->usersWithRole(Role::ROLE_ID_MANAGER), $this->usersWithRole(Role::ROLE_ID_SUB_EDITOR, true));
        if (!$users) {
            $this->markTestSkipped('No journal with editors in this database.');
        }
        return $users;
    }

    public function testEveryTotalIsTheNumberOfTheDashboard(): void
    {
        foreach ($this->users() as [$context, $userId]) {
            $report = $this->report($context, $userId);
            $core = Repo::submission()->getDashboardViews($context, Repo::user()->get($userId, true), QueueReport::EDITORIAL_ROLES, true);
            $counts = $report->getViewCounts($this->noFilters($report));
            $this->assertNotEmpty($counts);

            foreach ($core as $id => $view) {
                $this->assertArrayHasKey($id, $counts, "{$context->getPath()}/{$userId}: view {$id} missing.");
                $this->assertSame($view->getCount(), $counts[$id], "{$context->getPath()}/{$userId}: count of {$id}.");
            }
            $this->assertArrayHasKey(DashboardView::TYPE_REVISIONS_REQUESTED, $counts);
        }
    }

    public function testTotalsWithFiltersAreTheTotalsOfEachViewFiltered(): void
    {
        foreach ($this->users() as [$context, $userId]) {
            $report = $this->report($context, $userId);
            foreach ([['stageIds' => ['3']], ['daysInactive' => '30'], ['dateFrom' => '2000-01-01', 'stageIds' => ['1', '3']], ['searchPhrase' => 'a']] as $query) {
                $filters = ReportFilters::fromQuery($query, $report->getAllowedIds() + ['views' => array_keys($report->getViews()), 'canFilterAssignedTo' => $report->canAccessAll()]);
                // Counted from the review criteria where they apply, or each view queried.
                $this->assertSame($report->getViewCounts($filters, true), $report->getViewCounts($filters), "{$context->getPath()}/{$userId} " . json_encode($query));
            }
        }
    }

    public function testRevisionsRequestedIsTheCurrentRoundWaitingForTheAuthor(): void
    {
        foreach ($this->usersWithRole(Role::ROLE_ID_MANAGER) as [$context, $userId]) {
            $report = $this->report($context, $userId);
            // Independent SQL: current round of the current review stage with revisions
            // requested or resubmit for review, submission still in progress.
            $expected = DB::table('submissions AS s')
                ->where('s.context_id', $context->getId())
                ->where('s.status', Submission::STATUS_QUEUED)
                ->whereIn('s.stage_id', Application::get()->getReviewStages())
                ->whereExists(fn ($q) => $q->from('review_rounds AS rr')
                    ->whereColumn('rr.submission_id', 's.submission_id')
                    ->whereColumn('rr.stage_id', 's.stage_id')
                    ->whereIn('rr.status', [1, 2])
                    ->whereRaw('rr.round = (SELECT MAX(r2.round) FROM review_rounds r2 WHERE r2.submission_id = s.submission_id AND r2.stage_id = s.stage_id)'))
                ->count();
            $this->assertSame($expected, $report->getViewCounts($this->noFilters($report))[DashboardView::TYPE_REVISIONS_REQUESTED]);
        }
    }

    public function testMainSituationsAddUpAndNothingIsListedTwice(): void
    {
        foreach ($this->users() as [$context, $userId]) {
            $report = $this->report($context, $userId);
            $counts = $report->getViewCounts($this->noFilters($report));
            foreach ($counts as $id => $count) {
                $filters = $this->noFilters($report, [$id]);
                $main = $report->getMainSituationCounts($filters);
                $this->assertSame(QueueReport::MAIN_SITUATIONS, array_keys($main));
                $this->assertSame($count, array_sum($main), "{$context->getPath()}/{$userId}: main situations of {$id}.");
                $page = $report->getPage($filters, 0, 100000);
                $this->assertCount($count, $page, "{$context->getPath()}/{$userId}: rows of {$id}.");
            }

            // Several views at once: every submission once.
            $all = $report->getPage($this->noFilters($report, array_keys($counts)), 0, 100000);
            $ids = DB::table('submissions')->whereIn('submission_id', array_keys($all) ?: [0])->pluck('submission_id')->all();
            $this->assertCount(count($all), array_unique($ids));
        }
    }

    public function testSectionEditorsOnlyGetTheirSubmissions(): void
    {
        $editors = $this->usersWithRole(Role::ROLE_ID_SUB_EDITOR, true);
        if (!$editors) {
            $this->markTestSkipped('No section editor with assignments in this database.');
        }
        foreach ($editors as [$context, $userId]) {
            $report = $this->report($context, $userId);
            $this->assertFalse($report->canAccessAll(), "{$context->getPath()}/{$userId} sees every submission.");
            $this->assertArrayNotHasKey(DashboardView::TYPE_NEEDS_EDITOR, $report->getViews());
            $this->assertArrayNotHasKey(DashboardView::TYPE_DECLINED, $report->getViews());

            $assigned = DB::table('stage_assignments AS sa')
                ->join('user_groups AS ug', 'ug.user_group_id', '=', 'sa.user_group_id')
                ->where('sa.user_id', $userId)
                ->whereIn('ug.role_id', QueueReport::EDITORIAL_ROLES)
                ->pluck('sa.submission_id')->map(intval(...))->unique()->all();
            $listed = array_keys($report->getPage($this->noFilters($report, array_keys($report->getViews())), 0, 100000));
            $this->assertSame([], array_values(array_diff($listed, $assigned)), "{$context->getPath()}/{$userId} sees submissions not assigned to them.");

            // A view the user does not have, asked for anyway, is not applied.
            $forced = ReportFilters::fromQuery(['views' => [DashboardView::TYPE_NEEDS_EDITOR]], $report->getAllowedIds() + ['views' => array_keys($report->getViews())]);
            $this->assertSame([], $forced->views);
        }
    }

    public function testTheListsOfEachSubmissionAreTheListsOfTheDashboard(): void
    {
        foreach ($this->users() as [$context, $userId]) {
            $report = $this->report($context, $userId);
            $ids = array_keys($report->getPage($this->noFilters($report, array_keys($report->getViews())), 0, 100000));
            foreach (array_chunk($ids, 100) as $chunk) {
                // The lists taken from the review criteria are the lists each view's own query gives.
                $this->assertSame($report->getViewsOf($chunk, true), $report->getViewsOf($chunk), "{$context->getPath()}/{$userId}");
            }
        }
    }

    public function testRowsShowTheCurrentRoundOnly(): void
    {
        foreach ($this->usersWithRole(Role::ROLE_ID_MANAGER) as [$context, $userId]) {
            $report = $this->report($context, $userId);
            $filters = $this->noFilters($report, [DashboardView::TYPE_ACTIVE]);
            $page = $report->getPage($filters, 0, 30);
            $rows = (new ReportRows($context, Application::get()->getRequest(), $report))->build($page, $report->getViewsOf(array_keys($page)));
            $this->assertSame(array_keys($page), array_column($rows, 'id'));

            foreach ($rows as $row) {
                $this->assertSame($page[$row['id']], $row['mainSituation']);
                $this->assertContains(DashboardView::TYPE_ACTIVE, $row['views']);
                foreach ($row['reviews'] as $review) {
                    $this->assertSame($row['round'], $review['round'], "Submission {$row['id']}: a review of another round.");
                }
                $this->assertSame(count(array_unique(array_column($row['reviews'], 'id'))), count($row['reviews']));
            }
        }
    }
}
