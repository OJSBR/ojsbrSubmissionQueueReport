<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/classes/QueueReportHandler.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QueueReportHandler
 *
 * @brief The report page, the data the page asks for and the export.
 *
 *  Only the roles of the editorial dashboard get in, and every request (page, data and
 *  export) builds its queries with the user's own access: section editors and
 *  assistants only ever get the submissions they are assigned to.
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\classes;

use APP\components\forms\dashboard\SubmissionFilters;
use APP\core\Application;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\plugins\generic\ojsbrSubmissionQueueReport\OjsbrSubmissionQueueReportPlugin;
use APP\template\TemplateManager;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldText;
use PKP\core\JSONMessage;
use PKP\core\PKPRequest;
use PKP\core\PKPString;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\submission\DashboardView;
use PKP\workflow\WorkflowStageDAO;

class QueueReportHandler extends Handler
{
    /** @copydoc PKPHandler::_isBackendPage */
    public $_isBackendPage = true;

    /** Submissions per page of the table. */
    public const PER_PAGE = 30;

    /** Submissions loaded at a time by the export. */
    public const EXPORT_CHUNK = 100;

    /** The two shapes of the export. */
    public const MODE_SUBMISSIONS = 'submissions';
    public const MODE_REVIEWS = 'reviews';

    protected ?QueueReport $report = null;

    public function __construct(protected OjsbrSubmissionQueueReportPlugin $plugin)
    {
        parent::__construct();
        $this->addRoleAssignment(QueueReport::EDITORIAL_ROLES, ['index', 'data', 'export']);
    }

    /**
     * Only the roles of the editorial dashboard, in the journal of the request.
     *
     * @param PKPRequest $request
     * @param array $args
     * @param array $roleAssignments
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * The report page.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function index($args, $request)
    {
        $this->setupTemplate($request);
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->setState(['ojsbrSubmissionQueueReport' => $this->getPageConfig($request)]);
        $templateMgr->assign([
            'pageComponent' => 'Page',
            'pageTitle' => __('plugins.generic.ojsbrSubmissionQueueReport.pageTitle'),
            'pageWidth' => TemplateManager::PAGE_WIDTH_FULL,
        ]);

        $assetsUrl = $request->getBaseUrl() . '/' . $this->plugin->getPluginPath();
        // The date of each file in the address, so that browsers never keep an old copy.
        $version = fn (string $file) => is_file($path = $this->plugin->getPluginPath() . '/' . $file) ? (string) filemtime($path) : '';
        $templateMgr->addStyleSheet(
            'ojsbrSubmissionQueueReport',
            $assetsUrl . '/css/submissionQueueReport.css?v=' . $version('css/submissionQueueReport.css'),
            ['contexts' => 'backend']
        );
        // After the core scripts, so that the component is registered before the page mounts.
        $templateMgr->addJavaScript(
            'ojsbrSubmissionQueueReport',
            $assetsUrl . '/js/submissionQueueReport.js?v=' . $version('js/submissionQueueReport.js'),
            ['contexts' => 'backend', 'priority' => TemplateManager::STYLE_SEQUENCE_LAST]
        );

        $templateMgr->display($this->plugin->getTemplateResource('report.tpl'));
    }

    /**
     * The totals and one page of the table, for the filters of the query.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function data($args, $request): JSONMessage
    {
        $report = $this->getReport($request);
        $filters = $this->getFilters($request);

        $mainCounts = $report->getMainSituationCounts($filters);
        $total = array_sum($filters->mainSituations
            ? array_intersect_key($mainCounts, array_flip($filters->mainSituations))
            : $mainCounts);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $filters->page = min($filters->page, $lastPage);

        $page = $report->getPage($filters, ($filters->page - 1) * self::PER_PAGE, self::PER_PAGE);
        $rows = (new ReportRows($request->getContext(), $request, $report))
            ->build($page, $report->getViewsOf(array_keys($page)));

        return new JSONMessage(true, [
            'generatedAt' => $this->formatDateTime($request, time()),
            'viewCounts' => $report->getViewCounts($filters),
            'mainSituationCounts' => $mainCounts,
            'total' => $total,
            'page' => $filters->page,
            'lastPage' => $lastPage,
            'rows' => $rows,
        ]);
    }

    /**
     * Download every submission of the filters, as CSV or XLSX: one row per submission,
     * or one row per review assignment of the current round.
     *
     * @param array $args
     * @param PKPRequest $request
     */
    public function export($args, $request)
    {
        $context = $request->getContext();
        $report = $this->getReport($request);
        $filters = $this->getFilters($request);
        $mode = $request->getUserVar('mode') === self::MODE_REVIEWS ? self::MODE_REVIEWS : self::MODE_SUBMISSIONS;
        $format = $request->getUserVar('format') === SpreadsheetWriter::FORMAT_XLSX && SpreadsheetWriter::canWriteXlsx()
            ? SpreadsheetWriter::FORMAT_XLSX
            : SpreadsheetWriter::FORMAT_CSV;

        set_time_limit(0);
        $rowsBuilder = new ReportRows($context, $request, $report);
        $export = new ReportExport($report, $rowsBuilder);
        $fileName = $this->getExportFileName($request);

        $writer = new SpreadsheetWriter($format);
        $writer->open($fileName);
        $writer->row([$this->describeFilters($request, $filters, $rowsBuilder)]);
        $writer->row($export->getHeader($mode), true);
        // The order is taken once, then the submissions are loaded a chunk at a time.
        foreach (array_chunk($report->getOrderedIds($filters), self::EXPORT_CHUNK) as $ids) {
            $page = $report->getMainSituationsOf($ids);
            $rows = $rowsBuilder->build($page, $report->getViewsOf($ids));
            foreach ($export->getRows($rows, $mode) as $cells) {
                $writer->row($cells);
            }
        }
        $writer->close($fileName);
        exit;
    }

