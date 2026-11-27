# Writes locale/<code>/locale.po from the table below (en, pt_BR, es) and from
# tools/translations/<code>.json (the other 35 locales of OJS 3.5, one {key: text} per file).
# Locales not in REVIEWED are marked fuzzy: translated, waiting for a speaker to review them
# (OJS shows fuzzy entries as any other).
# Usage: python3 tools/locales.py
import json
import os
import re

P = 'plugins.generic.ojsbrSubmissionQueueReport.'
S = [
 ('displayName', 'Submission Queue Report', 'Relatório de submissões', 'Informe de envíos'),
 ('description',
  'A report of the journal\'s submissions for editors: totals per situation equal to the editorial dashboard, filters, the editors and reviewers of each submission with their deadlines, the last decision, days without activity, and a CSV or XLSX export.',
  'Relatório das submissões da revista para os editores: totais por situação iguais aos do painel editorial, filtros, editores e avaliadores de cada submissão com os prazos, última decisão, dias parado e exportação em CSV ou XLSX.',
  'Informe de los envíos de la revista para los editores/as: totales por situación iguales a los del panel editorial, filtros, editores/as y revisores/as de cada envío con sus plazos, última decisión, días sin actividad y exportación en CSV o XLSX.'),
 ('menu', 'Submission report', 'Relatório de submissões', 'Informe de envíos'),
 ('pageTitle', 'Submission report', 'Relatório de submissões', 'Informe de envíos'),
 ('filter.situation', 'Situation', 'Situação', 'Situación'),
 ('filter.situationDescription', 'The lists of the editorial dashboard. A submission in any of the chosen lists is shown.', 'As listas do painel editorial. Aparece a submissão que estiver em qualquer uma das listas escolhidas.', 'Las listas del panel editorial. Se muestra el envío que esté en cualquiera de las listas elegidas.'),
 ('filter.dateFrom', 'Submitted from', 'Submetidas a partir de', 'Enviados desde'),
 ('filter.dateTo', 'Submitted until', 'Submetidas até', 'Enviados hasta'),
 ('filter.period', 'Submitted between {$from} and {$to}', 'Submetidas entre {$from} e {$to}', 'Enviados entre {$from} y {$to}'),
 ('situation.reviewOther', 'In review, other cases', 'Em avaliação, outros casos', 'En revisión, otros casos'),
 ('situation.submission', 'Submission stage', 'Etapa de submissão', 'Etapa de envío'),
 ('reviewStatus.awaitingResponse', 'Awaiting response', 'Aguardando resposta', 'Esperando respuesta'),
 ('reviewStatus.responseOverdue', 'Response overdue', 'Resposta atrasada', 'Respuesta vencida'),
 ('reviewStatus.requestResent', 'Request resent', 'Convite reenviado', 'Invitación reenviada'),
 ('reviewStatus.declined', 'Declined', 'Recusou', 'Rechazó'),
 ('reviewStatus.accepted', 'Accepted, reviewing', 'Aceitou, avaliando', 'Aceptó, revisando'),
 ('reviewStatus.reviewOverdue', 'Review overdue', 'Parecer atrasado', 'Revisión vencida'),
 ('reviewStatus.received', 'Review submitted', 'Parecer enviado', 'Revisión enviada'),
 ('reviewStatus.confirmed', 'Review confirmed by the editor', 'Parecer confirmado pelo editor', 'Revisión confirmada por el editor/a'),
 ('reviewStatus.cancelled', 'Cancelled', 'Cancelada', 'Cancelada'),
 ('column.id', 'ID', 'ID', 'ID'),
 ('column.url', 'Address', 'Endereço', 'Dirección'),
 ('column.title', 'Title', 'Título', 'Título'),
 ('column.mainSituation', 'Main situation', 'Situação principal', 'Situación principal'),
 ('column.views', 'Dashboard lists', 'Listas do painel', 'Listas del panel'),
 ('column.stage', 'Stage', 'Etapa', 'Etapa'),
 ('column.locale', 'Language', 'Idioma', 'Idioma'),
 ('column.section', 'Section', 'Seção', 'Sección'),
 ('column.dateSubmitted', 'Submitted', 'Submissão', 'Envío'),
 ('column.daysSinceSubmission', 'Days since submission', 'Dias desde a submissão', 'Días desde el envío'),
 ('column.lastActivity', 'Last activity', 'Última atividade', 'Última actividad'),
 ('column.daysInactive', 'Days without activity', 'Dias parado', 'Días sin actividad'),
 ('column.authors', 'Authors', 'Autoria', 'Autoría'),
 ('column.emails', 'Authors\' emails', 'E-mails dos autores', 'Correos de los autores/as'),
 ('column.affiliations', 'Affiliations', 'Afiliações', 'Afiliaciones'),
 ('column.editors', 'Assigned editors', 'Editores designados', 'Editores/as asignados'),
 ('column.round', 'Review round', 'Rodada de avaliação', 'Ronda de revisión'),
 ('column.roundStatus', 'Round status', 'Situação da rodada', 'Estado de la ronda'),
 ('column.reviewsRequired', 'Reviews required', 'Avaliações exigidas', 'Revisiones requeridas'),
 ('column.reviewsAccepted', 'Reviews accepted', 'Avaliações aceitas', 'Revisiones aceptadas'),
 ('column.reviewsCompleted', 'Reviews completed', 'Avaliações concluídas', 'Revisiones completadas'),
 ('column.reviewsOverdue', 'Reviews overdue', 'Avaliações atrasadas', 'Revisiones vencidas'),
 ('column.reviewsDeclined', 'Reviews declined', 'Avaliações recusadas', 'Revisiones rechazadas'),
 ('column.nextDue', 'Next review due', 'Próximo prazo de parecer', 'Próximo plazo de revisión'),
 ('column.reviewers', 'Reviewers', 'Avaliadores', 'Revisores/as'),
 ('column.lastDecision', 'Last decision', 'Última decisão', 'Última decisión'),
 ('column.lastDecisionDate', 'Date of the last decision', 'Data da última decisão', 'Fecha de la última decisión'),
 ('column.issue', 'Issue', 'Edição', 'Número'),
 ('column.categories', 'Categories', 'Categorias', 'Categorías'),
 ('column.doi', 'DOI', 'DOI', 'DOI'),
 ('column.reviewer', 'Reviewer', 'Avaliador', 'Revisor/a'),
 ('column.reviewStatus', 'Review status', 'Situação da avaliação', 'Estado de la revisión'),
 ('column.recommendation', 'Recommendation', 'Recomendação', 'Recomendación'),
 ('column.dateAssigned', 'Assigned', 'Designação', 'Asignación'),
 ('column.dateResponseDue', 'Response due', 'Prazo de resposta', 'Plazo de respuesta'),
 ('column.dateResponded', 'Response', 'Resposta', 'Respuesta'),
 ('column.dateDue', 'Review due', 'Prazo do parecer', 'Plazo de la revisión'),
 ('column.dateCompleted', 'Review submitted', 'Envio do parecer', 'Envío de la revisión'),
 ('column.daysOverdue', 'Days overdue', 'Dias de atraso', 'Días de retraso'),
 ('export.csv', 'CSV (Excel, LibreOffice)', 'CSV (Excel, LibreOffice)', 'CSV (Excel, LibreOffice)'),
 ('export.xlsx', 'Excel workbook (XLSX)', 'Planilha do Excel (XLSX)', 'Libro de Excel (XLSX)'),
 ('export.modeSubmissions', 'One row per submission', 'Uma linha por submissão', 'Una fila por envío'),
 ('export.modeReviews', 'One row per review (for pivot tables)', 'Uma linha por avaliação (para tabela dinâmica)', 'Una fila por revisión (para tablas dinámicas)'),
 ('export.filePrefix', 'submissions', 'submissoes', 'envios'),
 ('export.firstLine', '{$journal} — generated on {$date} by {$user} — {$filters}', '{$journal} — gerado em {$date} por {$user} — {$filters}', '{$journal} — generado el {$date} por {$user} — {$filters}'),
 ('ui.generatedAt', 'Generated on {$date}', 'Gerado em {$date}', 'Generado el {$date}'),
 ('ui.export', 'Export', 'Exportar', 'Exportar'),
 ('ui.exportTitle', 'Export what is filtered', 'Exportar o que está filtrado', 'Exportar lo filtrado'),
 ('ui.exportMode', 'Rows', 'Linhas', 'Filas'),
 ('ui.exportFormat', 'Format', 'Formato', 'Formato'),
 ('ui.download', 'Download', 'Baixar', 'Descargar'),
 ('ui.cancel', 'Cancel', 'Cancelar', 'Cancelar'),
 ('ui.filtersApply', 'Apply filters', 'Aplicar filtros', 'Aplicar filtros'),
 ('ui.filtersClear', 'Clear filters', 'Limpar filtros', 'Limpiar filtros'),
 ('ui.filtersShow', 'Show filters', 'Mostrar filtros', 'Mostrar filtros'),
 ('ui.filtersHide', 'Hide filters', 'Ocultar filtros', 'Ocultar filtros'),
 ('ui.activeFilters', 'Active filters', 'Filtros em uso', 'Filtros activos'),
 ('ui.viewsHeading', 'Totals of the dashboard lists', 'Totais das listas do painel', 'Totales de las listas del panel'),
 ('ui.viewsOverlap', 'The same numbers as the side menu of the dashboard. A submission can be in more than one list, so they do not add up to the total. Choose a list to show its submissions.', 'Os mesmos números do menu lateral do painel. Uma submissão pode estar em mais de uma lista, por isso eles não somam o total. Escolha uma lista para ver as submissões dela.', 'Los mismos números del menú lateral del panel. Un envío puede estar en más de una lista, por eso no suman el total. Elija una lista para ver sus envíos.'),
 ('ui.viewsFiltered', 'With the filters in use: without them, these are the numbers of the side menu of the dashboard.', 'Com os filtros em uso: sem eles, estes são os números do menu lateral do painel.', 'Con los filtros activos: sin ellos, estos son los números del menú lateral del panel.'),
 ('ui.openInDashboard', 'Open "{$name}" in the dashboard', 'Abrir "{$name}" no painel', 'Abrir "{$name}" en el panel'),
 ('ui.mainHeading', 'Main situation', 'Situação principal', 'Situación principal'),
 ('ui.mainDescription', 'One situation per submission, the first that applies in this order, so the numbers add up to the total listed. Choose one to show only its submissions.', 'Uma situação por submissão, a primeira que servir nesta ordem, para os números fecharem com o total listado. Escolha uma para ver só as submissões dela.', 'Una situación por envío, la primera que corresponda en este orden, para que los números sumen el total listado. Elija una para ver solo sus envíos.'),
 ('ui.mainTotal', 'Total', 'Total', 'Total'),
 ('ui.mainClear', 'Show all main situations', 'Mostrar todas as situações principais', 'Mostrar todas las situaciones principales'),
 ('ui.mainFilteredBy', 'Only: {$names}', 'Só: {$names}', 'Solo: {$names}'),
 ('ui.search', 'Search title, author or ID', 'Buscar título, autor ou ID', 'Buscar título, autor/a o ID'),
 ('ui.columns', 'Columns', 'Colunas', 'Columnas'),
 ('ui.columnsReset', 'Default columns', 'Colunas padrão', 'Columnas predeterminadas'),
 ('ui.showCancelled', 'Show cancelled reviews', 'Mostrar avaliações canceladas', 'Mostrar revisiones canceladas'),
 ('ui.loading', 'Loading…', 'Carregando…', 'Cargando…'),
 ('ui.loadError', 'The report could not be loaded.', 'Não foi possível carregar o relatório.', 'No se pudo cargar el informe.'),
 ('ui.retry', 'Try again', 'Tentar de novo', 'Intentar de nuevo'),
 ('ui.empty', 'No submissions match these filters.', 'Nenhuma submissão com estes filtros.', 'Ningún envío con estos filtros.'),
 ('ui.total', '{$total} submissions', '{$total} submissões', '{$total} envíos'),
 ('ui.pageOf', 'Page {$page} of {$lastPage}', 'Página {$page} de {$lastPage}', 'Página {$page} de {$lastPage}'),
 ('ui.openWorkflow', 'Open the workflow of submission {$id} in a new tab', 'Abrir o fluxo da submissão {$id} numa nova aba', 'Abrir el flujo del envío {$id} en una pestaña nueva'),
 ('ui.contact', 'contact', 'contato', 'contacto'),
 ('ui.none', 'None', 'Nenhum', 'Ninguno'),
 ('ui.daysAgo', '{$days} days ago', 'há {$days} dias', 'hace {$days} días'),
 ('ui.days', '{$days} days', '{$days} dias', '{$days} días'),
 ('ui.daysOverdue', '{$days} days overdue', '{$days} dias de atraso', '{$days} días de retraso'),
 ('ui.due', 'review due {$date}', 'prazo do parecer {$date}', 'plazo de la revisión {$date}'),
 ('ui.responseDue', 'response due {$date}', 'prazo de resposta {$date}', 'plazo de respuesta {$date}'),
 ('ui.round', 'Round {$round}', 'Rodada {$round}', 'Ronda {$round}'),
 ('ui.reviewsSummary', 'Reviews of the round', 'Avaliações da rodada', 'Revisiones de la ronda'),
 ('ui.reviewsAccepted', 'accepted', 'aceitas', 'aceptadas'),
 ('ui.reviewsCompleted', 'completed', 'concluídas', 'completadas'),
 ('ui.reviewsOverdue', 'overdue', 'atrasadas', 'vencidas'),
 ('ui.reviewsDeclined', 'declined', 'recusadas', 'rechazadas'),
 ('ui.noRound', 'Not in review', 'Fora da avaliação', 'Fuera de revisión'),
 ('ui.noReviews', 'No reviewers in this round', 'Nenhum avaliador nesta rodada', 'Ningún revisor/a en esta ronda'),
 ('ui.recommendation', 'Recommendation', 'Recomendação', 'Recomendación'),
 ('ui.tableLabel', 'Submissions of the report', 'Submissões do relatório', 'Envíos del informe'),
 ('ui.percent', '{$percent}%', '{$percent}%', '{$percent}%'),
]

