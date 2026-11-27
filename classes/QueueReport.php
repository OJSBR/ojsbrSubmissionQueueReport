<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/classes/QueueReport.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QueueReport
 *
 * @brief The queries of the report.
 *
 *  Every situation of the report is a view of the editorial dashboard, with the very
 *  collector core builds for it in PKP\submission\Repository::getDashboardViews(), so
 *  each total of the report is the number of the dashboard's side menu. The other
 *  filters (section, issue, editor, period...) narrow every view the same way.
 *
 *  Besides the views, which overlap, each submission gets one "main situation", the
 *  first one that applies in self::MAIN_SITUATIONS, so that a summary adds up to the
 *  number of submissions listed.
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\classes;

use APP\facades\Repo;
use APP\submission\Collector;
use APP\submission\Submission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\submission\DashboardView;
use PKP\user\User;

class QueueReport
{
    /** Roles of the editorial dashboard, the roles core builds its views for. */
    public const EDITORIAL_ROLES = [
        Role::ROLE_ID_SITE_ADMIN,
        Role::ROLE_ID_MANAGER,
        Role::ROLE_ID_SUB_EDITOR,
        Role::ROLE_ID_ASSISTANT,
    ];

    /** Roles that see every submission of the journal, as in Repository::getDashboardViews(). */
    public const UNASSIGNED_ACCESS_ROLES = [
        Role::ROLE_ID_SITE_ADMIN,
        Role::ROLE_ID_MANAGER,
    ];

    /** Roles of the editors in charge of a submission (the ones "Needs editor" looks for). */
    public const EDITOR_ROLES = [
        Role::ROLE_ID_MANAGER,
        Role::ROLE_ID_SUB_EDITOR,
    ];

    /** View the report opens with: the submissions in progress, as the report of OJS 3.3. */
    public const DEFAULT_VIEW = DashboardView::TYPE_ACTIVE;

    /** Main situation of a submission in review that fits none of the review views. */
    public const SITUATION_REVIEW_OTHER = 'review-other';

    /** Main situation of a submission in the submission stage. */
    public const SITUATION_SUBMISSION = 'submission';

    /**
     * The main situations, in order of precedence: a submission takes the first one
     * that applies. Each one but the last two is a view of the dashboard.
     */
    public const MAIN_SITUATIONS = [
        DashboardView::TYPE_DECLINED,
        DashboardView::TYPE_PUBLISHED,
        DashboardView::TYPE_SCHEDULED,
        DashboardView::TYPE_NEEDS_EDITOR,
        DashboardView::TYPE_PRODUCTION,
        DashboardView::TYPE_COPYEDITING,
        DashboardView::TYPE_REVISIONS_SUBMITTED,
        DashboardView::TYPE_REVISIONS_REQUESTED,
        DashboardView::TYPE_REVIEWS_OVERDUE,
        DashboardView::TYPE_REVIEWS_SUBMITTED,
        DashboardView::TYPE_AWAITING_REVIEWS,
        DashboardView::TYPE_NEEDS_REVIEWS,
        self::SITUATION_REVIEW_OTHER,
        self::SITUATION_SUBMISSION,
    ];

    /** @var ?array<string,DashboardView> */
    protected ?array $views = null;

    /**
     * @param int[] $roleIds The editorial roles of the user in the journal
     */
    public function __construct(
        public Context $context,
        public User $user,
        public array $roleIds
    ) {
    }

    /**
     * Whether the user sees every submission of the journal, not only the assigned ones.
     */
    public function canAccessAll(): bool
    {
        return (bool) array_intersect(self::UNASSIGNED_ACCESS_ROLES, $this->roleIds);
    }

