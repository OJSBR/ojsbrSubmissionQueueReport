/**
 * @file plugins/generic/ojsbrSubmissionQueueReport/js/submissionQueueReport.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * The report page: totals of the dashboard lists, summary of the main situations,
 * the filters form of the dashboard, the table and the export.
 *
 * The component is registered through pkp.registry and is made of the components of
 * the core (table, form, search, pagination, buttons). The filters live in the address
 * of the page, so a filtered report can be shared as a link.
 */
(function () {
	'use strict';

	if (!window.pkp || !pkp.registry || !pkp.modules || !pkp.modules.vue || !pkp.modules.useFiltersForm) {
		return;
	}
	var vue = pkp.modules.vue;
	var useFiltersForm = pkp.modules.useFiltersForm.useFiltersForm;

	/** Query parameters that are not fields of the filters form. */
	var OWN_PARAMS = ['searchPhrase', 'orderBy', 'orderDirection', 'page', 'mainSituations'];

	/** Days without activity from which the report calls attention to a submission. */
	var INACTIVE_WARNING = 30;

	function format(text, params) {
		return String(text).replace(/\{\$(\w+)\}/g, function (match, key) {
			return Object.prototype.hasOwnProperty.call(params || {}, key) ? params[key] : match;
		});
	}

	function hasValue(value) {
		return Array.isArray(value) ? value.length > 0 : value !== null && value !== undefined && value !== '' && value !== 0;
	}

	/** Query string with the arrays as key[]=value, the way PHP reads them. */
	function toSearch(params) {
		var search = new URLSearchParams();
		Object.keys(params).forEach(function (key) {
			var value = params[key];
			if (Array.isArray(value)) {
				value.forEach(function (item) {
					search.append(key + '[]', item);
				});
			} else if (hasValue(value)) {
				search.append(key, value);
			}
		});
		return search.toString();
	}

	/** The query of the address, with key[] as arrays. */
	function fromSearch(search) {
		var params = {};
		new URLSearchParams(search).forEach(function (value, key) {
			if (key.slice(-2) === '[]') {
				key = key.slice(0, -2);
				params[key] = (params[key] || []).concat([value]);
			} else {
				params[key] = value;
			}
		});
		return params;
	}

	function storageGet(key) {
		try {
			return JSON.parse(window.localStorage.getItem(key));
		} catch (e) {
			return null;
		}
	}

	function storageSet(key, value) {
		try {
			window.localStorage.setItem(key, JSON.stringify(value));
		} catch (e) {
			// Private windows and blocked storage: the choice just is not remembered.
		}
	}

	pkp.registry.registerComponent('OjsbrSubmissionQueueReport', {
		name: 'OjsbrSubmissionQueueReport',
		props: {
			config: {type: Object, required: true}
		},
		setup: function (props) {
			var c = props.config;
			var t = c.i18n;

			var filtersFormRef = vue.ref(JSON.parse(JSON.stringify(c.filtersForm)));
			var filters = useFiltersForm(filtersFormRef);
			var filtersForm = filters.filtersForm;

			var applied = vue.ref({});
			var searchPhrase = vue.ref('');
			var orderBy = vue.ref('dateSubmitted');
			var orderDirection = vue.ref('DESC');
			var page = vue.ref(1);
			var mainSituations = vue.ref([]);

			var loading = vue.ref(false);
			var failed = vue.ref(false);
			var result = vue.ref(null);
			var showFilters = vue.ref(false);
			var showExport = vue.ref(false);
			var exportMode = vue.ref(c.exportModes[0].value);
			var exportFormat = vue.ref(c.exportFormats[0].value);

			var columnsKey = 'ojsbrSubmissionQueueReport.columns.' + c.journalPath;
			var cancelledKey = 'ojsbrSubmissionQueueReport.showCancelled';
			var defaultColumns = c.columns.filter(function (column) {
				return column.visible;
			}).map(function (column) {
				return column.id;
			});
			var storedColumns = storageGet(columnsKey);
			var visibleColumnIds = vue.ref(Array.isArray(storedColumns) && storedColumns.length ? storedColumns : defaultColumns.slice());
			var showCancelled = vue.ref(storageGet(cancelledKey) === true);
			vue.watch(visibleColumnIds, function (value) {
				storageSet(columnsKey, value);
			}, {deep: true});
			vue.watch(showCancelled, function (value) {
				storageSet(cancelledKey, value);
			});

			var visibleColumns = vue.computed(function () {
				return c.columns.filter(function (column) {
					return visibleColumnIds.value.indexOf(column.id) !== -1;
				});
			});

			var viewNames = {};
			c.views.forEach(function (view) {
				viewNames[view.id] = view.name;
			});
			var situationNames = {};
			c.mainSituations.forEach(function (situation) {
				situationNames[situation.id] = situation.name;
			});

			function getField(name) {
				return filtersForm.value.fields.find(function (field) {
					return field.name === name;
				});
			}

			var selectedViews = vue.computed(function () {
				var values = applied.value.views || [];
				return values.length ? values : (c.defaultView ? [c.defaultView] : []);
			});

			var otherFilters = vue.computed(function () {
				// The core list also has the fields without a value (dates, texts): leave them out.
				return filters.filtersFormList.value.filter(function (item) {
					return item.name !== 'views' && hasValue(item.value);
				}).map(function (item) {
					var field = getField(item.name);
					var label = item.label === 'TODO' || (field && field.component === 'field-slider') ? item.value : item.label;
					return {name: item.name, value: item.value, text: item.fieldLabel + ': ' + label};
				});
			});

			var hasOtherFilters = vue.computed(function () {
				return Object.keys(applied.value).some(function (key) {
					return key !== 'views' && hasValue(applied.value[key]);
				}) || searchPhrase.value !== '';
			});

			/** Parameters of the data and the export: what is applied, not what is being edited. */
			function requestParams() {
				var params = Object.assign({}, applied.value);
				params.views = selectedViews.value;
				params.searchPhrase = searchPhrase.value;
				params.orderBy = orderBy.value;
				params.orderDirection = orderDirection.value;
				params.mainSituations = mainSituations.value;
				return params;
			}

			/** Keep the filters in the address, with the names of chosen people and issues. */
			function updateAddress() {
				var params = {};
				var formParams = filters.filtersFormQueryParams.value;
				Object.keys(formParams).forEach(function (key) {
					if (hasValue(formParams[key])) {
						params[key] = formParams[key];
					}
				});
				params.searchPhrase = searchPhrase.value;
				params.mainSituations = mainSituations.value;
				if (orderBy.value !== 'dateSubmitted' || orderDirection.value !== 'DESC') {
					params.orderBy = orderBy.value;
					params.orderDirection = orderDirection.value;
				}
				if (page.value > 1) {
					params.page = page.value;
				}
				var search = toSearch(params);
				window.history.replaceState(window.history.state, '', window.location.pathname + (search ? '?' + search : '') + window.location.hash);
			}

			var requestNumber = 0;
			function load() {
				var number = ++requestNumber;
				var params = requestParams();
				params.page = page.value;
				loading.value = true;
				failed.value = false;
				updateAddress();
				return fetch(c.dataUrl + (c.dataUrl.indexOf('?') === -1 ? '?' : '&') + toSearch(params), {
					credentials: 'same-origin',
					headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'}
				}).then(function (response) {
					if (!response.ok) {
						throw new Error(String(response.status));
					}
					return response.json();
				}).then(function (json) {
					if (number !== requestNumber) {
						return;
					}
					if (!json || !json.status || !json.content) {
						throw new Error('content');
					}
					result.value = json.content;
					page.value = json.content.page;
					updateAddress();
				}).catch(function () {
					if (number === requestNumber) {
						failed.value = true;
					}
				}).finally(function () {
					if (number === requestNumber) {
						loading.value = false;
					}
				});
			}

			function applyFilters() {
				applied.value = JSON.parse(JSON.stringify(filters.filtersFormQueryParamsApi.value));
				page.value = 1;
				load();
			}

			function clearFilters() {
				filters.clearFiltersForm();
				var views = getField('views');
				if (views && c.defaultView) {
					views.value = [c.defaultView];
				}
				searchPhrase.value = '';
				mainSituations.value = [];
				applyFilters();
			}

			function removeFilter(item) {
				filters.clearFiltersFormField(item.name, item.value);
				applyFilters();
			}

			/** A list of the dashboard: alone, or added and removed with Ctrl, Cmd or Shift. */
			function selectView(id, event) {
				var field = getField('views');
				if (!field) {
					return;
				}
				var current = selectedViews.value.slice();
				if (event && (event.ctrlKey || event.metaKey || event.shiftKey)) {
					var index = current.indexOf(id);
					if (index === -1) {
						current.push(id);
					} else if (current.length > 1) {
						current.splice(index, 1);
					}
				} else {
					current = [id];
				}
				field.value = current;
				mainSituations.value = [];
				applyFilters();
			}

			function toggleMainSituation(id) {
				var current = mainSituations.value.slice();
				var index = current.indexOf(id);
				if (index === -1) {
					current.push(id);
				} else {
					current.splice(index, 1);
				}
				mainSituations.value = current;
				page.value = 1;
				load();
			}

			function clearMainSituations() {
				mainSituations.value = [];
				page.value = 1;
				load();
			}

			function onSearch(phrase) {
				searchPhrase.value = phrase || '';
				page.value = 1;
				load();
			}

			var sortDescriptor = vue.computed(function () {
				return {column: orderBy.value, direction: orderDirection.value === 'ASC' ? 'ascending' : 'descending'};
			});

			function onSort(columnId) {
				var column = c.columns.find(function (item) {
					return item.id === columnId;
				});
				if (!column || !column.sort) {
					return;
				}
				if (orderBy.value === column.sort) {
					orderDirection.value = orderDirection.value === 'ASC' ? 'DESC' : 'ASC';
				} else {
					orderBy.value = column.sort;
					orderDirection.value = column.sort === 'lastActivity' ? 'ASC' : 'DESC';
				}
				page.value = 1;
				load();
			}

			function setPage(number) {
				page.value = number;
				load();
				var table = document.getElementById('ojsbr-sqr-table');
				if (table && table.scrollIntoView) {
					table.scrollIntoView({block: 'start'});
				}
			}

			function onFormSet(formId, data) {
				filters.updateFiltersForm(data);
			}

			function toggleColumn(id) {
				var list = visibleColumnIds.value.slice();
				var index = list.indexOf(id);
				if (index === -1) {
					list.push(id);
				} else if (list.length > 1) {
					list.splice(index, 1);
				}
				visibleColumnIds.value = list;
			}

			function resetColumns() {
				visibleColumnIds.value = defaultColumns.slice();
			}

			function download() {
				var params = requestParams();
				params.mode = exportMode.value;
				params.format = exportFormat.value;
				showExport.value = false;
				window.location.href = c.exportUrl + (c.exportUrl.indexOf('?') === -1 ? '?' : '&') + toSearch(params);
			}

			var rows = vue.computed(function () {
				return result.value ? result.value.rows : [];
			});

			var viewCounts = vue.computed(function () {
				return result.value ? result.value.viewCounts : {};
			});

			var mainSummary = vue.computed(function () {
				var counts = result.value ? result.value.mainSituationCounts : {};
				var total = 0;
				c.mainSituations.forEach(function (situation) {
					total += counts[situation.id] || 0;
				});
				return {
					total: total,
					items: c.mainSituations.map(function (situation) {
						var count = counts[situation.id] || 0;
						return {
							id: situation.id,
							name: situation.name,
							count: count,
							percent: total ? Math.round(count * 1000 / total) / 10 : 0,
							isActive: mainSituations.value.indexOf(situation.id) !== -1
						};
					}).filter(function (item) {
						return item.count > 0 || item.isActive;
					})
				};
			});

			function reviewsOf(row) {
				return showCancelled.value ? row.reviews : row.reviews.filter(function (review) {
					return !review.cancelled;
				});
			}

			// The address may bring filters (a shared link); otherwise the default list.
			var initial = fromSearch(window.location.search);
			if (initial.views || Object.keys(initial).some(function (key) {
				return OWN_PARAMS.indexOf(key) === -1;
			})) {
				filters.initFiltersFormFromQueryParams(initial);
			}
			searchPhrase.value = typeof initial.searchPhrase === 'string' ? initial.searchPhrase : '';
			mainSituations.value = Array.isArray(initial.mainSituations) ? initial.mainSituations : [];
			if (initial.orderBy) {
				orderBy.value = initial.orderBy;
				orderDirection.value = initial.orderDirection === 'ASC' ? 'ASC' : 'DESC';
			}
			applied.value = JSON.parse(JSON.stringify(filters.filtersFormQueryParamsApi.value));
			page.value = Math.max(1, parseInt(initial.page, 10) || 1);
			showFilters.value = hasOtherFilters.value;
			load();

			return {
				c: c,
				t: t,
				format: format,
				filtersForm: filtersForm,
				otherFilters: otherFilters,
				hasOtherFilters: hasOtherFilters,
				selectedViews: selectedViews,
				searchPhrase: searchPhrase,
				mainSituations: mainSituations,
				page: page,
				loading: loading,
				failed: failed,
				result: result,
				rows: rows,
				viewCounts: viewCounts,
				mainSummary: mainSummary,
				showFilters: showFilters,
				showExport: showExport,
				exportMode: exportMode,
				exportFormat: exportFormat,
				visibleColumns: visibleColumns,
				visibleColumnIds: visibleColumnIds,
				showCancelled: showCancelled,
				sortDescriptor: sortDescriptor,
				viewNames: viewNames,
				situationNames: situationNames,
				inactiveWarning: INACTIVE_WARNING,
				load: load,
				applyFilters: applyFilters,
				clearFilters: clearFilters,
				removeFilter: removeFilter,
				selectView: selectView,
				toggleMainSituation: toggleMainSituation,
				clearMainSituations: clearMainSituations,
				onSearch: onSearch,
				onSort: onSort,
				setPage: setPage,
				onFormSet: onFormSet,
				toggleColumn: toggleColumn,
				resetColumns: resetColumns,
				download: download,
				reviewsOf: reviewsOf
			};
		},
		template:
'<div class="ojsbrSqr">' +
'  <div class="ojsbrSqr__top">' +
'    <p class="ojsbrSqr__generated" aria-live="polite">' +
'      <span v-if="result">{{ format(t.generatedAt, {date: result.generatedAt}) }}</span>' +
'      <PkpSpinner v-if="loading"></PkpSpinner>' +
'    </p>' +
'    <div class="ojsbrSqr__exportWrap">' +
'      <PkpButton :is-primary="true" :aria-expanded="showExport ? \'true\' : \'false\'" aria-controls="ojsbr-sqr-export" @click="showExport = !showExport">{{ t.export }}</PkpButton>' +
'      <div v-if="showExport" id="ojsbr-sqr-export" class="ojsbrSqr__export" role="group" :aria-label="t.exportTitle">' +
'        <p class="ojsbrSqr__exportTitle">{{ t.exportTitle }}</p>' +
'        <fieldset><legend>{{ t.exportMode }}</legend>' +
'          <label v-for="mode in c.exportModes" :key="mode.value"><input type="radio" name="ojsbrSqrMode" :value="mode.value" v-model="exportMode"> {{ mode.label }}</label>' +
'        </fieldset>' +
'        <fieldset><legend>{{ t.exportFormat }}</legend>' +
'          <label v-for="item in c.exportFormats" :key="item.value"><input type="radio" name="ojsbrSqrFormat" :value="item.value" v-model="exportFormat"> {{ item.label }}</label>' +
'        </fieldset>' +
'        <div class="ojsbrSqr__exportButtons">' +
'          <PkpButton @click="showExport = false">{{ t.cancel }}</PkpButton>' +
'          <PkpButton :is-primary="true" data-sqr="download" @click="download">{{ t.download }}</PkpButton>' +
'        </div>' +
'      </div>' +
'    </div>' +
'  </div>' +

'  <section class="ojsbrSqr__panel" aria-labelledby="ojsbr-sqr-views">' +
'    <h2 id="ojsbr-sqr-views" class="ojsbrSqr__heading">{{ t.viewsHeading }}</h2>' +
'    <p class="ojsbrSqr__note">{{ t.viewsOverlap }} <strong v-if="hasOtherFilters">{{ t.viewsFiltered }}</strong></p>' +
'    <ul class="ojsbrSqr__tiles">' +
'      <li v-for="view in c.views" :key="view.id" class="ojsbrSqr__tile" :class="{\'is-active\': selectedViews.indexOf(view.id) !== -1, \'is-zero\': result && !viewCounts[view.id]}" :data-view="view.id">' +
'        <button type="button" class="ojsbrSqr__tileButton" :aria-pressed="selectedViews.indexOf(view.id) !== -1 ? \'true\' : \'false\'" @click="selectView(view.id, $event)">' +
'          <span class="ojsbrSqr__tileCount">{{ result ? (viewCounts[view.id] || 0) : \'–\' }}</span>' +
'          <span class="ojsbrSqr__tileName">{{ view.name }}</span>' +
'        </button>' +
'        <a class="ojsbrSqr__tileLink" :href="view.dashboardUrl" target="_blank" rel="noopener" :title="format(t.openInDashboard, {name: view.name})" :aria-label="format(t.openInDashboard, {name: view.name})"><span aria-hidden="true">↗</span></a>' +
'      </li>' +
'    </ul>' +
'  </section>' +

'  <section class="ojsbrSqr__panel" aria-labelledby="ojsbr-sqr-main">' +
'    <h2 id="ojsbr-sqr-main" class="ojsbrSqr__heading">{{ t.mainHeading }} <span class="ojsbrSqr__total" v-if="result">{{ format(t.total, {total: mainSummary.total}) }}</span></h2>' +
'    <p class="ojsbrSqr__note">{{ t.mainDescription }}</p>' +
'    <div v-if="mainSummary.total" class="ojsbrSqr__bar" aria-hidden="true">' +
'      <span v-for="item in mainSummary.items" :key="item.id" class="ojsbrSqr__barPart" :class="[\'ojsbrSqr--\' + item.id, {\'is-dimmed\': mainSituations.length && !item.isActive}]" :style="{flexGrow: item.count}" :title="item.name + \': \' + item.count"></span>' +
'    </div>' +
'    <ul class="ojsbrSqr__legend">' +
'      <li v-for="item in mainSummary.items" :key="item.id">' +
'        <button type="button" class="ojsbrSqr__legendButton" :class="{\'is-active\': item.isActive}" :aria-pressed="item.isActive ? \'true\' : \'false\'" :data-situation="item.id" @click="toggleMainSituation(item.id)">' +
'          <span class="ojsbrSqr__dot" :class="\'ojsbrSqr--\' + item.id" aria-hidden="true"></span>' +
'          <span class="ojsbrSqr__legendName">{{ item.name }}</span>' +
'          <span class="ojsbrSqr__legendCount">{{ item.count }}</span>' +
'          <span class="ojsbrSqr__legendPercent">{{ format(t.percent, {percent: item.percent}) }}</span>' +
'        </button>' +
'      </li>' +
'      <li class="ojsbrSqr__legendTotal"><span>{{ t.mainTotal }}</span> <strong>{{ mainSummary.total }}</strong></li>' +
'    </ul>' +
'    <PkpButton v-if="mainSituations.length" :is-link="true" @click="clearMainSituations">{{ t.mainClear }}</PkpButton>' +
'  </section>' +

'  <section class="ojsbrSqr__panel">' +
'    <div class="ojsbrSqr__filtersBar">' +
'      <PkpButton :aria-expanded="showFilters ? \'true\' : \'false\'" aria-controls="ojsbr-sqr-filters" @click="showFilters = !showFilters">{{ showFilters ? t.filtersHide : t.filtersShow }}</PkpButton>' +
'      <ul v-if="otherFilters.length" class="ojsbrSqr__chips" :aria-label="t.activeFilters">' +
'        <li v-for="item in otherFilters" :key="item.name + \'-\' + item.value"><button type="button" class="ojsbrSqr__chip" @click="removeFilter(item)">{{ item.text }} <span aria-hidden="true">×</span></button></li>' +
'      </ul>' +
'      <PkpButton v-if="hasOtherFilters || mainSituations.length" :is-link="true" @click="clearFilters">{{ t.filtersClear }}</PkpButton>' +
'    </div>' +
'    <div v-show="showFilters" id="ojsbr-sqr-filters" class="ojsbrSqr__filters">' +
'      <PkpForm v-bind="filtersForm" @set="onFormSet"></PkpForm>' +
'      <div class="ojsbrSqr__filtersButtons">' +
'        <PkpButton @click="clearFilters">{{ t.filtersClear }}</PkpButton>' +
'        <PkpButton :is-primary="true" data-sqr="apply" @click="applyFilters">{{ t.filtersApply }}</PkpButton>' +
'      </div>' +
'    </div>' +
'  </section>' +

'  <section class="ojsbrSqr__panel" id="ojsbr-sqr-table">' +
'    <div class="ojsbrSqr__toolbar">' +
'      <div class="ojsbrSqr__search"><PkpSearch :search-phrase="searchPhrase" :search-label="t.search" @search-phrase-changed="onSearch"></PkpSearch></div>' +
'      <span class="ojsbrSqr__count" v-if="result" aria-live="polite">{{ format(t.total, {total: result.total}) }}<template v-if="mainSituations.length"> · {{ format(t.mainFilteredBy, {names: mainSituations.map(function (id) { return situationNames[id]; }).join(\', \')}) }}</template></span>' +
'      <label class="ojsbrSqr__check"><input type="checkbox" v-model="showCancelled"> {{ t.showCancelled }}</label>' +
'      <details class="ojsbrSqr__columns">' +
'        <summary>{{ t.columns }}</summary>' +
'        <div class="ojsbrSqr__columnsList">' +
'          <label v-for="column in c.columns" :key="column.id"><input type="checkbox" :checked="visibleColumnIds.indexOf(column.id) !== -1" @change="toggleColumn(column.id)"> {{ column.label }}</label>' +
'          <PkpButton :is-link="true" @click="resetColumns">{{ t.columnsReset }}</PkpButton>' +
'        </div>' +
'      </details>' +
'    </div>' +
'    <div v-if="failed" class="ojsbrSqr__error" role="alert">{{ t.loadError }} <PkpButton @click="load">{{ t.retry }}</PkpButton></div>' +
'    <div class="ojsbrSqr__tableWrap" :class="{\'is-loading\': loading}">' +
'      <PkpTable :aria-label="t.tableLabel" :sort-descriptor="sortDescriptor" @sort="onSort">' +
'        <PkpTableHeader>' +
'          <PkpTableColumn v-for="column in visibleColumns" :key="column.id" :id="column.sort || column.id" :allows-sorting="!!column.sort">{{ column.label }}</PkpTableColumn>' +
'        </PkpTableHeader>' +
'        <PkpTableBody :empty-text="result ? t.empty : t.loading">' +
'          <PkpTableRow v-for="row in rows" :key="row.id">' +
'            <PkpTableCell v-for="column in visibleColumns" :key="column.id" :class="\'ojsbrSqr__cell--\' + column.id">' +
'              <template v-if="column.id === \'id\'"><a :href="row.url" target="_blank" rel="noopener" :aria-label="format(t.openWorkflow, {id: row.id})">{{ row.id }}</a></template>' +
'              <template v-else-if="column.id === \'title\'"><a class="ojsbrSqr__title" :href="row.url" target="_blank" rel="noopener">{{ row.title }}</a></template>' +
'              <template v-else-if="column.id === \'mainSituation\'"><span class="ojsbrSqr__situation"><span class="ojsbrSqr__dot" :class="\'ojsbrSqr--\' + row.mainSituation" aria-hidden="true"></span>{{ situationNames[row.mainSituation] }}</span></template>' +
'              <template v-else-if="column.id === \'views\'"><ul class="ojsbrSqr__plain"><li v-for="id in row.views" :key="id">{{ viewNames[id] || id }}</li></ul></template>' +
'              <template v-else-if="column.id === \'stage\'">{{ row.stage }}</template>' +
'              <template v-else-if="column.id === \'section\'">{{ row.section }}</template>' +
'              <template v-else-if="column.id === \'locale\'">{{ row.localeName }}</template>' +
'              <template v-else-if="column.id === \'dateSubmitted\'"><span class="ojsbrSqr__nowrap">{{ row.dateSubmittedText }}</span><span class="ojsbrSqr__sub" v-if="row.daysSinceSubmission !== null">{{ format(t.days, {days: row.daysSinceSubmission}) }}</span></template>' +
'              <template v-else-if="column.id === \'lastActivity\'"><span class="ojsbrSqr__nowrap">{{ row.lastActivityText }}</span><span class="ojsbrSqr__sub" :class="{\'is-warning\': row.daysInactive >= inactiveWarning}" v-if="row.daysInactive !== null">{{ format(t.daysAgo, {days: row.daysInactive}) }}</span></template>' +
'              <template v-else-if="column.id === \'authors\'"><ul class="ojsbrSqr__plain"><li v-for="(author, i) in row.authors" :key="i">{{ author.name }}<span v-if="author.isPrimaryContact" class="ojsbrSqr__sub ojsbrSqr__inline"> ({{ t.contact }})</span></li></ul></template>' +
'              <template v-else-if="column.id === \'emails\'"><ul class="ojsbrSqr__plain"><li v-for="(author, i) in row.authors" :key="i"><a v-if="author.email" :href="\'mailto:\' + author.email">{{ author.email }}</a><span v-if="author.isPrimaryContact" class="ojsbrSqr__sub ojsbrSqr__inline"> ({{ t.contact }})</span></li></ul></template>' +
'              <template v-else-if="column.id === \'affiliations\'"><ul class="ojsbrSqr__plain"><li v-for="(author, i) in row.authors" :key="i">{{ author.affiliations }}</li></ul></template>' +
'              <template v-else-if="column.id === \'editors\'"><ul v-if="row.editors.length" class="ojsbrSqr__plain"><li v-for="(name, i) in row.editors" :key="i">{{ name }}</li></ul><span v-else class="ojsbrSqr__sub is-warning">{{ t.none }}</span></template>' +
'              <template v-else-if="column.id === \'round\'">' +
'                <template v-if="row.round"><strong class="ojsbrSqr__nowrap">{{ format(t.round, {round: row.round}) }}</strong><span class="ojsbrSqr__sub">{{ row.roundStatus }}</span>' +
'                  <span class="ojsbrSqr__counters" :title="t.reviewsSummary">' +
'                    <span>{{ row.reviewsAccepted }}/{{ row.reviewsRequired }} {{ t.reviewsAccepted }}</span>' +
'                    <span>{{ row.reviewsCompleted }} {{ t.reviewsCompleted }}</span>' +
'                    <span v-if="row.reviewsOverdue" class="is-danger">{{ row.reviewsOverdue }} {{ t.reviewsOverdue }}</span>' +
'                    <span v-if="row.reviewsDeclined">{{ row.reviewsDeclined }} {{ t.reviewsDeclined }}</span>' +
'                  </span>' +
'                </template>' +
'                <span v-else class="ojsbrSqr__sub">{{ t.noRound }}</span>' +
'              </template>' +
'              <template v-else-if="column.id === \'reviewers\'">' +
'                <ul v-if="reviewsOf(row).length" class="ojsbrSqr__reviews">' +
'                  <li v-for="review in reviewsOf(row)" :key="review.id" :class="[\'is-\' + review.status]">' +
'                    <span class="ojsbrSqr__reviewer">{{ review.reviewer }}</span>' +
'                    <span class="ojsbrSqr__reviewStatus">{{ review.statusLabel }}</span>' +
'                    <span v-if="review.recommendation" class="ojsbrSqr__recommendation">{{ t.recommendation }}: {{ review.recommendation }}</span>' +
'                    <span v-if="review.dateDue && !review.dateCompleted && !review.declined && !review.cancelled" class="ojsbrSqr__sub">{{ format(review.deadlineIsResponse ? t.responseDue : t.due, {date: review.deadlineText}) }}<template v-if="review.daysOverdue"> · <span class="is-danger">{{ format(t.daysOverdue, {days: review.daysOverdue}) }}</span></template></span>' +
'                  </li>' +
'                </ul>' +
'                <span v-else-if="row.round" class="ojsbrSqr__sub">{{ t.noReviews }}</span>' +
'              </template>' +
'              <template v-else-if="column.id === \'nextDue\'"><span class="ojsbrSqr__nowrap">{{ row.nextDueText }}</span></template>' +
'              <template v-else-if="column.id === \'lastDecision\'">{{ row.lastDecision }}<span class="ojsbrSqr__sub" v-if="row.lastDecisionDateText">{{ row.lastDecisionDateText }}</span></template>' +
'              <template v-else-if="column.id === \'issue\'">{{ row.issue }}</template>' +
'              <template v-else-if="column.id === \'categories\'"><ul class="ojsbrSqr__plain"><li v-for="(name, i) in row.categories" :key="i">{{ name }}</li></ul></template>' +
'              <template v-else-if="column.id === \'doi\'"><span class="ojsbrSqr__nowrap">{{ row.doi }}</span></template>' +
'            </PkpTableCell>' +
'          </PkpTableRow>' +
'        </PkpTableBody>' +
'      </PkpTable>' +
'    </div>' +
'    <div class="ojsbrSqr__pagination" v-if="result && result.lastPage > 1">' +
'      <span class="ojsbrSqr__sub">{{ format(t.pageOf, {page: result.page, lastPage: result.lastPage}) }}</span>' +
'      <PkpPagination :current-page="page" :last-page="result.lastPage" :is-loading="loading" @set-page="setPage"></PkpPagination>' +
'    </div>' +
'  </section>' +
'</div>'
	});
})();