    /**
     * The report for the user of the request.
     */
    protected function getReport(PKPRequest $request): QueueReport
    {
        if (!$this->report) {
            $context = $request->getContext();
            $user = $request->getUser();
            $roles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
            $this->report = new QueueReport($context, $user, array_values(array_intersect(QueueReport::EDITORIAL_ROLES, $roles)));
        }
        return $this->report;
    }

    /**
     * The filters of the request, checked against the journal and the user.
     */
    protected function getFilters(PKPRequest $request): ReportFilters
    {
        $report = $this->getReport($request);
        $query = $request->getQueryArray();
        $filters = ReportFilters::fromQuery($query, $report->getAllowedIds() + [
            'views' => array_keys($report->getViews()),
            'canFilterAssignedTo' => $report->canAccessAll(),
        ]);
        if (!$filters->views && $report->getDefaultViewId()) {
            $filters->views = [$report->getDefaultViewId()];
        }
        return $filters;
    }

    /**
     * Everything the page needs to start: addresses, views, labels, columns and the
     * filters form of the dashboard with the report's own filters added.
     */
    protected function getPageConfig(PKPRequest $request): array
    {
        $context = $request->getContext();
        $report = $this->getReport($request);
        $t = fn (string $key, array $params = []) => __('plugins.generic.ojsbrSubmissionQueueReport.' . $key, $params);

        $views = [];
        foreach ($report->getViews() as $id => $view) {
            $views[] = [
                'id' => $id,
                'name' => $view->getData()['name'],
                'dashboardUrl' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, 'dashboard', 'editorial', null, ['currentViewId' => $id]),
            ];
        }

        $mainSituations = [];
        foreach (QueueReport::MAIN_SITUATIONS as $id) {
            $mainSituations[] = ['id' => $id, 'name' => self::getSituationName($id)];
        }

        $exportFormats = [['value' => SpreadsheetWriter::FORMAT_CSV, 'label' => $t('export.csv')]];
        if (SpreadsheetWriter::canWriteXlsx()) {
            $exportFormats[] = ['value' => SpreadsheetWriter::FORMAT_XLSX, 'label' => $t('export.xlsx')];
        }