    /**
     * The views of the editorial dashboard the user has, in the order of the side menu.
     *
     * Core builds them; "Revisions requested", which core only builds for authors, is
     * added with the rules core uses for "Revisions submitted" (the same view the
     * revisionsRequestedView plugin adds to the dashboard); views other plugins add
     * through the Dashboard::views hook are kept too, with a collector built from their
     * query parameters the way the dashboard API reads them.
     *
     * @return array<string,DashboardView>
     *
     * @hook Dashboard::views [[&$viewsData, $userRoles]]
     */
    public function getViews(): array
    {
        if ($this->views !== null) {
            return $this->views;
        }

        $views = Repo::submission()
            ->getDashboardViews($this->context, $this->user, self::EDITORIAL_ROLES)
            ->filter(fn ($view) => $view instanceof DashboardView && $view->getCollector() instanceof Collector)
            ->all();

        if (!isset($views[DashboardView::TYPE_REVISIONS_REQUESTED])) {
            $views = $this->insertBefore(
                $views,
                DashboardView::TYPE_REVISIONS_SUBMITTED,
                $this->getRevisionsRequestedView()
            );
        }

        $viewsData = array_values(array_map(fn (DashboardView $view) => $view->getData(), $views));
        $userRoles = $this->roleIds;
        Hook::call('Dashboard::views', [&$viewsData, $userRoles]);

        $ordered = [];
        foreach ($viewsData as $data) {
            $id = is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : null;
            if ($id === null || isset($ordered[$id])) {
                continue;
            }
            if (isset($views[$id])) {
                $ordered[$id] = $views[$id];
                continue;
            }
            $collector = $this->getCollectorFromViewData($data);
            if ($collector) {
                $ordered[$id] = new DashboardView(
                    $id,
                    (string) ($data['name'] ?? $id),
                    self::EDITORIAL_ROLES,
                    $collector,
                    $data['op'] ?? null,
                    (array) ($data['queryParams'] ?? [])
                );
            }
        }
        // Keep core's views even if a plugin took them out of the dashboard's list.
        $this->views = $ordered + $views;

        return $this->views;
    }

    /**
     * The view to open with: "Active" or, if the user does not have it, the first view.
     */
    public function getDefaultViewId(): ?string
    {
        $views = $this->getViews();
        return isset($views[self::DEFAULT_VIEW]) ? self::DEFAULT_VIEW : (array_key_first($views) ?? null);
    }

    /**
     * "Revisions requested" for editors: the submissions whose current review round
     * waits for the author's revisions.
     */
    public function getRevisionsRequestedView(): DashboardView
    {
        $assignedWithRoles = $this->canAccessAll() ? null : self::EDITORIAL_ROLES;
        $collector = Repo::submission()->getCollector()
            ->filterByContextIds([$this->context->getId()])
            ->filterByRevisionsRequested(true)
            ->filterByStatus([Submission::STATUS_QUEUED]);
        if (!$this->canAccessAll()) {
            $collector->assignedTo([$this->user->getId()], $assignedWithRoles);
        }

        return new DashboardView(
            DashboardView::TYPE_REVISIONS_REQUESTED,
            __('submission.dashboard.view.revisionsRequested'),
            self::EDITORIAL_ROLES,
            $collector,
            'reviews',
            ['revisionsRequested' => true, 'assignedWithRoles' => $assignedWithRoles]
        );
    }

    /**
     * Build the collector of a view added by a plugin from its query parameters, as the
     * endpoints of the dashboard API (`_submissions`, `_submissions/assigned` and
     * `_submissions/reviews`) read them.
     */
    public function getCollectorFromViewData(array $data): ?Collector
    {
        $params = is_array($data['queryParams'] ?? null) ? $data['queryParams'] : [];
        $op = $data['op'] ?? null;
        $ints = fn ($value) => array_values(array_map('intval', is_array($value) ? $value : explode(',', (string) $value)));

        $collector = Repo::submission()->getCollector()->filterByContextIds([$this->context->getId()]);
        foreach ($params as $name => $value) {
            match ($name) {
                'status' => $collector->filterByStatus($ints($value)),
                'stageIds' => $collector->filterByStageIds($ints($value)),
                'categoryIds' => $collector->filterByCategoryIds($ints($value)),
                'sectionIds' => $collector->filterBySectionIds($ints($value)),
                'issueIds' => $collector->filterByIssueIds($ints($value)),
                'daysInactive' => $collector->filterByDaysInactive((int) $value),
                'isOverdue', 'reviewsOverdue' => $collector->filterByReviewsOverdue(true),
                'isIncomplete' => $collector->filterByIncomplete(true),
                'isUnassigned' => $collector->filterByisUnassigned(true),
                'needsReviews' => $collector->filterByNumReviewsConfirmedLimit($this->getRequiredReviews()),
                'awaitingReviews' => $collector->filterByAwaitingReviews(true),
                'reviewsSubmitted' => $collector->filterByReviewsSubmitted(true),
                'revisionsRequested' => $collector->filterByRevisionsRequested(true),
                'revisionsSubmitted' => $collector->filterByRevisionsSubmitted(true),
                default => null,
            };
        }

        $withRoles = isset($params['assignedWithRoles']) ? ($ints($params['assignedWithRoles']) ?: null) : null;
        if ($op === 'reviews') {
            $collector->filterByStatus([Submission::STATUS_QUEUED])
                ->filterByStageIds([WORKFLOW_STAGE_ID_INTERNAL_REVIEW, WORKFLOW_STAGE_ID_EXTERNAL_REVIEW]);
        }
        if ($op === 'assigned' || !$this->canAccessAll()) {
            $collector->assignedTo([$this->user->getId()], $withRoles ?? self::EDITORIAL_ROLES);
        }

        return $collector;
    }

