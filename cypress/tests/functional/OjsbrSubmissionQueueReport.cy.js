/**
 * @file cypress/tests/functional/OjsbrSubmissionQueueReport.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests of the submission report, through the page: it opens from the
 * Statistics menu and from Statistics > Reports; every total is the number the side
 * menu of the dashboard gets from the API, for a manager and for a section editor;
 * choosing a list fills the table and the main situations add up to it; the CSV export
 * has the rows of the screen; and a reviewer gets no report at all.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword, sectionEditorUser,
 * sectionEditorPassword, reviewerUser, reviewerPassword (captcha on login must be off
 * for the run). The defaults match the data set of PKP's continuous integration, and the
 * first test enables the plugin when it is off.
 */

describe('Submission Queue Report', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const sectionEditorUser = Cypress.env('sectionEditorUser') || 'dbuskins';
	const sectionEditorPassword = Cypress.env('sectionEditorPassword') || 'dbuskinsdbuskins';
	const reviewerUser = Cypress.env('reviewerUser') || 'jjanssen';
	const reviewerPassword = Cypress.env('reviewerPassword') || 'jjanssenjjanssen';
	const rowName = 'ojsbrsubmissionqueuereportplugin';
	const editorialRoles = [1, 16, 17, 4097];

	// ---- OJSBR spec helpers (padrão v2) ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// ---- end of helpers ----

	const openReport = (query) => {
		cy.intercept('GET', '**/submissionQueueReport/data*').as('data');
		cy.visit(pageUrl('submissionQueueReport') + (query ? '?' + query : ''));
		cy.wait('@data', {timeout: 60000}).its('response.statusCode').should('eq', 200);
		cy.get('.ojsbrSqr__tile', {timeout: 60000}).should('have.length.greaterThan', 0);
	};

	// The counts of the dashboard's side menu, from the endpoint the dashboard itself calls.
	const dashboardCounts = () => cy.window().then((win) => cy.wrap(
		win.fetch(pageUrl('api/v1/_submissions/viewsCount') + '?' + editorialRoles.map((role) => 'assignedWithRoles[]=' + role).join('&'), {
			credentials: 'same-origin',
			headers: {'X-Csrf-Token': win.pkp.currentUser.csrfToken},
		}).then((response) => response.json()),
		{timeout: 30000}
	));

	const tileCounts = () => cy.get('.ojsbrSqr__tile').then(($tiles) => {
		const counts = {};
		$tiles.each((i, tile) => {
			counts[tile.dataset.view] = Number(tile.querySelector('.ojsbrSqr__tileCount').textContent);
		});
		return counts;
	});

	const sameCountsAsTheDashboard = () => {
		dashboardCounts().then((expected) => {
			tileCounts().then((counts) => {
				Object.keys(expected).forEach((id) => {
					expect(counts[id], 'total of ' + id).to.eq(expected[id]);
				});
			});
		});
	};

	it('Enables the plugin and opens the report from the Statistics menu and from Reports', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('a[href*="/submissionQueueReport"]', {timeout: 60000}).first().click({force: true});
		cy.get('.ojsbrSqr', {timeout: 60000}).should('be.visible');
		cy.visit(pageUrl('stats/reports'));
		cy.get('a[href*="pluginName=OjsbrSubmissionQueueReportLink"]').click();
		cy.location('pathname', {timeout: 60000}).should('match', /\/submissionQueueReport$/);
	});

	it('Shows the numbers of the dashboard to a manager', function() {
		login(adminUser, adminPassword);
		openReport();
		sameCountsAsTheDashboard();
	});

	it('Shows the numbers of the dashboard to a section editor, and only their submissions', function() {
		login(sectionEditorUser, sectionEditorPassword);
		openReport();
		sameCountsAsTheDashboard();
		cy.get('[data-view="needs-editor"]').should('not.exist');
		cy.get('[data-view="declined"]').should('not.exist');
	});

	it('Lists a dashboard list in the table, and the main situations add up', function() {
		login(adminUser, adminPassword);
		openReport();
		cy.get('[data-view="published"] .ojsbrSqr__tileCount').invoke('text').then((text) => {
			const count = Number(text);
			cy.get('[data-view="published"] .ojsbrSqr__tileButton').click();
			cy.wait('@data');
			cy.get('[data-view="published"]').should('have.class', 'is-active');
			cy.location('search').should('contain', 'views%5B%5D=published');
			cy.get('.ojsbrSqr__legendTotal strong').should('have.text', String(count));
			cy.get('.ojsbrSqr__tableWrap tbody tr').should('have.length', Math.min(count, 30) || 1);
			if (count) {
				cy.get('[data-situation="published"] .ojsbrSqr__legendCount').should('have.text', String(count));
			}
		});
	});

	it('Exports the rows of the screen as CSV', function() {
		login(adminUser, adminPassword);
		openReport('views%5B%5D=active');
		cy.wait(500);
		cy.get('.ojsbrSqr__legendTotal strong').invoke('text').then((total) => {
			request({url: pageUrl('submissionQueueReport/export') + '?views%5B%5D=active&mode=submissions&format=csv', encoding: 'binary'}).then((response) => {
				expect(response.status).to.eq(200);
				expect(response.headers['content-type']).to.contain('text/csv');
				expect(response.headers['content-disposition']).to.match(/filename="[a-z-]+-[A-Za-z0-9_-]+-\d{8}-\d{4}\.csv"/);
				// UTF-8 with a byte order mark, so that Excel shows the accents.
				expect(response.body.slice(0, 3)).to.eq('\xEF\xBB\xBF');
				// One record per submission after the line of the filters and the header; a cell
				// may hold several lines between quotes.
				let records = 0;
				let quoted = false;
				for (const character of response.body) {
					if (character === '"') {
						quoted = !quoted;
					} else if (character === '\n' && !quoted) {
						records++;
					}
				}
				expect(records).to.eq(Number(total) + 2);
				expect(response.body.split('\n')[1].split(';').length).to.be.greaterThan(20);
			});
		});
	});

	it('Gives a reviewer no report', function() {
		login(reviewerUser, reviewerPassword);
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('a[href*="/submissionQueueReport"]').should('not.exist');
		['submissionQueueReport', 'submissionQueueReport/data?views%5B%5D=active', 'submissionQueueReport/export?views%5B%5D=active&format=csv'].forEach((path) => {
			request({url: pageUrl(path), failOnStatusCode: false}).then((response) => {
				expect(response.body).not.to.contain('ojsbrSqr');
				expect(response.body).not.to.contain('"rows"');
				expect(response.body).not.to.contain('\xEF\xBB\xBF');
				expect(response.redirects ? response.redirects.join(' ') : '').to.contain('authorizationDenied');
			});
		});
	});
});