HEADER = '''msgid ""
msgstr ""
"Project-Id-Version: \\n"
"Report-Msgid-Bugs-To: \\n"
"Last-Translator: OJSBR\\n"
"Language-Team: OJSBR\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Language: {code}\\n"

'''

def q(text):
    return '"' + text.replace('\\', '\\\\').replace('"', '\\"') + '"'

here = os.path.dirname(os.path.abspath(__file__))
base = os.path.join(here, '..', 'locale')
tables = {code: {row[0]: row[i] for row in S} for i, code in ((1, 'en'), (2, 'pt_BR'), (3, 'es'))}
folder = os.path.join(here, 'translations')
for name in sorted(os.listdir(folder)):
    tables[name[:-5]] = json.load(open(os.path.join(folder, name), encoding='utf-8'))

REVIEWED = ('en', 'pt_BR', 'es', 'pt')
placeholders = lambda text: sorted(re.findall(r'\{\$\w+\}', text))
for code, table in tables.items():
    assert set(table) == set(tables['en']), code + ': keys differ from en'
    for key, text in table.items():
        assert text.strip(), code + ': empty ' + key
        assert placeholders(text) == placeholders(tables['en'][key]), code + ': placeholders of ' + key
    os.makedirs(os.path.join(base, code), exist_ok=True)
    out = HEADER.format(code=code)
    for row in S:
        out += '\n' + ('' if code in REVIEWED else '#, fuzzy\n') + 'msgid ' + q(P + row[0]) + '\nmsgstr ' + q(table[row[0]]) + '\n'
    open(os.path.join(base, code, 'locale.po'), 'w', encoding='utf-8').write(out)
print(len(S), 'keys,', len(tables), 'locales')
