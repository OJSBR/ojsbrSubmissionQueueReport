<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/classes/ReportRows.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReportRows
 *
 * @brief The rows of the report: what the editor needs to follow up each submission.
 *
 *  Everything a row shows is loaded for the whole page (or the whole chunk of an
 *  export) at once, through the repositories of the core: never a query per row of the
 *  report's own. Labels come from the core whenever the core has a short one, so they
 *  read as in the rest of OJS (and follow the journal's own renamings).
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\classes;

use APP\author\Author;
use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use Illuminate\Support\Facades\DB;
use PKP\context\Context;
use PKP\core\PKPRequest;
use PKP\core\PKPString;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\i18n\LocaleConversion;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submission\reviewRound\ReviewRound;
use PKP\submission\reviewRound\ReviewRoundDAO;
use PKP\workflow\WorkflowStageDAO;

class ReportRows
{
    /** Short label of each status of a review assignment (core only has long sentences). */
    public const REVIEW_STATUS_KEYS = [
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_AWAITING_RESPONSE => 'awaitingResponse',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_RESPONSE_OVERDUE => 'responseOverdue',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_REQUEST_RESEND => 'requestResent',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_DECLINED => 'declined',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_ACCEPTED => 'accepted',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_REVIEW_OVERDUE => 'reviewOverdue',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_RECEIVED => 'received',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_VIEWED => 'received',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_COMPLETE => 'confirmed',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_THANKED => 'confirmed',
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_CANCELLED => 'cancelled',
    ];

    /** Statuses of a review assignment that are late. */
    public const OVERDUE_STATUSES = [
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_RESPONSE_OVERDUE,
        ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_REVIEW_OVERDUE,
    ];

    /** @var ?array<int,string> Section id => title */
    protected ?array $sections = null;

    /** @var ?array<int,string> Category id => title */
    protected ?array $categories = null;

    /** @var array<string,string> Locale => name */
    protected array $localeNames = [];

    protected string $dateFormat;

    protected ?string $workflowUrl = null;

    public function __construct(
        protected Context $context,
        protected PKPRequest $request,
        protected QueueReport $report
    ) {
        $this->dateFormat = PKPString::convertStrftimeFormat((string) $context->getLocalizedDateFormatShort());
    }

    /**
     * The rows of some submissions, in the given order.
     *
     * @param array<int,string> $mainSituations Submission id => main situation
     * @param array<int,string[]> $views Submission id => ids of its dashboard views
     */
    public function build(array $mainSituations, array $views): array
    {
        $ids = array_keys($mainSituations);
        if (!$ids) {
            return [];
        }

        // The submissions of the journal only, whatever ids were asked for.
        $submissions = DB::table('submissions')
            ->where('context_id', $this->context->getId())
            ->whereIn('submission_id', $ids)
            ->get()
            ->keyBy(fn ($row) => (int) $row->submission_id)
            ->all();

        $publications = $this->getPublications($submissions);
        $editors = $this->getEditors(array_keys($submissions));
        $rounds = $this->getCurrentRounds($submissions);
        $reviews = $this->getReviews(array_map(fn (ReviewRound $round) => $round->getId(), $rounds));
        $decisions = $this->getLastDecisions(array_keys($submissions));
        $issues = $this->getIssues(array_map(fn ($publication) => (int) $publication->getData('issueId'), $publications));

        // The address of the workflow, built once: building it for each row reads the
        // journal from the database each time.
        $this->workflowUrl ??= $this->request->getDispatcher()->url(
            $this->request,
            Application::ROUTE_PAGE,
            $this->context->getPath(),
            'dashboard',
            'editorial',
            null,
            ['workflowSubmissionId' => '__SUBMISSION_ID__']
        );

        $rows = [];
        foreach ($ids as $id) {
            $submission = $submissions[$id] ?? null;
            if (!$submission) {
                continue;
            }
            $round = $rounds[$id] ?? null;
            $rows[] = $this->row(
                $submission,
                $publications[$id] ?? null,
                $mainSituations[$id],
                $views[$id] ?? [],
                $editors[$id] ?? [],
                $round,
                $round ? ($reviews[$round->getId()] ?? []) : [],
                $decisions[$id] ?? null,
                $issues
            );
        }
        return $rows;
    }

    /**
     * The current publication of each submission, with its authors (in order, with
     * their affiliations), its categories and its DOI.
     *
     * @param array<int,object> $submissions Rows of the submissions, by id
     *
     * @return array<int,Publication> Submission id => publication
     */
    protected function getPublications(array $submissions): array
    {
        $submissionOf = [];
        foreach ($submissions as $id => $submission) {
            if ($submission->current_publication_id) {
                $submissionOf[(int) $submission->current_publication_id] = $id;
            }
        }
        if (!$submissionOf) {
            return [];
        }

        $publications = BatchHydrator::where(Repo::publication()->dao, 'publication_id', array_keys($submissionOf));

        $authors = BatchHydrator::where(Repo::author()->dao, 'publication_id', array_keys($publications), 'seq');
        $affiliations = BatchHydrator::where(Repo::affiliation()->dao, 'author_id', array_keys($authors));
        $rors = [];
        $byAuthor = [];
        foreach ($affiliations as $affiliation) {
            $ror = (string) $affiliation->getRor();
            if ($ror !== '') {
                $rors[$ror] ??= Repo::ror()->getCollector()->filterByRor($ror)->getMany()->first();
                $affiliation->setData('rorObject', $rors[$ror]);
            }
            $byAuthor[(int) $affiliation->getData('authorId')][] = $affiliation;
        }
        $byPublication = [];
        foreach ($authors as $authorId => $author) {
            $publicationId = (int) $author->getData('publicationId');
            $author->setData('submissionLocale', $submissions[$submissionOf[$publicationId]]->locale ?? null);
            $author->setAffiliations($byAuthor[$authorId] ?? []);
            $byPublication[$publicationId][] = $author;
        }
        // Core orders the authors by their position only. Where positions repeat (imported
        // or migrated submissions), the order is whatever core's own query returns, so it
        // is asked to core for those publications: the order the rest of OJS shows.
        foreach ($byPublication as $publicationId => $list) {
            $positions = array_map(fn ($author) => (int) $author->getData('seq'), $list);
            if (count(array_unique($positions)) < count($positions)) {
                $order = Repo::author()->getCollector()
                    ->filterByPublicationIds([$publicationId])
                    ->getQueryBuilder()
                    ->pluck('a.author_id')
                    ->map(intval(...))
                    ->flip()
                    ->all();
                usort($list, fn ($a, $b) => ($order[$a->getId()] ?? PHP_INT_MAX) <=> ($order[$b->getId()] ?? PHP_INT_MAX));
                $byPublication[$publicationId] = $list;
            }
        }

        $categories = [];
        foreach (DB::table('publication_categories')->whereIn('publication_id', array_keys($publications))->get(['publication_id', 'category_id']) as $row) {
            $categories[(int) $row->publication_id][] = (int) $row->category_id;
        }
        $doiIds = array_filter(array_map(fn ($publication) => (int) $publication->getData('doiId'), $publications));
        $dois = $doiIds ? DB::table('dois')->whereIn('doi_id', array_values($doiIds))->pluck('doi', 'doi_id')->all() : [];

        $result = [];
        foreach ($publications as $publicationId => $publication) {
            $submissionId = $submissionOf[$publicationId];
            $publication->setData('locale', $submissions[$submissionId]->locale);
            $publication->setData('authors', $byPublication[$publicationId] ?? []);
            $publication->setData('categoryIds', $categories[$publicationId] ?? []);
            $publication->setData('doi', (string) ($dois[(int) $publication->getData('doiId')] ?? ''));
            $result[$submissionId] = $publication;
        }
        return $result;
    }

    /**
     * The row of one submission.
     *
     * @param string[] $views
     * @param string[] $editors
     * @param ReviewAssignment[] $reviews
     * @param array<int,string> $issues
     */
    protected function row(
        object $submission,
        ?Publication $publication,
        string $mainSituation,
        array $views,
        array $editors,
        ?ReviewRound $round,
        array $reviews,
        $decision,
        array $issues
    ): array {
        $locale = (string) $submission->locale;
        $stageId = (int) $submission->stage_id;
        $submissionId = (int) $submission->submission_id;

        $authors = [];
        $categories = [];
        $title = '';
        $section = '';
        $issue = '';
        $doi = '';
        if ($publication) {
            $title = $this->getTitle($publication, $locale);
            $section = $this->getSections()[(int) $publication->getData('sectionId')] ?? '';
            $issue = $issues[(int) $publication->getData('issueId')] ?? '';
            $doi = (string) $publication->getData('doi');
            $primaryContactId = (int) $publication->getData('primaryContactId');
            foreach ($publication->getData('authors') ?? [] as $author) {
                $authors[] = [
                    'name' => $author->getFullName(true, false, $locale),
                    'email' => (string) $author->getEmail(),
                    'isPrimaryContact' => (int) $author->getId() === $primaryContactId,
                    'affiliations' => $this->getAffiliationNames($author, $locale),
                ];
            }
            foreach ((array) $publication->getData('categoryIds') as $categoryId) {
                if (isset($this->getCategories()[(int) $categoryId])) {
                    $categories[] = $this->getCategories()[(int) $categoryId];
                }
            }
        }

        $reviewRows = array_map(fn (ReviewAssignment $review) => $this->reviewRow($review), $reviews);
        $counted = array_filter($reviewRows, fn (array $review) => !$review['cancelled']);
        $open = array_filter($counted, fn (array $review) => !$review['declined'] && !$review['dateCompleted'] && $review['dateDue']);
        $nextDue = $open ? min(array_column($open, 'dateDue')) : '';

        $dateSubmitted = (string) $submission->date_submitted;
        $lastActivity = (string) $submission->date_last_activity;

        return [
            'id' => $submissionId,
            'url' => str_replace('__SUBMISSION_ID__', (string) $submissionId, $this->workflowUrl),
            'title' => $title,
            'mainSituation' => $mainSituation,
            'views' => $views,
            'stageId' => $stageId,
            'stage' => __((string) WorkflowStageDAO::getTranslationKeyFromId($stageId)),
            'locale' => $locale,
            'localeName' => $this->getLocaleName($locale),
            'section' => $section,
            'dateSubmitted' => $this->date($dateSubmitted),
            'dateSubmittedText' => $this->formatDate($dateSubmitted),
            'daysSinceSubmission' => $this->daysSince($dateSubmitted),
            'lastActivity' => $this->date($lastActivity),
            'lastActivityText' => $this->formatDate($lastActivity),
            'daysInactive' => $this->daysSince($lastActivity),
            'authors' => $authors,
            'editors' => $editors,
            'round' => $round?->getRound(),
            'roundStatus' => $round ? $this->getRoundStatus($round) : '',
            'reviewsRequired' => $round ? $this->report->getRequiredReviews() : null,
            'reviewsAccepted' => count(array_filter($counted, fn (array $review) => !$review['declined'] && $review['dateConfirmed'])),
            'reviewsCompleted' => count(array_filter($counted, fn (array $review) => (bool) $review['dateCompleted'])),
            'reviewsOverdue' => count(array_filter($counted, fn (array $review) => $review['overdue'])),
            'reviewsDeclined' => count(array_filter($counted, fn (array $review) => $review['declined'])),
            'nextDue' => $nextDue,
            'nextDueText' => $this->formatDate($nextDue),
            'reviews' => $reviewRows,
            'lastDecision' => $decision ? $decision['label'] : '',
            'lastDecisionDate' => $decision ? $this->date($decision['date']) : '',
            'lastDecisionDateText' => $decision ? $this->formatDate($decision['date']) : '',
            'issue' => $issue,
            'categories' => $categories,
            'doi' => $doi,
        ];
    }

    /**
     * What the report shows of a review assignment.
     */
    protected function reviewRow(ReviewAssignment $review): array
    {
        $status = (int) $review->getStatus();
        $recommendation = $review->getRecommendation();
        $daysOverdue = null;
        if ($status === ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_RESPONSE_OVERDUE) {
            $daysOverdue = $this->daysSince((string) $review->getDateResponseDue());
        } elseif ($status === ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_REVIEW_OVERDUE) {
            $daysOverdue = $this->daysSince((string) $review->getDateDue());
        }

        return [
            'id' => (int) $review->getId(),
            'reviewer' => (string) $review->getReviewerFullName(),
            'status' => self::REVIEW_STATUS_KEYS[$status] ?? 'awaitingResponse',
            'statusLabel' => __('plugins.generic.ojsbrSubmissionQueueReport.reviewStatus.' . (self::REVIEW_STATUS_KEYS[$status] ?? 'awaitingResponse')),
            'recommendation' => $recommendation === null || $recommendation === '' ? '' : (string) $review->getLocalizedRecommendation(),
            'round' => (int) $review->getRound(),
            'declined' => (bool) $review->getDeclined(),
            'cancelled' => $status === ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_CANCELLED,
            'overdue' => in_array($status, self::OVERDUE_STATUSES, true),
            'daysOverdue' => $daysOverdue,
            'dateAssigned' => $this->date((string) $review->getDateAssigned()),
            'dateConfirmed' => $this->date((string) $review->getDateConfirmed()),
            'dateDue' => $this->date((string) $review->getDateDue()),
            'dateDueText' => $this->formatDate((string) $review->getDateDue()),
            'dateResponseDue' => $this->date((string) $review->getDateResponseDue()),
            // The deadline that matters now: to answer the request, then to send the review.
            'deadlineText' => $this->formatDate((string) ($review->getDateConfirmed() ? $review->getDateDue() : $review->getDateResponseDue())),
            'deadlineIsResponse' => !$review->getDateConfirmed(),
            'dateCompleted' => $this->date((string) $review->getDateCompleted()),
        ];
    }

    /**
     * The title in the language of the submission, or else in the journal's language,
     * or else in the first language it has.
     */
    protected function getTitle($publication, string $locale): string
    {
        $titles = array_filter((array) $publication->getFullTitles('text'), fn ($title) => trim((string) $title) !== '');
        foreach ([$locale, $this->context->getPrimaryLocale()] as $preferred) {
            if (isset($titles[$preferred])) {
                return (string) $titles[$preferred];
            }
        }
        return (string) (reset($titles) ?: '');
    }

    /**
     * The editors assigned to each submission (managers and section editors, the roles
     * "Needs editor" looks for), by name.
     *
     * @param int[] $submissionIds
     *
     * @return array<int,string[]> Submission id => names
     */
    protected function getEditors(array $submissionIds): array
    {
        $assignments = DB::table('stage_assignments AS sa')
            ->join('user_groups AS ug', 'ug.user_group_id', '=', 'sa.user_group_id')
            ->whereIn('sa.submission_id', $submissionIds)
            ->whereIn('ug.role_id', QueueReport::EDITOR_ROLES)
            ->select(['sa.submission_id', 'sa.user_id'])
            ->distinct()
            ->orderBy('sa.submission_id')
            ->orderBy('sa.user_id')
            ->get();
        if ($assignments->isEmpty()) {
            return [];
        }

        $names = $this->getUserNames($assignments->pluck('user_id')->map(intval(...))->all());

        $editors = [];
        foreach ($assignments as $assignment) {
            if (isset($names[(int) $assignment->user_id])) {
                $editors[(int) $assignment->submission_id][] = $names[(int) $assignment->user_id];
            }
        }
        return $editors;
    }

    /**
     * The affiliation names of an author, as Author::getLocalizedAffiliationNamesAsString()
     * gives them: the ROR name when there is one, else the name in the first language
     * of the order the core follows (the submission's, the journal's, the site's, any).
     *
     * Asking the core for it loads the whole author again from the database for each
     * affiliation (Affiliation::getDefaultLocale()), so the same order is applied here.
     */
    protected function getAffiliationNames(Author $author, string $locale): string
    {
        $precedence = array_values(array_unique(array_filter([
            $locale,
            $author->getData('submissionLocale'),
            $this->context->getPrimaryLocale(),
            $this->request->getSite()?->getPrimaryLocale(),
        ])));
        $names = [];
        foreach ($author->getAffiliations() as $affiliation) {
            $ror = $affiliation->getData('rorObject');
            if ($ror) {
                $names[] = (string) $ror->getLocalizedName(LocaleConversion::getIso1FromLocale($locale));
                continue;
            }
            $values = (array) $affiliation->getData('name');
            $name = '';
            foreach ($precedence as $candidate) {
                if (!empty($values[$candidate])) {
                    $name = (string) $values[$candidate];
                    break;
                }
            }
            if ($name === '') {
                $name = (string) (array_values(array_filter($values))[0] ?? '');
            }
            $names[] = $name;
        }
        return implode('; ', $names);
    }

    /**
     * The full names of some users, as the core writes them (preferred public name, or
     * given and family names, in the language of the interface or the first one).
     *
     * @param int[] $userIds
     *
     * @return array<int,string> User id => name
     */
    protected function getUserNames(array $userIds): array
    {
        $names = [];
        foreach (BatchHydrator::where(Repo::user()->dao, 'user_id', $userIds) as $id => $user) {
            $names[$id] = $user->getFullName();
        }
        return $names;
    }

    /**
     * The current review round of each submission: the last round of the stage the
     * submission is in, as the dashboard counts it.
     *
     * @param array<int,object> $submissions Rows of the submissions, by id
     *
     * @return array<int,ReviewRound> Submission id => round
     */
    protected function getCurrentRounds(array $submissions): array
    {
        if (!$submissions) {
            return [];
        }
        $stages = [];
        foreach ($submissions as $id => $submission) {
            $stages[$id] = (int) $submission->stage_id;
        }

        /** @var ReviewRoundDAO $reviewRoundDao */
        $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO');
        $rows = DB::table('review_rounds')
            ->whereIn('submission_id', array_keys($stages))
            ->orderBy('round', 'desc')
            ->get();

        $rounds = [];
        foreach ($rows as $row) {
            $submissionId = (int) $row->submission_id;
            if (!isset($rounds[$submissionId]) && (int) $row->stage_id === $stages[$submissionId]) {
                $rounds[$submissionId] = $reviewRoundDao->_fromRow((array) $row);
            }
        }
        return $rounds;
    }

    /**
     * The review assignments of some rounds, in the order they were made.
     *
     * @param int[] $reviewRoundIds
     *
     * @return array<int,ReviewAssignment[]> Review round id => assignments
     */
    protected function getReviews(array $reviewRoundIds): array
    {
        if (!$reviewRoundIds) {
            return [];
        }
        $reviews = [];
        $assignments = BatchHydrator::where(Repo::reviewAssignment()->dao, 'review_round_id', array_values($reviewRoundIds));
        $names = $this->getUserNames(array_map(fn (ReviewAssignment $assignment) => (int) $assignment->getReviewerId(), $assignments));
        foreach ($assignments as $assignment) {
            $assignment->setReviewerFullName($names[(int) $assignment->getReviewerId()] ?? '');
            $reviews[(int) $assignment->getReviewRoundId()][] = $assignment;
        }
        foreach ($reviews as &$list) {
            usort($list, fn (ReviewAssignment $a, ReviewAssignment $b) => [(string) $a->getDateAssigned(), $a->getId()] <=> [(string) $b->getDateAssigned(), $b->getId()]);
        }
        return $reviews;
    }

    /**
     * The last editorial decision on each submission, with its label.
     *
     * @param int[] $submissionIds
     *
     * @return array<int,array{label:string,date:string}>
     */
    protected function getLastDecisions(array $submissionIds): array
    {
        $last = [];
        $decisions = Repo::decision()->getCollector()->filterBySubmissionIds($submissionIds)->getMany();
        foreach ($decisions as $decision) {
            $submissionId = (int) $decision->getData('submissionId');
            $key = [(string) $decision->getData('dateDecided'), (int) $decision->getId()];
            if (!isset($last[$submissionId]) || $key > $last[$submissionId]['key']) {
                $type = Repo::decision()->getDecisionType((int) $decision->getData('decision'));
                $last[$submissionId] = [
                    'key' => $key,
                    'label' => $type ? $type->getLabel() : '',
                    'date' => (string) $decision->getData('dateDecided'),
                ];
            }
        }
        return array_map(fn (array $item) => ['label' => $item['label'], 'date' => $item['date']], $last);
    }

    /**
     * The identification of some issues (volume, number, year, title).
     *
     * @param int[] $issueIds
     *
     * @return array<int,string> Issue id => identification
     */
    protected function getIssues(array $issueIds): array
    {
        $issueIds = array_values(array_unique(array_filter($issueIds)));
        if (!$issueIds) {
            return [];
        }
        $issues = [];
        $collection = Repo::issue()->getCollector()
            ->filterByContextIds([$this->context->getId()])
            ->filterByIssueIds($issueIds)
            ->getMany();
        foreach ($collection as $issue) {
            $issues[(int) $issue->getId()] = (string) $issue->getIssueIdentification();
        }
        return $issues;
    }

    /**
     * Titles of the journal's sections.
     *
     * @return array<int,string>
     */
    public function getSections(): array
    {
        if ($this->sections === null) {
            $this->sections = [];
            $sections = Repo::section()->getCollector()->filterByContextIds([$this->context->getId()])->getMany();
            foreach ($sections as $section) {
                $this->sections[(int) $section->getId()] = (string) $section->getLocalizedTitle();
            }
        }
        return $this->sections;
    }

    /**
     * Titles of the journal's categories.
     *
     * @return array<int,string>
     */
    public function getCategories(): array
    {
        if ($this->categories === null) {
            $this->categories = [];
            $categories = Repo::category()->getCollector()->filterByContextIds([$this->context->getId()])->getMany();
            foreach ($categories as $category) {
                $this->categories[(int) $category->getId()] = (string) $category->getLocalizedTitle();
            }
        }
        return $this->categories;
    }

    /**
     * The status of a review round, with the core's label, without the final period.
     */
    protected function getRoundStatus(ReviewRound $round): string
    {
        $key = $round->getStatusKey();
        return $key ? rtrim(__($key), '. ') : '';
    }

    /**
     * The name of a language, in the language of the interface.
     */
    protected function getLocaleName(string $locale): string
    {
        if ($locale === '') {
            return '';
        }
        if (!isset($this->localeNames[$locale])) {
            $this->localeNames[$locale] = Locale::getSubmissionLocaleDisplayNames([$locale])[$locale] ?? $locale;
        }
        return $this->localeNames[$locale];
    }

    /**
     * A date as Y-m-d, or an empty string.
     */
    protected function date(string $value): string
    {
        $time = $value !== '' ? strtotime($value) : false;
        return $time ? date('Y-m-d', $time) : '';
    }

    /**
     * A date in the journal's short date format, or an empty string.
     */
    protected function formatDate(string $value): string
    {
        $time = $value !== '' ? strtotime($value) : false;
        return $time ? date($this->dateFormat, $time) : '';
    }

    /**
     * Whole days from a date to today, or null without a date.
     */
    protected function daysSince(string $value): ?int
    {
        $time = $value !== '' ? strtotime($value) : false;
        if (!$time) {
            return null;
        }
        $from = new \DateTimeImmutable(date('Y-m-d', $time));
        $today = new \DateTimeImmutable(date('Y-m-d'));
        return (int) $from->diff($today)->format('%r%a');
    }
}