    /**
     * The number of reviews a submission needs, as core counts it for "Needs reviewers".
     */
    public function getRequiredReviews(): int
    {
        $count = $this->context->getNumReviewsPerSubmission();
        return $count == Context::REVIEWS_DEFAULT_COUNT ? Context::REVIEWS_REQUIRED_COUNT : (int) $count;
    }

    /**
     * Ids of the journal's sections, issues and categories, to check the filters with.
     *
     * @return array{sectionIds:int[],issueIds:int[],categoryIds:int[]}
     */
    public function getAllowedIds(): array
    {
        $contextId = $this->context->getId();
        return [
            'sectionIds' => DB::table('sections')->where('journal_id', $contextId)->pluck('section_id')->map(intval(...))->all(),
            'issueIds' => DB::table('issues')->where('journal_id', $contextId)->pluck('issue_id')->map(intval(...))->all(),
            'categoryIds' => DB::table('categories')->where('context_id', $contextId)->pluck('category_id')->map(intval(...))->all(),
        ];
    }

    /**
     * The submissions the user may see in the report at all: the whole journal for
     * managers and admins, the assigned submissions for section editors and assistants
     * (the same rule core applies to every view).
     */
    public function getScopeCollector(): Collector
    {
        $collector = Repo::submission()->getCollector()->filterByContextIds([$this->context->getId()]);
        if (!$this->canAccessAll()) {
            $collector->assignedTo([$this->user->getId()], self::EDITORIAL_ROLES);
        }
        return $collector;
    }

    /**
     * The query of a collector narrowed by the filters other than the situation.
     */
    public function applyFilters(Collector $collector, ReportFilters $filters): Builder
    {
        $collector = clone $collector;
        if ($filters->sectionIds) {
            $collector->filterBySectionIds($filters->sectionIds);
        }
        if ($filters->issueIds) {
            $collector->filterByIssueIds($filters->issueIds);
        }
        if ($filters->categoryIds) {
            $collector->filterByCategoryIds($filters->categoryIds);
        }
        if ($filters->daysInactive) {
            $collector->filterByDaysInactive($filters->daysInactive);
        }
        if ($filters->searchPhrase !== '') {
            $collector->searchPhrase($filters->searchPhrase);
        }

        $q = $collector->getQueryBuilder();

        // The stage is filtered on the query, so that it narrows the stage of a view
        // (Copyediting, Production...) instead of replacing it.
        if ($filters->stageIds) {
            $q->whereIn('s.stage_id', $filters->stageIds);
        }
        if ($filters->dateFrom !== '') {
            $q->where('s.date_submitted', '>=', $filters->dateFrom . ' 00:00:00');
        }
        if ($filters->dateTo !== '') {
            $q->where('s.date_submitted', '<=', $filters->dateTo . ' 23:59:59');
        }
        // "Assigned to" of the dashboard filters: editors assigned in any editorial role.
        if ($filters->assignedTo && $this->canAccessAll()) {
            $q->whereIn(
                's.submission_id',
                fn (Builder $sub) => $sub->select('sa.submission_id')
                    ->from('stage_assignments AS sa')
                    ->join('user_groups AS ug', 'ug.user_group_id', '=', 'sa.user_group_id')
                    ->whereIn('sa.user_id', $filters->assignedTo)
                    ->whereIn('ug.role_id', self::EDITORIAL_ROLES)
            );
        }

        return $q;
    }

    /**
     * The number of submissions in each view, with the filters other than the
     * situation, in one query, the way core counts the views of the side menu
     * (Collector::getViewsCountBuilder()).
     *
     * @return array<string,int>
     */
    public function getViewCounts(ReportFilters $filters): array
    {
        $views = $this->getViews();
        if (!$views) {
            return [];
        }

        $q = DB::query();
        $aliases = [];
        foreach (array_keys($views) as $i => $id) {
            $alias = 'v' . $i;
            $aliases[$alias] = $id;
            $q->selectSub(
                $this->applyFilters($views[$id]->getCollector(), $filters)
                    ->select([])
                    ->selectRaw('COUNT(s.submission_id)')
                    ->reorder(),
                $alias
            );
        }

        $row = (array) $q->first();
        $counts = [];
        foreach ($aliases as $alias => $id) {
            $counts[$id] = (int) ($row[$alias] ?? 0);
        }
        return $counts;
    }

