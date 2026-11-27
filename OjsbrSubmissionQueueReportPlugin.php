<?php

/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/OjsbrSubmissionQueueReportPlugin.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OjsbrSubmissionQueueReportPlugin
 *
 * @brief A report of the journal's submissions for editors: totals per dashboard view,
 *  a table with what the editor needs to follow up each submission (assigned editors,
 *  current round, reviewers and their deadlines, last decision, days without activity)
 *  and a CSV export of exactly what is filtered on the screen.
 *
 *  The views, their criteria and their counts are the ones of the editorial dashboard
 *  (PKP\submission\Repository::getDashboardViews()), so the report and the side menu of
 *  the dashboard always show the same numbers.
 *
 *  Inspired by PKP's own reports (Statistics > Reports) and by the editorial dashboard of
 *  OJS 3.5, whose views and filters it reuses; it replaces OJSBR's "artigosEmFila" report
 *  for OJS 3.3, which only downloaded a CSV.
 */

namespace APP\plugins\generic\ojsbrSubmissionQueueReport;

use APP\core\Application;
use APP\plugins\generic\ojsbrSubmissionQueueReport\classes\QueueReportHandler;
use APP\plugins\generic\ojsbrSubmissionQueueReport\classes\QueueReportLinkPlugin;
use APP\template\TemplateManager;
use PKP\core\PKPRequest;
use PKP\db\DAORegistry;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\security\RoleDAO;

class OjsbrSubmissionQueueReportPlugin extends GenericPlugin
{
    /** The page of the report. */
    public const PAGE = 'submissionQueueReport';

    /** Roles of the editorial dashboard: the only ones that can open the report. */
    public const EDITORIAL_ROLES = [
        Role::ROLE_ID_SITE_ADMIN,
        Role::ROLE_ID_MANAGER,
        Role::ROLE_ID_SUB_EDITOR,
        Role::ROLE_ID_ASSISTANT,
    ];

    /**
     * Register the plugin and its hooks. The report is a page of the backend, so the
     * hooks are only needed when the plugin is enabled in the journal of the request.
     *
     * @param string $category
     * @param string $path
     * @param null|int $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success || Application::isUnderMaintenance()) {
            return $success;
        }

        // OPS has no review stage and OMP has other review views: the report is for OJS.
        if (Application::get()->getName() === 'ojs2' && $this->getEnabled($mainContextId)) {
            Hook::add('LoadHandler', $this->callbackLoadHandler(...));
            Hook::add('TemplateManager::setupBackendPage', $this->callbackSetupBackendPage(...));
            Hook::add('PluginRegistry::categoryLoaded::reports', $this->callbackReportsLoaded(...));
        }

        return $success;
    }

    /**
     * Name shown in the plugins list.
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.ojsbrSubmissionQueueReport.displayName');
    }

    /**
     * Description shown in the plugins list.
     */
    public function getDescription(): string
    {
        return __('plugins.generic.ojsbrSubmissionQueueReport.description');
    }

    /**
     * Serve the report page.
     *
     * @param string $hookName
     * @param array $args [&$page, &$op, &$sourceFile, &$handler]
     */
    public function callbackLoadHandler($hookName, $args): bool
    {
        $page = $args[0];
        $handler = &$args[3];
        if ($page !== self::PAGE || !Application::get()->getRequest()->getContext()) {
            return Hook::CONTINUE;
        }

        $handler = new QueueReportHandler($this);
        return Hook::ABORT;
    }

    /**
     * Add the report to the Statistics part of the backend menu. Assistants have no
     * Statistics menu, so for them the report is a menu item of its own.
     *
     * The hook is called without arguments.
     */
    public function callbackSetupBackendPage($hookName, $args): bool
    {
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context || !$user || !$this->getUserRoleIds($user->getId(), $context->getId())) {
            return Hook::CONTINUE;
        }

        $templateMgr = TemplateManager::getManager($request);
        $menu = $templateMgr->getState('menu');
        if (!is_array($menu) || !$menu) {
            return Hook::CONTINUE;
        }

        $item = [
            'name' => __('plugins.generic.ojsbrSubmissionQueueReport.menu'),
            'url' => $this->getPageUrl($request),
            'isCurrent' => $request->getRequestedPage() === self::PAGE,
        ];

        if (isset($menu['statistics']['submenu']) && is_array($menu['statistics']['submenu'])) {
            $menu['statistics']['submenu'] = [self::PAGE => $item] + $menu['statistics']['submenu'];
        } else {
            $menu[self::PAGE] = $item + ['icon' => 'Statistics'];
        }
        $templateMgr->setState(['menu' => $menu]);

        return Hook::CONTINUE;
    }

    /**
     * List the report in Statistics > Reports too, where the report of OJS 3.3 was, as a
     * link to the report page.
     *
     * @hook PluginRegistry::categoryLoaded::reports [[&$plugins]]
     */
    public function callbackReportsLoaded($hookName, $args): bool
    {
        $plugins = &$args[0];
        $link = new QueueReportLinkPlugin($this);
        $plugins[$link->getName()] = $link;
        return Hook::CONTINUE;
    }

    /**
     * Address of the report page, with optional query parameters.
     */
    public function getPageUrl(PKPRequest $request, ?string $op = null, array $params = []): string
    {
        return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, null, self::PAGE, $op, null, $params ?: null);
    }

    /**
     * The editorial roles the user has in the journal (none: no access to the report).
     *
     * @return int[]
     */
    public function getUserRoleIds(int $userId, int $contextId): array
    {
        /** @var RoleDAO $roleDao */
        $roleDao = DAORegistry::getDAO('RoleDAO');
        $roleIds = array_map(fn (Role $role) => $role->getRoleId(), $roleDao->getByUserId($userId, $contextId));
        return array_values(array_intersect(self::EDITORIAL_ROLES, $roleIds));
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\ojsbrSubmissionQueueReport\OjsbrSubmissionQueueReportPlugin', '\OjsbrSubmissionQueueReportPlugin');
}
