# Submission Queue Report — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-5.0.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/ojsbrSubmissionQueueReport/releases/download/5.0.0.0/ojsbrSubmissionQueueReport-5.0.0.0.tar.gz) — or browse all [Releases](../../releases).

> **Built on the work of others.** The lists, their criteria and the filters are the ones of
> the **editorial dashboard of OJS 3.5** by the [Public Knowledge Project](https://pkp.sfu.ca)
> (`Repository::getDashboardViews()`, `SubmissionFilters`), reused as they are. The idea of an
> editorial report on screen, with filters, instead of a CSV download comes from PKP's
> [Sprint Report #6: Enhanced Editorial Reports](https://pkp.sfu.ca/?p=4113); PKP's **Review
> Report** and **Articles Report** (Statistics > Reports) are the reports editors used before.
> The plugin replaces OJSBR's own *artigosEmFila* report for OJS 3.3, and its "Revisions
> requested" list follows the rule of OJSBR's
> [revisionsRequestedView](https://github.com/OJSBR/revisionsRequestedView). See
> [Credits](#credits--authorship).

A generic plugin for **Open Journal Systems (OJS) 3.5** that gives editors a **report of the
journal's submissions**: the totals of every list of the editorial dashboard, a summary that adds
up, a table with what is needed to follow up each submission, and an export of exactly what is
filtered.

> **Developed and maintained by [OJSBR](https://ojsbr.com).**

## Compatibility & branches

| Application | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 5.0.0.0 |

The first number of the version names the OJS line (5 = OJS 3.5). OMP and OPS are not supported:
OMP has other review lists and OPS has no review stage.

## What the editor gets

**Statistics › Submission report** (also listed in *Statistics › Reports*, where the report of OJS
3.3 was; assistants, who have no Statistics menu, get it as a menu item of its own):

- **Totals of the dashboard lists** — one tile per list of the side menu of the editorial dashboard
  (*Assigned to me*, *Active*, *Needs editor*, *Needs reviews*, *Reviews overdue*, *Revisions
  requested*, *Copyediting*, *Published*...), in the same order, with the same names and **the same
  numbers**. A click shows the list in the table (Ctrl/Shift-click adds or removes lists); a small
  arrow opens the same list in the dashboard, to compare.
- **Main situation** — the lists overlap (a submission is *Active*, *In review* and *Reviews overdue*
  at once), so each submission also gets one main situation, the first that applies in this order:
  declined, published, scheduled, needs editor, production, copyediting, revisions submitted,
  revisions requested, reviews overdue, reviews submitted, awaiting reviews, needs reviews, other
  review cases, submission stage. A coloured bar and a legend show how many are in each one, and
  they **add up to the total listed**. A click on a situation shows only its submissions.
- **Filters** — the filters form of the dashboard itself (section, assigned editor, issue,
  category, days without activity), plus the stage and the period of submission. Filters, list,
  search, order and page live in the address, so a filtered report can be shared as a link.
- **Table** (30 per page, sorted by ID, submission date or last activity; columns can be chosen
  and the choice is remembered):
  ID and title (opening the workflow in a new tab) · main situation · dashboard lists · stage ·
  section · language · submitted (and days since) · last activity (and days without activity,
  highlighted after 30) · authors (contact marked) · e-mails · affiliations (the 3.5 affiliations,
  with ROR) · assigned editors (*None* highlighted) · current round, its status and the counts of
  accepted, completed, overdue and declined reviews · the reviewers of the current round, each with
  status, recommendation, the deadline that matters (to answer, then to review) and days overdue ·
  next review due · last decision and its date · issue · categories · DOI.
- **Export** — CSV for Excel (UTF-8 with BOM, `;`) or an Excel workbook (XLSX, header frozen and
  with filter buttons), with **one row per submission** or **one row per review** (for pivot
  tables). It exports exactly what is filtered, every page; the first line says which journal,
  when, by whom and with which filters.

### The numbers are the dashboard's numbers

Each list is built with the collector core builds for the dashboard
(`Repo::submission()->getDashboardViews()`), and all totals are counted in one query, the way core
counts the side menu. *Revisions requested*, which core only builds for authors, is added with the
rule core uses for *Revisions submitted* (the same list the revisionsRequestedView plugin adds to
the dashboard); lists other plugins add through the `Dashboard::views` hook are kept too. Without
filters, every tile shows the number of the side menu; with filters, the tiles count the filtered
submissions and the page says so.

### Who sees what

The same rules as the editorial dashboard: **managers and admins** see every submission of the
journal; **section editors and assistants** only the submissions they are assigned to, and only
the lists core gives them (no *Needs editor*, no *Declined*). Authors and reviewers get OJS's
"access denied" page on the report, its data and its export. Every request checks the role and
builds its queries with the user's own access; ids from the request are checked against the
journal, and lists or filters the user may not use are ignored.

## Installation

Upload the package in **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
into `plugins/generic/` (giving `plugins/generic/ojsbrSubmissionQueueReport/`). Then enable
**Submission Queue Report** in the *Generic* plugins list of each journal. There is nothing to
configure.

## How it works (technical)

- A page of its own (`submissionQueueReport`, through `LoadHandler`) with the operations `index`,
  `data` (JSON) and `export`; `TemplateManager::setupBackendPage` adds the menu item;
  `PluginRegistry::categoryLoaded::reports` lists it in Statistics › Reports.
- The page is a Vue component registered with `pkp.registry`, made of the core's own components
  (table, form, search, pagination, buttons) and the dashboard's `useFiltersForm`.
- Queries go through the core collectors and Laravel's query builder only (MySQL/MariaDB and
  PostgreSQL). The review criteria of the main situation are the dashboard's own collectors, run
  once per request; the lists built from them are reused for the main situation, for the lists of
  each submission and for the totals of the matching views (nested in one query, some of core's
  criteria are evaluated again for every row and take seconds on a large journal).
- Rows are loaded for the whole page at once: core objects (publications, authors, affiliations,
  review assignments, users) are made from their rows the way core's `EntityDAO::fromRow()` makes
  them, with one query of settings per page instead of one per object, and without what the report
  does not show (keywords, citations...).
- The export streams: CSV straight to the output, XLSX to a temporary sheet that is zipped at the
  end (OJS ships no spreadsheet library). Cells that would start a formula are kept as text.
- Labels come from core where core has a short one (lists, stages, recommendations, round status,
  decisions), so they read as in the rest of OJS and follow the journal's own renamings.
- No core file is patched, no template is replaced and nothing is stored.

## Tests

- **PHPUnit** (`tests/`), in PKP's continuous integration (OJS × PHP 8.2/8.3 × MySQL/PostgreSQL):
  for every journal, as a manager and as a section editor, each total equals the number core gives
  the dashboard; *Revisions requested* equals an independent SQL count; the main situations add up
  to every list; nothing is listed twice; a section editor never gets a submission they are not
  assigned to; rows show the current round only; filters, CSV and XLSX; translations.
- **Cypress** (`cypress/tests/functional`): the menu and the Reports link; tiles against the
  `_submissions/viewsCount` endpoint the dashboard calls, for a manager and a section editor; a list
  in the table with its summary; the CSV export; a reviewer gets nothing.
- **On a large journal** (8,638 submissions, read only): the 16 totals equal the dashboard's; the
  rows of 150 submissions are identical to the ones built with full core objects; the report page
  takes about 4–5 s, less than the dashboard takes for the same counts on that server; exporting
  the 360 active submissions takes about 1 s and the whole journal, as XLSX, about 30 s with
  31 MB of memory.
- Checked by hand on a journal with real data: the 16 tiles against the side menu of the dashboard,
  for an admin and for a section editor; English, Spanish and Portuguese; 1366 px; the report
  inside a 375 px content area (the OJS 3.5 backend itself keeps its 336 px side menu open on
  phones). Counter-tests: breaking the *Revisions requested* rule or the section editor's access
  makes the tests fail.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com).
- **[Public Knowledge Project](https://pkp.sfu.ca)** — the editorial dashboard of OJS 3.5, whose
  lists, criteria, filters form and Vue components the report reuses; the
  [Enhanced Editorial Reports](https://pkp.sfu.ca/?p=4113) sprint, for the idea of an editorial
  report on screen with filters; the Review Report and Articles Report.
- **OJSBR's *artigosEmFila*** (OJS 3.3), the report this plugin replaces: its columns are kept,
  corrected (current version only, titles in any language, authors and reviewers without a family
  name, review status from core, current round only).
- **OJSBR's [revisionsRequestedView](https://github.com/OJSBR/revisionsRequestedView)**, for the
  *Revisions requested* list for editors.
- Distributed under the **GNU GPL v3**.

## AI use

Generative AI (Claude, by Anthropic) was used to write and run tests, improve the code and bring it
in line with PKP standards. Every change is reviewed and tested by OJSBR, which is responsible for
the published releases.

## Contributing

Issues and pull requests are welcome. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

> **Feito sobre o trabalho de outros.** As listas, os critérios delas e os filtros são os do
> **painel editorial do OJS 3.5**, do [Public Knowledge Project](https://pkp.sfu.ca)
> (`Repository::getDashboardViews()`, `SubmissionFilters`), reaproveitados como são. A ideia de um
> relatório editorial na tela, com filtros, em vez de um CSV para baixar vem do
> [Sprint Report #6: Enhanced Editorial Reports](https://pkp.sfu.ca/?p=4113) da PKP; o **Relatório
> de avaliações** e o **Relatório de artigos** da PKP (Estatísticas › Relatórios) eram o que os
> editores usavam antes. O plugin substitui o relatório *artigosEmFila* da OJSBR para o OJS 3.3, e
> a lista "Revisões solicitadas" segue a regra do
> [revisionsRequestedView](https://github.com/OJSBR/revisionsRequestedView) da OJSBR. Veja
> [Créditos](#créditos-e-autoria).

Plugin genérico para o **Open Journal Systems (OJS) 3.5** que dá aos editores um **relatório das
submissões da revista**: os totais de todas as listas do painel editorial, um resumo que fecha, uma
tabela com o que é preciso para acompanhar cada submissão e a exportação exatamente do que está
filtrado.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).**

### Compatibilidade e branches

| Aplicação | Branch | Release do plugin |
|-----------|--------|-------------------|
| OJS 3.5.x | `stable-3_5_0` *(padrão)* | 5.0.0.0 |

O primeiro número da versão indica a linha do OJS (5 = OJS 3.5). OMP e OPS não são atendidos: o OMP
tem outras listas de avaliação e o OPS não tem etapa de avaliação.

### O que o editor ganha

**Estatísticas › Relatório de submissões** (também listado em *Estatísticas › Relatórios*, onde
ficava o relatório do OJS 3.3; o assistente, que não tem o menu Estatísticas, recebe um item de
menu próprio):

- **Totais das listas do painel** — um quadro por lista do menu lateral do painel editorial
  (*Designado para mim*, *Submissões ativas*, *Precisa de editor*, *Avaliações pendentes*,
  *Avaliações atrasadas*, *Revisões solicitadas*, *Edição de texto*, *Publicado*...), na mesma
  ordem, com os mesmos nomes e **os mesmos números**. O clique mostra a lista na tabela
  (Ctrl/Shift + clique soma ou tira listas); uma setinha abre a mesma lista no painel, para
  comparar.
- **Situação principal** — as listas se sobrepõem (uma submissão é *Ativa*, *Em avaliação* e
  *Avaliação atrasada* ao mesmo tempo), por isso cada submissão também recebe uma situação
  principal, a primeira que servir nesta ordem: rejeitada, publicada, agendada, precisa de editor,
  editoração, edição de texto, revisões enviadas, revisões solicitadas, avaliações atrasadas,
  avaliações enviadas, aguardando avaliações, avaliações pendentes, outros casos em avaliação,
  etapa de submissão. Uma barra colorida e a legenda mostram quantas há em cada uma, e **a soma
  fecha com o total listado**. O clique numa situação mostra só as submissões dela.
- **Filtros** — o próprio formulário de filtros do painel (seção, editor designado, edição,
  categoria, dias sem atividade), mais a etapa e o período da submissão. Filtros, lista, busca,
  ordem e página ficam no endereço: o relatório filtrado pode ser compartilhado como link.
- **Tabela** (30 por página, ordenada por ID, data de submissão ou última atividade; as colunas
  podem ser escolhidas e a escolha fica guardada): ID e título (abrem o fluxo numa nova aba) ·
  situação principal · listas do painel · etapa · seção · idioma · submissão (e dias desde então) ·
  última atividade (e dias parado, em destaque depois de 30) · autoria (com o contato marcado) ·
  e-mails · afiliações (as do 3.5, com ROR) · editores designados (*Nenhum* em destaque) · rodada
  atual, situação dela e contagem de avaliações aceitas, concluídas, atrasadas e recusadas · os
  avaliadores da rodada atual, cada um com situação, recomendação, o prazo que vale (de resposta,
  depois do parecer) e dias de atraso · próximo prazo de parecer · última decisão e data · edição ·
  categorias · DOI.
- **Exportação** — CSV para o Excel (UTF-8 com BOM, `;`) ou planilha do Excel (XLSX, cabeçalho
  congelado e com botões de filtro), com **uma linha por submissão** ou **uma linha por avaliação**
  (para tabela dinâmica). Exporta exatamente o que está filtrado, todas as páginas; a primeira linha
  diz a revista, quando, quem gerou e com quais filtros.

### Os números são os do painel

Cada lista é montada com o mesmo coletor que o núcleo monta para o painel
(`Repo::submission()->getDashboardViews()`), e todos os totais saem numa consulta só, como o núcleo
conta o menu lateral. *Revisões solicitadas*, que o núcleo só monta para o autor, entra com a regra
que o núcleo usa em *Revisões submetidas* (a mesma lista que o plugin revisionsRequestedView põe no
painel); listas que outros plugins acrescentam pelo gancho `Dashboard::views` também entram. Sem
filtros, cada quadro mostra o número do menu lateral; com filtros, os quadros contam as submissões
filtradas e a página avisa.

### Quem vê o quê

As mesmas regras do painel editorial: **gestores e administradores** veem todas as submissões da
revista; **editores de seção e assistentes**, só as submissões designadas a eles, e só as listas
que o núcleo dá a eles (sem *Precisa de editor* e sem *Rejeitadas*). Autores e avaliadores recebem a
página "acesso negado" do OJS no relatório, nos dados e na exportação. Toda requisição confere o
papel e monta as consultas com o acesso do próprio usuário; ids vindos da requisição são conferidos
contra a revista, e listas ou filtros que o usuário não pode usar são ignorados.

### Instalação

Envie o pacote em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta
em `plugins/generic/` (ficando `plugins/generic/ojsbrSubmissionQueueReport/`). Depois ative o
**Relatório de submissões** na lista de plugins *Genéricos* de cada revista. Não há nada para
configurar.

### Testes

PHPUnit e Cypress no CI da PKP (OJS × PHP 8.2/8.3 × MySQL/PostgreSQL): em todas as revistas, como
gestor e como editor de seção, cada total igual ao número que o núcleo dá ao painel; *Revisões
solicitadas* igual a uma contagem SQL independente; situações principais fechando com cada lista;
nada repetido; editor de seção nunca recebe submissão que não é dele; só a rodada atual; filtros,
CSV, XLSX e traduções; no navegador, quadros contra o endpoint `_submissions/viewsCount` que o painel
chama, exportação e avaliador barrado. Numa revista grande (8.638 submissões, só leitura): os 16 totais iguais aos do painel; as
linhas de 150 submissões idênticas às montadas com os objetos completos do núcleo; a página do
relatório em cerca de 4–5 s, menos do que o próprio painel leva para as mesmas contagens naquele
servidor; exportação das 360 ativas em cerca de 1 s e da revista inteira, em XLSX, em cerca de
30 s com 31 MB de memória. Conferido à mão numa revista com dados reais: os 16 quadros
contra o menu lateral do painel, como administrador e como editor de seção; inglês, espanhol e
português; 1366 px; o relatório numa área de conteúdo de 375 px (o próprio backend do OJS 3.5
mantém o menu lateral de 336 px aberto no celular). Contraprovas: quebrar a regra de *Revisões
solicitadas* ou o acesso do editor de seção faz os testes falharem.

### Idiomas

Interface em português (Brasil), inglês e espanhol, revisados.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com).
- **[Public Knowledge Project](https://pkp.sfu.ca)** — o painel editorial do OJS 3.5, cujas listas,
  critérios, formulário de filtros e componentes Vue o relatório reaproveita; o sprint
  [Enhanced Editorial Reports](https://pkp.sfu.ca/?p=4113), pela ideia do relatório editorial na
  tela com filtros; o Relatório de avaliações e o Relatório de artigos.
- **O *artigosEmFila* da OJSBR** (OJS 3.3), o relatório que este plugin substitui: as colunas dele
  continuam, corrigidas (só a versão atual, título em qualquer idioma, autor e avaliador sem
  sobrenome, situação do avaliador vinda do núcleo, só a rodada atual).
- **O [revisionsRequestedView](https://github.com/OJSBR/revisionsRequestedView) da OJSBR**, pela
  lista *Revisões solicitadas* para editores.
- Distribuído sob a **GNU GPL v3**.

### Uso de IA

Foi usada IA generativa (Claude, da Anthropic) para escrever e rodar testes, melhorar o código e
alinhá-lo aos padrões da PKP. Toda mudança é revisada e testada pela OJSBR, que responde pelas
releases publicadas.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