    /**
     * The query of the submissions of the selected views (any of them), narrowed by the
     * other filters. With no view selected, the default view.
     */
    public function getSelectedQuery(ReportFilters $filters, bool $withMainSituations = true): Builder
    {
        $views = $this->getViews();
        $selected = array_values(array_intersect($filters->views, array_keys($views)));
        if (!$selected) {
            $default = $this->getDefaultViewId();
            $selected = $default ? [$default] : [];
        }

        $q = $this->applyFilters($this->getScopeCollector(), $filters);
        $q->where(function (Builder $where) use ($selected, $views) {
            if (!$selected) {
                $where->whereRaw('1 = 0');
            }
            foreach ($selected as $id) {
                $where->orWhereIn('s.submission_id', $this->getIdsQuery($views[$id]->getCollector()));
            }
        });

        if ($withMainSituations && $filters->mainSituations) {
            [$case, $bindings] = $this->getMainSituationSql();
            $q->whereRaw(
                '(' . $case . ') IN (' . implode(', ', array_fill(0, count($filters->mainSituations), '?')) . ')',
                array_merge($bindings, $filters->mainSituations)
            );
        }

        return $q;
    }

    /**
     * The ids of the submissions of a collector, as a subquery.
     */
    public function getIdsQuery(Collector $collector): Builder
    {
        return (clone $collector)->getQueryBuilder()->select('s.submission_id')->reorder();
    }

    /**
     * SQL that gives the main situation of the submission `s`, with its bindings.
     *
     * The review situations use the criteria of the dashboard views for the whole
     * journal (not only the user's submissions): the situation belongs to the
     * submission, not to who looks at it.
     *
     * @return array{0:string,1:array}
     */
    public function getMainSituationSql(): array
    {
        $contextId = $this->context->getId();
        $queued = fn () => Repo::submission()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByStatus([Submission::STATUS_QUEUED]);
        $criteria = [
            DashboardView::TYPE_NEEDS_EDITOR => $queued()->filterByisUnassigned(true),
            DashboardView::TYPE_REVISIONS_SUBMITTED => $queued()->filterByRevisionsSubmitted(true),
            DashboardView::TYPE_REVISIONS_REQUESTED => $queued()->filterByRevisionsRequested(true),
            DashboardView::TYPE_REVIEWS_OVERDUE => $queued()->filterByReviewsOverdue(true),
            DashboardView::TYPE_REVIEWS_SUBMITTED => $queued()->filterByReviewsSubmitted(true),
            DashboardView::TYPE_AWAITING_REVIEWS => $queued()->filterByAwaitingReviews(true),
            DashboardView::TYPE_NEEDS_REVIEWS => $queued()->filterByNumReviewsConfirmedLimit($this->getRequiredReviews()),
        ];
        $ids = [];
        foreach ($criteria as $id => $collector) {
            $ids[$id] = $this->getIdsQuery($collector);
        }

        $sql = 'CASE';
        $bindings = [];
        $in = function (string $situation) use (&$sql, &$bindings, $ids) {
            $sql .= ' WHEN s.submission_id IN (' . $ids[$situation]->toSql() . ") THEN '" . $situation . "'";
            array_push($bindings, ...$ids[$situation]->getBindings());
        };

        $sql .= ' WHEN s.status = ' . (int) Submission::STATUS_DECLINED . " THEN '" . DashboardView::TYPE_DECLINED . "'";
        $sql .= ' WHEN s.status = ' . (int) Submission::STATUS_PUBLISHED . " THEN '" . DashboardView::TYPE_PUBLISHED . "'";
        $sql .= ' WHEN s.status = ' . (int) Submission::STATUS_SCHEDULED . " THEN '" . DashboardView::TYPE_SCHEDULED . "'";
        $in(DashboardView::TYPE_NEEDS_EDITOR);
        $sql .= ' WHEN s.stage_id = ' . (int) WORKFLOW_STAGE_ID_PRODUCTION . " THEN '" . DashboardView::TYPE_PRODUCTION . "'";
        $sql .= ' WHEN s.stage_id = ' . (int) WORKFLOW_STAGE_ID_EDITING . " THEN '" . DashboardView::TYPE_COPYEDITING . "'";
        $in(DashboardView::TYPE_REVISIONS_SUBMITTED);
        $in(DashboardView::TYPE_REVISIONS_REQUESTED);
        $in(DashboardView::TYPE_REVIEWS_OVERDUE);
        $in(DashboardView::TYPE_REVIEWS_SUBMITTED);
        $in(DashboardView::TYPE_AWAITING_REVIEWS);
        $in(DashboardView::TYPE_NEEDS_REVIEWS);
        $sql .= ' WHEN s.stage_id IN (' . (int) WORKFLOW_STAGE_ID_INTERNAL_REVIEW . ', ' . (int) WORKFLOW_STAGE_ID_EXTERNAL_REVIEW . ") THEN '" . self::SITUATION_REVIEW_OTHER . "'";
        $sql .= " ELSE '" . self::SITUATION_SUBMISSION . "' END";

        return [$sql, $bindings];
    }