        return [
            'dataUrl' => $this->plugin->getPageUrl($request, 'data'),
            'exportUrl' => $this->plugin->getPageUrl($request, 'export'),
            'pageUrl' => $this->plugin->getPageUrl($request),
            'perPage' => self::PER_PAGE,
            'defaultView' => $report->getDefaultViewId(),
            'views' => $views,
            'mainSituations' => $mainSituations,
            'filtersForm' => $this->getFiltersForm($request, $report)->getConfig(),
            'columns' => ReportExport::getScreenColumns(),
            'exportModes' => [
                ['value' => self::MODE_SUBMISSIONS, 'label' => $t('export.modeSubmissions')],
                ['value' => self::MODE_REVIEWS, 'label' => $t('export.modeReviews')],
            ],
            'exportFormats' => $exportFormats,
            'i18n' => $this->getUiStrings(),
            'journalPath' => $context->getPath(),
        ];
    }

    /**
     * The filters form of the editorial dashboard (section, assigned to, issue,
     * category, days of inactivity), with the situations, the stages and the period of
     * submission of the report.
     */
    protected function getFiltersForm(PKPRequest $request, QueueReport $report): SubmissionFilters
    {
        $context = $request->getContext();
        $userRoles = (array) $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        $form = new SubmissionFilters(
            $context,
            $userRoles,
            Repo::section()->getCollector()->filterByContextIds([$context->getId()])->getMany(),
            Repo::category()->getCollector()->filterByContextIds([$context->getId()])->getMany()
        );
        $form->id = 'ojsbrSubmissionQueueReportFilters';

        $situations = [];
        foreach ($report->getViews() as $id => $view) {
            $situations[] = ['value' => $id, 'label' => $view->getData()['name']];
        }
        $form->addField(new FieldOptions('views', [
            'groupId' => 'default',
            'label' => __('plugins.generic.ojsbrSubmissionQueueReport.filter.situation'),
            'description' => __('plugins.generic.ojsbrSubmissionQueueReport.filter.situationDescription'),
            'options' => $situations,
            'value' => array_filter([$report->getDefaultViewId()]),
        ]), $form->fields ? [FIELD_POSITION_BEFORE, reset($form->fields)->name] : []);

        $stages = [];
        foreach (ReportFilters::STAGE_IDS as $stageId) {
            $stages[] = ['value' => $stageId, 'label' => __((string) WorkflowStageDAO::getTranslationKeyFromId($stageId))];
        }
        $form->addField(new FieldOptions('stageIds', [
            'groupId' => 'default',
            'label' => __('workflow.stage'),
            'options' => $stages,
            'value' => [],
        ]));
        $form->addField(new FieldText('dateFrom', [
            'groupId' => 'default',
            'label' => __('plugins.generic.ojsbrSubmissionQueueReport.filter.dateFrom'),
            'inputType' => 'date',
            'size' => 'small',
            'value' => '',
        ]));
        $form->addField(new FieldText('dateTo', [
            'groupId' => 'default',
            'label' => __('plugins.generic.ojsbrSubmissionQueueReport.filter.dateTo'),
            'inputType' => 'date',
            'size' => 'small',
            'value' => '',
        ]));

        return $form;
    }

    /**
     * The name of a main situation: the name of its dashboard view, or the report's own
     * name for the two situations that are not views.
     */
    public static function getSituationName(string $id): string
    {
        return match ($id) {
            QueueReport::SITUATION_REVIEW_OTHER => __('plugins.generic.ojsbrSubmissionQueueReport.situation.reviewOther'),
            QueueReport::SITUATION_SUBMISSION => __('plugins.generic.ojsbrSubmissionQueueReport.situation.submission'),
            DashboardView::TYPE_DECLINED => __('submission.dashboard.view.declined'),
            DashboardView::TYPE_PUBLISHED => __('submission.dashboard.view.published'),
            DashboardView::TYPE_SCHEDULED => __('submission.dashboard.view.scheduled'),
            DashboardView::TYPE_NEEDS_EDITOR => __('submission.dashboard.view.needsEditor'),
            DashboardView::TYPE_PRODUCTION => __('submission.dashboard.view.production'),
            DashboardView::TYPE_COPYEDITING => __('submission.dashboard.view.copyediting'),
            DashboardView::TYPE_REVISIONS_SUBMITTED => __('submission.dashboard.view.revisionsSubmitted'),
            DashboardView::TYPE_REVISIONS_REQUESTED => __('submission.dashboard.view.revisionsRequested'),
            DashboardView::TYPE_REVIEWS_OVERDUE => __('submission.dashboard.view.reviewsOverdue'),
            DashboardView::TYPE_REVIEWS_SUBMITTED => __('submission.dashboard.view.reviewsSubmitted'),
            DashboardView::TYPE_AWAITING_REVIEWS => __('submission.dashboard.view.awaitingReviews'),
            DashboardView::TYPE_NEEDS_REVIEWS => __('submission.dashboard.view.needsReviews'),
            default => $id,
        };
    }

    /**
     * The first line of the export: when, by whom and with which filters.
     */
    protected function describeFilters(PKPRequest $request, ReportFilters $filters, ReportRows $rows): string
    {
        $report = $this->getReport($request);
        $t = fn (string $key, array $params = []) => __('plugins.generic.ojsbrSubmissionQueueReport.' . $key, $params);
        $views = $report->getViews();
        $parts = [];
        $names = fn (array $ids, array $map) => implode(', ', array_filter(array_map(fn ($id) => $map[$id] ?? null, $ids)));

        $parts[] = $t('filter.situation') . ': ' . implode(', ', array_map(fn ($id) => isset($views[$id]) ? $views[$id]->getData()['name'] : $id, $filters->views));
        if ($filters->mainSituations) {
            $parts[] = $t('column.mainSituation') . ': ' . implode(', ', array_map(self::getSituationName(...), $filters->mainSituations));
        }
        if ($filters->sectionIds) {
            $parts[] = __('section.section') . ': ' . $names($filters->sectionIds, $rows->getSections());
        }
        if ($filters->assignedTo) {
            $users = [];
            foreach (Repo::user()->getCollector()->filterByUserIds($filters->assignedTo)->getMany() as $user) {
                $users[$user->getId()] = $user->getFullName();
            }
            $parts[] = __('editor.submissions.assignedTo') . ': ' . $names($filters->assignedTo, $users);
        }
        if ($filters->issueIds) {
            $issues = [];
            foreach (Repo::issue()->getCollector()->filterByContextIds([$request->getContext()->getId()])->filterByIssueIds($filters->issueIds)->getMany() as $issue) {
                $issues[$issue->getId()] = $issue->getIssueIdentification();
            }
            $parts[] = __('issue.issues') . ': ' . $names($filters->issueIds, $issues);
        }
        if ($filters->categoryIds) {
            $parts[] = __('category.category') . ': ' . $names($filters->categoryIds, $rows->getCategories());
        }
        if ($filters->stageIds) {
            $parts[] = __('workflow.stage') . ': ' . implode(', ', array_map(fn ($id) => __((string) WorkflowStageDAO::getTranslationKeyFromId($id)), $filters->stageIds));
        }
        if ($filters->daysInactive) {
            $parts[] = __('submission.list.daysSinceLastActivity') . ': ' . $filters->daysInactive;
        }
        if ($filters->dateFrom !== '' || $filters->dateTo !== '') {
            $parts[] = $t('filter.period', ['from' => $filters->dateFrom ?: '…', 'to' => $filters->dateTo ?: '…']);
        }
        if ($filters->searchPhrase !== '') {
            $parts[] = __('common.search') . ': ' . $filters->searchPhrase;
        }

        return $t('export.firstLine', [
            'journal' => $request->getContext()->getLocalizedName(),
            'date' => $this->formatDateTime($request, time()),
            'user' => $request->getUser()->getFullName(),
            'filters' => implode(' | ', $parts),
        ]);
    }

    /**
     * Name of the export file: report, journal, date and time.
     */
    protected function getExportFileName(PKPRequest $request): string
    {
        $prefix = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', __('plugins.generic.ojsbrSubmissionQueueReport.export.filePrefix'))));
        $journal = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $request->getContext()->getPath());
        return trim($prefix, '-') . '-' . $journal . '-' . date('Ymd-Hi');
    }

    /**
     * A date and time in the journal's short format.
     */
    protected function formatDateTime(PKPRequest $request, int $time): string
    {
        $format = PKPString::convertStrftimeFormat((string) $request->getContext()->getLocalizedDateTimeFormatShort());
        return date($format, $time);
    }

    /**
     * The texts of the page.
     *
     * @return array<string,string>
     */
    protected function getUiStrings(): array
    {
        $keys = [
            'generatedAt', 'export', 'exportTitle', 'exportMode', 'exportFormat', 'download', 'cancel',
            'filtersApply', 'filtersClear', 'filtersShow', 'filtersHide', 'activeFilters',
            'viewsHeading', 'viewsOverlap', 'viewsFiltered', 'openInDashboard',
            'mainHeading', 'mainDescription', 'mainTotal', 'mainClear', 'mainFilteredBy',
            'search', 'columns', 'columnsReset', 'showCancelled', 'loading', 'loadError', 'retry',
            'empty', 'total', 'pageOf', 'openWorkflow', 'contact', 'none', 'daysAgo', 'days',
            'daysOverdue', 'due', 'responseDue', 'round', 'reviewsSummary', 'reviewsAccepted',
            'reviewsCompleted', 'reviewsOverdue', 'reviewsDeclined', 'noRound', 'noReviews', 'recommendation',
            'tableLabel', 'percent',
        ];
        $strings = [];
        foreach ($keys as $key) {
            $strings[$key] = __('plugins.generic.ojsbrSubmissionQueueReport.ui.' . $key);
        }
        foreach (ReportExport::getScreenColumns() as $column) {
            $strings['column.' . $column['id']] = $column['label'];
        }
        return $strings;
    }
}
