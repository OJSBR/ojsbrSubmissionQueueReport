<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/classes/QueueReportLinkPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QueueReportLinkPlugin
 *
 * @brief The entry of the report in Statistics > Reports, the place of the report in
 *  OJS 3.3. It is not installed on its own: the generic plugin adds it to the list of
 *  report plugins, and opening it leads to the report page.
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport\classes;

use APP\plugins\generic\ojsbrSubmissionQueueReport\OjsbrSubmissionQueueReportPlugin;
use PKP\plugins\ReportPlugin;

class QueueReportLinkPlugin extends ReportPlugin
{
    public function __construct(protected OjsbrSubmissionQueueReportPlugin $plugin)
    {
        parent::__construct();
        $this->pluginPath = $plugin->getPluginPath();
        $this->pluginCategory = 'reports';
    }

    /**
     * Name of the entry in the list of reports.
     */
    public function getName(): string
    {
        return 'OjsbrSubmissionQueueReportLink';
    }

    /**
     * Name shown in the list of reports.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.ojsbrSubmissionQueueReport.menu');
    }

    /**
     * Description shown in the list of reports.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.ojsbrSubmissionQueueReport.description');
    }

    /**
     * Open the report page.
     *
     * @param array $args
     * @param \PKP\core\PKPRequest $request
     */
    public function display($args, $request)
    {
        $request->redirectUrl($this->plugin->getPageUrl($request));
    }
}