    /**
     * The number of selected submissions in each main situation, whatever main
     * situations are chosen, so that the summary keeps showing all of them. Without a
     * choice of main situations the numbers add up to the number of submissions listed.
     *
     * @return array<string,int> Every main situation, in order, with its number
     */
    public function getMainSituationCounts(ReportFilters $filters): array
    {
        [$case, $bindings] = $this->getMainSituationSql();
        $sub = $this->getSelectedQuery($filters, false)
            ->select([])
            ->selectRaw($case . ' AS main_situation', $bindings)
            ->reorder();

        $counts = array_fill_keys(self::MAIN_SITUATIONS, 0);
        $rows = DB::query()->fromSub($sub, 'x')
            ->select('x.main_situation')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('x.main_situation')
            ->get();
        foreach ($rows as $row) {
            $counts[$row->main_situation] = (int) $row->total;
        }
        return $counts;
    }

    /**
     * A page of the selected submissions: their ids, in the chosen order, with their
     * main situation.
     *
     * @return array<int,string> Submission id => main situation
     */
    public function getPage(ReportFilters $filters, int $offset, int $count): array
    {
        [$case, $bindings] = $this->getMainSituationSql();
        $direction = $filters->orderDirection === Collector::ORDER_DIR_ASC ? 'asc' : 'desc';
        $rows = $this->getSelectedQuery($filters)
            ->select(['s.submission_id'])
            ->selectRaw($case . ' AS main_situation', $bindings)
            ->reorder()
            ->orderBy(ReportFilters::ORDER_BY[$filters->orderBy], $direction)
            ->orderBy('s.submission_id', $direction)
            ->offset($offset)
            ->limit($count)
            ->get();

        $page = [];
        foreach ($rows as $row) {
            $page[(int) $row->submission_id] = (string) $row->main_situation;
        }
        return $page;
    }

    /**
     * The views each submission is in, as the dashboard shows them (without the
     * filters of the report), in one query.
     *
     * @param int[] $submissionIds
     *
     * @return array<int,string[]> Submission id => ids of its views, in menu order
     */
    public function getViewsOf(array $submissionIds): array
    {
        $views = $this->getViews();
        if (!$submissionIds || !$views) {
            return array_fill_keys($submissionIds, []);
        }

        $q = DB::table('submissions AS s')
            ->select('s.submission_id')
            ->whereIn('s.submission_id', $submissionIds);
        $aliases = [];
        foreach (array_keys($views) as $i => $id) {
            $alias = 'v' . $i;
            $aliases[$alias] = $id;
            $ids = $this->getIdsQuery($views[$id]->getCollector());
            $q->selectRaw('CASE WHEN s.submission_id IN (' . $ids->toSql() . ') THEN 1 ELSE 0 END AS ' . $alias, $ids->getBindings());
        }

        $result = array_fill_keys($submissionIds, []);
        foreach ($q->get() as $row) {
            $row = (array) $row;
            foreach ($aliases as $alias => $id) {
                if ((int) $row[$alias] === 1) {
                    $result[(int) $row['submission_id']][] = $id;
                }
            }
        }
        return $result;
    }

    /**
     * Insert a view before another one, or at the end.
     *
     * @param array<string,DashboardView> $views
     *
     * @return array<string,DashboardView>
     */
    protected function insertBefore(array $views, string $beforeId, DashboardView $view): array
    {
        $position = array_search($beforeId, array_keys($views), true);
        if ($position === false) {
            $position = count($views);
        }
        return array_slice($views, 0, $position, true)
            + [$view->getData()['id'] => $view]
            + array_slice($views, $position, null, true);
    }
}
