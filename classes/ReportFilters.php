<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/classes/ReportFilters.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReportFilters
 *
 * @brief The filters of the report, read from the query of a request and checked
 *  against the journal: ids that do not belong to the journal and values out of range
 *  are dropped, never passed on to a query.
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\classes;

use APP\submission\Collector;

class ReportFilters
{
    /** Stages that can be filtered in OJS. */
    public const STAGE_IDS = [
        WORKFLOW_STAGE_ID_SUBMISSION,
        WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
        WORKFLOW_STAGE_ID_EDITING,
        WORKFLOW_STAGE_ID_PRODUCTION,
    ];

    /** Columns the table can be sorted by, and the column of the submission behind each. */
    public const ORDER_BY = [
        'id' => 's.submission_id',
        'dateSubmitted' => 's.date_submitted',
        'lastActivity' => 's.date_last_activity',
    ];

    /** Most days the dashboard filter of inactivity goes to. */
    public const DAYS_INACTIVE_MAX = 180;

    /** Longest search phrase passed to the search of the submissions. */
    public const SEARCH_MAX_LENGTH = 200;

    /** @var string[] Ids of the selected views (situations) */
    public array $views = [];

    /** @var string[] Main situations to list (QueueReport::MAIN_SITUATIONS) */
    public array $mainSituations = [];

    /** @var int[] */
    public array $sectionIds = [];

    /** @var int[] Editors the submissions are assigned to (managers and admins only) */
    public array $assignedTo = [];

    /** @var int[] */
    public array $issueIds = [];

    /** @var int[] */
    public array $categoryIds = [];

    /** @var int[] */
    public array $stageIds = [];

    public int $daysInactive = 0;

    public string $searchPhrase = '';

    /** First day of the period of submission (Y-m-d), or empty */
    public string $dateFrom = '';

    /** Last day of the period of submission (Y-m-d), or empty */
    public string $dateTo = '';

    public string $orderBy = 'dateSubmitted';

    public string $orderDirection = Collector::ORDER_DIR_DESC;

    public int $page = 1;

    /**
     * Read the filters from the query of a request.
     *
     * @param array $query The query parameters
     * @param array $allowed Lists of what may be chosen: 'views', 'sectionIds',
     *  'issueIds', 'categoryIds' (ids of the journal) and 'canFilterAssignedTo'
     */
    public static function fromQuery(array $query, array $allowed): self
    {
        $filters = new self();

        $filters->views = array_values(array_intersect(
            self::stringList($query['views'] ?? []),
            $allowed['views'] ?? []
        ));
        $filters->mainSituations = array_values(array_intersect(
            self::stringList($query['mainSituations'] ?? []),
            QueueReport::MAIN_SITUATIONS
        ));
        $filters->sectionIds = self::idList($query['sectionIds'] ?? [], $allowed['sectionIds'] ?? []);
        $filters->issueIds = self::idList($query['issueIds'] ?? [], $allowed['issueIds'] ?? []);
        $filters->categoryIds = self::idList($query['categoryIds'] ?? [], $allowed['categoryIds'] ?? []);
        $filters->stageIds = self::idList($query['stageIds'] ?? [], self::STAGE_IDS);
        if (!empty($allowed['canFilterAssignedTo'])) {
            $filters->assignedTo = self::idList($query['assignedTo'] ?? [], null);
        }

        $days = (int) ($query['daysInactive'] ?? 0);
        $filters->daysInactive = max(0, min(self::DAYS_INACTIVE_MAX, $days));

        $search = trim((string) (is_scalar($query['searchPhrase'] ?? null) ? $query['searchPhrase'] : ''));
        $filters->searchPhrase = mb_substr($search, 0, self::SEARCH_MAX_LENGTH);

        $filters->dateFrom = self::date($query['dateFrom'] ?? '');
        $filters->dateTo = self::date($query['dateTo'] ?? '');
        if ($filters->dateFrom && $filters->dateTo && $filters->dateFrom > $filters->dateTo) {
            [$filters->dateFrom, $filters->dateTo] = [$filters->dateTo, $filters->dateFrom];
        }

        $orderBy = (string) (is_scalar($query['orderBy'] ?? null) ? $query['orderBy'] : '');
        if (array_key_exists($orderBy, self::ORDER_BY)) {
            $filters->orderBy = $orderBy;
        }
        $direction = strtoupper((string) (is_scalar($query['orderDirection'] ?? null) ? $query['orderDirection'] : ''));
        if (in_array($direction, [Collector::ORDER_DIR_ASC, Collector::ORDER_DIR_DESC], true)) {
            $filters->orderDirection = $direction;
        }

        $filters->page = max(1, (int) ($query['page'] ?? 1));

        return $filters;
    }

    /**
     * Whether any filter besides the situation is in use.
     */
    public function hasOtherFilters(): bool
    {
        return $this->sectionIds || $this->assignedTo || $this->issueIds || $this->categoryIds
            || $this->stageIds || $this->daysInactive || $this->searchPhrase !== ''
            || $this->dateFrom !== '' || $this->dateTo !== '';
    }

    /**
     * The filters as query parameters, as the page sends them.
     */
    public function toQuery(): array
    {
        return array_filter([
            'views' => $this->views,
            'mainSituations' => $this->mainSituations,
            'sectionIds' => $this->sectionIds,
            'assignedTo' => $this->assignedTo,
            'issueIds' => $this->issueIds,
            'categoryIds' => $this->categoryIds,
            'stageIds' => $this->stageIds,
            'daysInactive' => $this->daysInactive ?: null,
            'searchPhrase' => $this->searchPhrase,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * A list of strings from a parameter given as an array or as a comma-separated string.
     *
     * @return string[]
     */
    protected static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            fn ($item) => is_scalar($item) ? trim((string) $item) : '',
            $value
        ), 'strlen')));
    }

    /**
     * A list of positive ids, kept only if they are in the allowed list (when given).
     *
     * @param ?int[] $allowed
     *
     * @return int[]
     */
    protected static function idList(mixed $value, ?array $allowed): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($item) => ctype_digit($item) ? (int) $item : 0, self::stringList($value)),
            fn (int $id) => $id > 0
        )));
        return $allowed === null ? $ids : array_values(array_intersect($ids, $allowed));
    }

    /**
     * A date in the Y-m-d format, or an empty string.
     */
    protected static function date(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }
}
