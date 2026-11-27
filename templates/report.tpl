{**
 * plugins/generic/ojsbrSubmissionQueueReport/templates/report.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The report page: the component of js/submissionQueueReport.js, fed by the state the
 * handler sets.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle|escape}
	</h1>

	<ojsbr-submission-queue-report :config="ojsbrSubmissionQueueReport"></ojsbr-submission-queue-report>
{/block}
