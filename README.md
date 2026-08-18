# Spryker Search Index Alias

Zed-driven blue/green search indices behind aliases: rebuild a search index in the background, verify
it, then flip to it atomically — zero downtime, including on first adoption of an existing installation.

## What does this do?

Spryker's stock `console search:setup` updates a search index's mapping **in place**. That's safe for
additive changes (new fields) but has no answer for a *breaking* change (retyping or removing a field) —
core ships no alias or blue/green reindex mechanism at all, so a breaking mapping change on stock Spryker
means either living with the old shape forever or a real-downtime manual reindex.

This package gives every configured (store, sourceIdentifier) index set a stable **alias** backed by
timestamped physical indices. A rebuild builds a **new** physical index in the background — bulk-loaded
directly from the publish tables, then caught up via a RabbitMQ mirror queue for anything published while
the rebuild ran — and only once it's verified does a single atomic `_aliases` call flip the alias across.
The live index is never written to during a rebuild, so the whole operation can be aborted at any point
with zero cleanup on the live side.

## How it works

1. **Adoption** (`search-index-alias:adopt`, once per scope). A stock Spryker install has a *concrete*
   index literally named e.g. `myshop_de_page` — Elasticsearch refuses to alias over a name that's
   already a concrete index. Adoption clones that index's mapping/settings onto a fresh, timestamped
   physical index, reindexes its documents server-side, verifies the document counts converge, then uses
   the `_aliases` API's `remove_index` action to atomically delete the old concrete index and add the
   alias in the SAME transaction — zero window where the name resolves to nothing.

2. **Rebuild** (`search-index-alias:rebuild`). Clones the live index's current mapping/settings onto a
   new target (optionally layering a mapping change on top — always safe, the target is empty), binds a
   RabbitMQ queue to the same exchange the live publish/sync pipeline writes to (a *mirror* of the real
   sync queue, not instead of it), then bulk-loads the target directly from the `spy_*_search` tables'
   `data` column — bypassing the publish/sync queue entirely, which is what makes this fast even for a
   large catalog. Anything published to the live index *during* the bulk load is captured by the mirror
   queue and replayed onto the target afterward, deduplicated last-write-wins. The live index is never
   touched by any of this.

3. **Flip** (`search-index-alias:flip`, or automatic — see `isAutoFlipEnabled()`). One final mirror-queue
   drain pass to catch anything published since the rebuild reached `ready`, then a single atomic
   `_aliases` call switches the alias from the old physical index to the new one. Verified live against
   OpenSearch 1.3.4: 4000 concurrent reads across 40 atomic flips produced zero non-200 responses, where
   the equivalent remove-then-add two-call sequence produced a 3.3% error rate under identical load.

4. **Abort**, at any point before flip. Unbinds the mirror queue; the live index was never written to, so
   this is a clean no-op as far as live traffic is concerned. The target index itself is left in place
   (marked `skipped` on the Overview table) rather than deleted automatically — deletion is a deliberate,
   separate action via that table's own Delete button, not an automatic side effect of aborting.

A rollout's full lifecycle (`building` → `ready` → `flipping` → `flipped`/`aborted`/`failed`) is persisted
in `spy_search_index_rollout`, with a DB-enforced (not just application-checked) guard against two
concurrent rollouts for the same scope.

## Installation

```
composer require spryker-community/search-index-alias
```

1. Register the core namespace (if not already, most Spryker projects already have this):
   ```php
   // config/Shared/config_default.php
   $config[KernelConstants::CORE_NAMESPACES][] = 'SprykerCommunity';
   ```

2. Run the migration:
   ```
   vendor/bin/console propel:install
   ```

3. Wire the console commands into your project's `Pyz\Zed\Console\ConsoleDependencyProvider`:
   ```php
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasStatusConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasAdoptConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRebuildConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRebuildWorkerConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasFlipConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasMarkFlipPendingConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasMarkRollbackPendingConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasDeployFlipConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasAbortConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasRollbackConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasPruneConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasHealthConsole;
   use SprykerCommunity\Zed\SearchIndexAlias\Communication\Console\SearchIndexAliasCheckInstallationConsole;

   // inside getConsoleCommands():
   $commands[] = new SearchIndexAliasStatusConsole();
   $commands[] = new SearchIndexAliasAdoptConsole();
   $commands[] = new SearchIndexAliasRebuildConsole();
   $commands[] = new SearchIndexAliasRebuildWorkerConsole();
   $commands[] = new SearchIndexAliasFlipConsole();
   $commands[] = new SearchIndexAliasMarkFlipPendingConsole();
   $commands[] = new SearchIndexAliasMarkRollbackPendingConsole();
   $commands[] = new SearchIndexAliasDeployFlipConsole();
   $commands[] = new SearchIndexAliasAbortConsole();
   $commands[] = new SearchIndexAliasRollbackConsole();
   $commands[] = new SearchIndexAliasPruneConsole();
   $commands[] = new SearchIndexAliasHealthConsole();
   $commands[] = new SearchIndexAliasCheckInstallationConsole();
   ```

4. Copy the `<search-index-alias-gui>` block from this package's own
   `src/SprykerCommunity/Zed/SearchIndexAlias/Communication/navigation.xml` into your project's
   `config/Zed/navigation.xml`, then rebuild the navigation cache:
   ```
   vendor/bin/console navigation:build-cache
   vendor/bin/console router:cache:warm-up:backoffice
   vendor/bin/console router:cache:warm-up:backend-gateway
   ```

   **Nesting under a shared parent (e.g. alongside `spryker-community/search-feedback`):** if your
   project uses `ZedNavigationConfig::BREADCRUMB_MERGE_STRATEGY` (an explicit per-project override, not
   Spryker's default), you can nest this block one level deeper under a project-owned category instead of
   letting it stand as its own top-level nav item — see this project's own `config/Zed/navigation.xml` for
   a working example (a `search-toolbox` category containing both this package and search-feedback).
   **Keep the nested `<search-index-alias-gui>` entry childless** (`label`/`title`/`bundle`/`controller`/
   `action` only, no nested `<pages>` of its own) and let its Overview/History/Adopt/Rebuild/Flip/Abort
   children merge in automatically from this package's own file — `BreadcrumbNavigationMergeStrategy`
   adopts a matching node's pages wholesale when your own copy has none. **Don't** also copy this
   package's own `<pages>` children into your nested copy: with both sides populated,
   `array_merge_recursive` collides on every duplicate leaf value (same key, same string) and turns it
   into an array, crashing `@Gui/Partials/navigation.twig` with `Twig\Error\RuntimeError: ... ("Array to
   string conversion")`. `search-index-alias:check-installation` (step 8 below) recognizes both the full
   and the childless copy as valid, so it won't false-flag a correctly-nested childless entry as missing.

5. **Configure which sourceIdentifiers this package can rebuild** (see below) — without this step,
   `adopt` works for every scope, but `rebuild` silently bulk-loads zero documents for any scope beyond
   `page`.

6. **Translations.** This package ships its Zed GUI strings as `spryker/translator` CSV catalogs under
   [`data/translation/Zed/`](data/translation/Zed/) (Zed's `trans` filter does **not** use the
   Yves-facing Glossary module — same convention as its siblings). If your project already extended
   `Pyz\Zed\Translator\TranslatorConfig::getCoreTranslationFilePathPatterns()` with the
   `spryker-community/*` glob for another package in this family, this one is auto-discovered by the same
   glob — no extra step. Otherwise add it once:
   ```php
   $coreTranslationFilePathPatterns[] = APPLICATION_VENDOR_DIR . '/spryker-community/*/data/translation/Zed/[a-z][a-z]_[A-Z][A-Z].csv';
   ```
   Adding the glob is not enough on its own — Zed's translation catalog is cached. Rebuild it once after
   wiring the glob (or after this package is installed at all, if the glob was already present):
   ```
   vendor/bin/console translator:clean-cache
   vendor/bin/console translator:generate-cache
   ```
   Every string in the Overview and History pages falls back to its own raw English text if untranslated,
   so skipping this step is never a hard failure — only a missing `de_DE` (or other locale) translation.

7. **Run the rebuild worker.** Rebuilds triggered from the Zed GUI are dispatched onto a dedicated RabbitMQ
   queue and only actually run once something is consuming it — without a worker running, a GUI "Rebuild"
   click will report a rollout was requested and then sit in `building` forever. Run it the same way you'd
   run any other long-lived queue consumer in this project (e.g. alongside `queue:worker:start`):
   ```
   vendor/bin/console search-index-alias:rebuild-worker
   ```
   The console-triggered `search-index-alias:rebuild` command is unaffected — it always runs synchronously
   and does not need the worker.

8. Verify everything:
   ```
   vendor/bin/console search-index-alias:check-installation
   ```

## Configuration

Override `Pyz\Zed\SearchIndexAlias\SearchIndexAliasConfig` (extending this package's own):

- **`getManagedSourceIdentifiers(): array`** — which (store, sourceIdentifier) pairs this package manages.
  Empty (the default) means every sourceIdentifier your `SearchElasticsearchConfig` supports, for every
  configured store. Narrow this to skip small/rarely-changed indices you're happy leaving on stock core's
  in-place `search:setup`.

- **`getSpySearchSourceTables(): array`** — maps a sourceIdentifier to the `spy_*_search` table(s) whose
  `data` column holds the fully rendered document. **Ships configured for `page` only** (this package's
  own reference values) — Spryker exposes no generic way to derive this, since it depends entirely on
  which publisher plugins your project has registered. Every other managed sourceIdentifier needs an
  entry here before `rebuild` will do anything useful for it; `search-index-alias:check-installation`
  flags any managed scope missing one.

- **`getSyncExchangeNames(): array`** — maps a sourceIdentifier to the RabbitMQ exchange its publish/sync
  pipeline writes to (the mirror queue binds here). Defaults to guessing `sync.search.<sourceIdentifier>`
  when not listed, which is right for some resources and wrong for others (confirmed live: category, cms,
  and merchant all have distinctly-named exchanges) — verify each one against your own broker's real
  exchange list rather than trusting the guess.

- **`getKeepIndicesCount(): int`** (default `3`) — how many old, unaliased physical indices
  `search-index-alias:prune` keeps per scope as a rollback buffer before deleting the rest.

- **`isAutoFlipEnabled(): bool`** (default `false`) — whether a rebuild that reaches `ready` flips
  automatically, or waits for an explicit `flip`/GUI confirmation. Manual is the safer default; enable
  auto-flip for an unattended deploy-pipeline rebuild once you trust your own verification gate.

## Extension points

- **`TargetIndexSettingsExpanderPluginInterface`** (`Dependency\Plugin`) — lets another package change a
  rebuild's *target* index `settings.index` block (analyzers, char/token filters, refresh interval, any
  other index-level setting) before the target is created. This is the only moment such settings can
  differ from what's already live, since they're immutable on an existing index. Register plugins by
  overriding `getTargetIndexSettingsExpanderPlugins()` in your project's own
  `Pyz\Zed\SearchIndexAlias\SearchIndexAliasDependencyProvider` (extending this package's own):

  ```php
  protected function getTargetIndexSettingsExpanderPlugins(): array
  {
      return [
          new AnalyzerConfigTargetIndexSettingsExpanderPlugin(),
      ];
  }
  ```

  The stack is consulted once per rebuild, inside `RebuildOrchestrator::buildTarget()`, for every trigger
  this package has (`start()`, the async request/consume pair, and `deploy-flip`) — no other wiring is
  needed. Each plugin receives the scope (`SearchIndexScopeTransfer`) and the settings cloned so far, and
  must return a settings array back (unchanged, if it has nothing to contribute for that scope). An empty
  stack (the default) reproduces this package's pre-existing behavior exactly: an unmodified clone of
  live's settings. Deliberately **not** consulted during first-time adoption (`IndexAdopter`) — adoption's
  job is to make the current live index the first blue/green generation unchanged, not to also apply
  pending config.

## Console commands

| Command | What it does |
|---|---|
| `search-index-alias:status [alias]` | Table of every managed scope: adopted?, last rollout status, target index, doc count. |
| `search-index-alias:adopt <alias>` | First-time migration of a concrete index into an alias. Run once per scope. |
| `search-index-alias:rebuild <alias> [--mapping-file=] [--user=] [--optimize]` | Starts a rebuild and blocks until it finishes; optionally layers a mapping change from a `{"properties": {...}}` JSON file. `--optimize` disables the target index's refresh interval/replicas for the duration of the bulk load (restored afterward) — worthwhile for a large catalog, at the cost of near-real-time search on the target until it converges. |
| `search-index-alias:rebuild-worker [--stop-when-empty\|-s]` | Consumes rebuild requests dispatched from the Zed GUI (see below); long-running by default like `queue:worker:start`, use `--stop-when-empty` to drain and exit once. |
| `search-index-alias:flip <alias>` | Atomically switches live traffic to a `ready` rollout's target. |
| `search-index-alias:mark-flip-pending <alias> [--off]` | Flags (or, with `--off`, unflags) a scope's `ready` rollout as "flip this the next time `deploy-flip` runs" — the console counterpart to the Overview page's "Flag for next deploy"/"Unflag" toggle. Does not flip anything itself. |
| `search-index-alias:mark-rollback-pending <alias> <target-index> [--off]` | Flags (or, with `--off`, unflags) an already-existing physical index as "flip to this the next time `deploy-flip` runs" — the rollback counterpart to `mark-flip-pending`. Mutually exclusive with it per scope: flagging one clears the other. Does not flip anything itself. |
| `search-index-alias:deploy-flip [--dry-run]` | Applies every managed scope's deploy-time intent in one call — a `ready`, flip-pending rollout, or a flagged rollback target — the deploy pipeline's entrypoint, see "Deploying" below. `--dry-run` lists what would happen without touching anything. Exits non-zero if any action failed, so CI/deploy tooling can gate on it. |
| `search-index-alias:abort <alias> [--reason=]` | Cancels an in-progress rollout and unbinds its mirror queue. Live traffic is never affected; the target index is left in place (status `skipped`) for manual cleanup. |
| `search-index-alias:rollback <alias> <target-index>` | Atomically flips the alias straight to an already-existing physical index, immediately — an emergency undo that doesn't require a fresh rebuild. For a rollback that should land at deploy time instead, use `mark-rollback-pending`. |
| `search-index-alias:prune <alias>` | Deletes old unaliased indices beyond `getKeepIndicesCount()`. |
| `search-index-alias:health [alias]` | Detects alias drift (an alias resolving to zero or more than one physical index). |
| `search-index-alias:check-installation` | Diagnoses the installation itself — see below. |

## Zed GUI

Search Index Alias → Overview (`/search-index-alias`) lists every physical index for a selected (Source,
Store) scope — one row per physical index, not per rollout event. Both dropdowns must resolve to a real
managed scope before the table renders — a partial filter just shows a prompt to finish selecting, rather
than guessing which scope you meant. Whenever there's an active rollout, a line above the action bar
states its status and target index name explicitly (e.g. "target `myshop_de_page_20260101_120000`"), so
it's clear what Flip/Abort would act on before you click. Each row shows the index's `current`/`replaced`/
`skipped`/`unknown` status (`replaced` served live traffic at some point and was superseded; `skipped`
means its rollout was aborted or failed and it never went live; `unknown` means it exists in Elasticsearch
but no rollout on record built it — typically a leftover from before this package's rollout history
existed), document count, and, for any non-current row, "Roll back to this index" (immediate) / "Flag for
next deploy" (records intent instead — see "Deploying" below; the row shows "flagged for next deploy" plus
an "Unflag" button once flagged) and "Delete" buttons — including `skipped` rows, since aborting no longer
deletes the target index automatically; cleaning one up is a deliberate action via that button, not a side
effect of aborting. An action bar above the table has
Adopt/Rebuild/Flip/Abort, scoped to whatever the current scope's state allows — Rebuild has an "Optimize
for large bulk load" checkbox (see `--optimize` above). **Clicking Rebuild in the GUI dispatches
asynchronously** (it returns immediately with a `building` rollout) — see "Run the rebuild worker" above;
without a worker running, the rollout never progresses past `building`. A "View rollout history" link leads
to a pure, read-only audit log of every past rollout event for that scope (no buttons — every action lives
on the Overview page, which can show
what it would actually apply to). A cross-scope "Pending deploy flips" panel sits above the source/store
pickers on every load, regardless of the current filter — it's exactly what
`search-index-alias:deploy-flip --dry-run` would report, so you can always see what the next deploy would
flip before it happens.

## Deploying

A blue-green rebuild is deliberately decoupled from the flip: you can rebuild and verify a target index
days ahead of a deploy, but the flip itself should usually happen at the *same instant* as the application
code that expects the new index (a new query field, a new mapping) goes live — not before, and not by
someone remembering to click a button in Zed after the fact.

The intended flow:

1. **Rebuild ahead of time** (GUI or `search-index-alias:rebuild`), verify the target reaches `ready`.
2. **Flag it** — "Flag for next deploy" in the GUI, or `search-index-alias:mark-flip-pending <alias>` —
   instead of flipping immediately. The live index is untouched; this only records intent.
3. **Wire `search-index-alias:deploy-flip` into your deploy pipeline's post-deploy hook.** This project's
   own Spryker Deploy config does this via `SPRYKER_HOOK_AFTER_DEPLOY` (see `deploy.aws-env-template.yml`
   and `config/install/post-deploy.yml`) — that hook fires only once the new application code is already
   live, which is exactly the timing this needs. `deploy-flip` applies every flagged scope in one call and
   exits non-zero if anything failed, so a CI/deploy pipeline can gate on it.

Nothing here requires flagging at all — `search-index-alias:flip <alias>` (or the plain "Flip" button)
still works for an immediate, manual switch when code/index timing isn't a concern. Flagging exists
specifically for the "flip must land atomically with a deploy" case.

**Rollbacks can be deploy-time too.** The same problem applies to an emergency undo: rolling back
immediately (the plain "Roll back to this index" button, or `search-index-alias:rollback`) switches live
traffic on the spot, which is exactly wrong if the rollback needs to land together with a code revert.
"Flag for next deploy" on any old, non-current physical index row (or
`search-index-alias:mark-rollback-pending <alias> <target-index>`) records the same kind of intent instead
— `deploy-flip` performs the atomic switch at deploy time either way, whether the flagged target came from
a fresh rebuild or an older index. The two flag kinds are mutually exclusive per scope: flagging one clears
the other, since only one target can be "the next deploy's outcome" for a given scope.

## Checking your installation

`vendor/bin/console search-index-alias:check-installation` verifies: the core namespace, the console
command classes, the `spy_search_index_rollout`/`spy_search_index_deploy_rollback_target` tables, navigation registration, Elasticsearch/OpenSearch
reachability, the RabbitMQ Management HTTP API reachability (a separate concern from the plain AMQP
connection — confirmed live these two can disagree in the same environment), whether every managed scope
has rebuild config, back-office access (see "Restricting access" below), and the Zed translation catalog —
both that a project actually wired it (README step 6) and that it is complete (every `trans`-filtered
string this package's own Twig/PHP ships has a matching CSV entry; a gap here is a defect in the package
itself, not something a project can fix). It also warns if any scope has been flagged flip-pending for
more than 24h without flipping — almost always either a deploy pipeline whose `SPRYKER_HOOK_AFTER_DEPLOY`
never actually calls `deploy-flip` (see "Deploying" above), or a forgotten flag worth reviewing on the
Overview page.

Known gap, not yet covered: the checker verifies the RabbitMQ **Management HTTP API** but not the plain
**AMQP connection** itself (used by the mirror-queue drain/publish and the rebuild-worker) — these two are
confirmed able to disagree in the same environment, so passing this check does not guarantee the
rebuild-worker can actually connect.

## Restricting access

**A default Spryker install does not restrict this package's pages to anyone.** `root_role` carries a
total wildcard (`*/*/*` allow) and every installer admin sits in `root_group`, so Rebuild, Flip, Abort,
Roll back, and Delete are all reachable by any back-office user the moment this package is installed — no
ACL work required. That is the same default every other Zed module ships with, but it is worth calling
out explicitly here: unlike a read-only reporting page, these actions build real indices, flip live search
traffic, and permanently delete old ones. `check-installation` reports how many unrestricted roles can
reach this package's modules (`search-index-alias`, `search-index-alias-gui`) and warns if that number is
above zero, but it deliberately never fails the check or does anything about it — restricting access is a
per-project decision this package cannot make on your behalf.

To restrict it: in the Zed GUI, go to **Maintenance > Users & Rights > Roles**, create (or reuse) a role
scoped to the `search-index-alias`/`search-index-alias-gui` bundles, assign it only to the group(s) that
should manage rebuilds, and either remove the wildcard role from everyone else or add an explicit deny
rule for these bundles to their role. Re-run `check-installation` afterward to confirm the warning clears.

## Known limitations

- `getSpySearchSourceTables()`/`getSyncExchangeNames()` ship configured for `page` only — every other
  managed scope needs project-level configuration before `rebuild` does anything but write an empty
  index. `check-installation` flags this.
- Alias drift (an alias pointing at more than one physical index) is detected (`search-index-alias:health`)
  but never auto-repaired — deciding which of several aliased indices is correct needs a human, not a
  script.

## Testing and CI

### Automated checks

`.github/workflows/ci.yml` runs on every push and pull request, the same set of checks as its siblings:

| check | what it protects |
|---|---|
| `composer validate` | the manifest stays well-formed |
| `phpcs` (PHP 8.3, 8.4) | coding standard, via this package's own `phpcs.xml` |
| `rector` dry-run (PHP 8.3, 8.4) | no unapplied Rector rule set drifts in |
| `composer check-floors` (PHP 8.3, 8.4) | the declared dependency floors are real |
| `phpmd` (`phpmd.xml` + `phpmd-public-methods.xml` + `phpmd-parameter-list.xml`) | complexity / method-, class-, and parameter-list limits, run as three separate invocations (PHPMD merges every ruleset's `exclude-pattern` into one global list per run, and only specific rules should skip Facades/Factories/`RebuildOrchestrator`) |
| `phpstan` (PHP 8.3, 8.4) | static analysis, standalone CI variant — see "Static analysis" below |
| `portable tests` (PHP 8.3, 8.4) | this package's own `@group Portable` test subset actually passes — see "Test suite" below |
| `phpstan` (host shop) | static analysis against a real Spryker Locator/`Generated\Shared\Transfer\*`, wired via a composer path-repository against `spryker-shop/b2b-demo-marketplace` |

### Test suite

Every test class carries a portability `@group`, so `codecept run -g <tag>` tells you what a given test
actually needs:

| tag | needs | where it runs |
|---|---|---|
| `Portable` | nothing beyond `Generated\Shared\Transfer\*` | standalone — CI runs exactly this, see below |
| `NeedsDatabase` | a real Propel connection | host shop only |
| `NeedsSearch` | a real Elasticsearch/OpenSearch | host shop only |
| `NeedsBroker` | a real RabbitMQ (both the plain AMQP connection and the Management HTTP API) | host shop only |
| `NeedsProject` | this package's own installation diagnostics, deliberately coupled to this demoshop's real wiring — see their own docblocks | host shop only |

Unlike a package whose logic is mostly pure computation, almost everything this package does — aliasing,
bulk-loading, mirror-queue draining, the atomic flip — is cluster/broker mechanics that cannot be
meaningfully faked, so most of the suite carries `NeedsDatabase`, `NeedsSearch`, and/or `NeedsBroker`
together. `Portable` therefore covers the genuinely pure-logic slice only: index-name building, mapping-
diff classification, canonical index-name resolution, the two Communication forms, one controller's
open-redirect guard, and the `check-installation` translation-catalog diff logic (extracting `trans` keys
from source and diffing against the real CSV — including a test that fabricates a genuinely missing key
to prove the diff would actually catch a real gap).

`Portable` tests run standalone in CI on every push, via `tests/codeception.portable.yml` +
`tests/_ci-standalone/` — no host shop, no live database, no search engine, no broker. The recipe: a
direct `TransferBusinessFactory` call generates `Generated\Shared\Transfer\*` into `src/Generated/`
(gitignored, exactly like a real project already gitignores its own — regenerated every run). Run it
yourself the same way CI does:

```bash
composer install
php tests/_ci-standalone/generate-transfers.php
vendor/bin/codecept run -c tests/codeception.portable.yml -g Portable
```

**167 tests, 322 assertions** in the full Zed suite (44 of those are `Portable` and also run standalone,
see above; the other 108 need `NeedsDatabase`/`NeedsSearch`/`NeedsBroker`/`NeedsProject`) covering nearly
every Business-layer class, two representative Communication forms, the
`search-index-alias:check-installation` console command (including the back-office access diagnostic, see
"Restricting access" above), and one controller's pure redirect-guard logic — all against real
infrastructure, not mocks, following this project's own testing conventions (see
`IndexClonerTest`'s `testCloneMappingAndSettingsThrowsWhenTheSourceIndexDoesNotExist` for an example of a
real production bug this discipline found: `cloneMappingAndSettings()`'s mapping/settings *fetch* wasn't
wrapped into `IndexCloneFailedException`, only the later index-creation call was). From a shop that has
the package installed and a running Elasticsearch/OpenSearch + RabbitMQ + MySQL stack:

```bash
vendor/bin/codecept build -c packages/spryker-community/search-index-alias/tests/SprykerCommunityTest/Zed/SearchIndexAlias
vendor/bin/codecept run   -c packages/spryker-community/search-index-alias/tests/SprykerCommunityTest/Zed/SearchIndexAlias
```

Deliberately NOT tested at this integration layer: the full form-post/redirect Zed backend controllers
(`RolloutController`'s and `DeployFlagController`'s action methods, `IndexController::indexAction()`) —
each needs a real Silex `Application`/session for `addSuccessMessage()`'s flash bag, which no sibling package's own
comparable controller attempts to unit-test either. A real browser click through the GUI is the only
honest way to verify that full round trip — see the WebDriver Presentation suite below, which covers
exactly this.

### WebDriver Presentation suite

`tests/SprykerCommunityTest/Zed/SearchIndexAliasGuiPresentation/` drives the real Zed GUI end to end in a
real headless Chrome session, same pattern as the sibling packages' own Presentation suites: the Overview
and History pages both load with real data, a partial source/store filter shows the picker prompt instead
of guessing, and — the real payoff — a full **Rebuild → real rebuild-worker console run → Flip** round
trip through the actual buttons and confirm() dialogs, verified by a growing physical-index row count and
the live alias actually moving. A separate test confirms **Abort** leaves the current live index
untouched, and two more confirm the **deploy-time flow** for both flag kinds: clicking "Flag for next
deploy" (on the active rollout, or on an old physical index row) leaves the live index untouched either
way — only `search-index-alias:deploy-flip` (the same command `SPRYKER_HOOK_AFTER_DEPLOY` runs in
production) actually moves it, whether that means a flip or a rollback. 8 tests, all green against a real
MySQL/Elasticsearch/RabbitMQ stack:

```bash
vendor/bin/codecept build -c packages/spryker-community/search-index-alias/tests/SprykerCommunityTest/Zed/SearchIndexAliasGuiPresentation
vendor/bin/codecept run   -c packages/spryker-community/search-index-alias/tests/SprykerCommunityTest/Zed/SearchIndexAliasGuiPresentation
```

Needs a real WebDriver browser session — run via `docker/sdk testing` (not plain `docker/sdk cli`, which
doesn't inject `SPRYKER_TEST_WEB_DRIVER_HOST`). Not gated in CI — needs the full docker-compose stack, no
self-hosted runner available; stays a local/manual gate like the rest of the Zed suite above.

### Static analysis

Two `phpstan` configs exist for the same reason the test suite splits into `Portable` vs. everything
else: `phpstan.neon` (the real one, needs a host shop's Locator/`Generated\Shared\Transfer\*`) and
`phpstan.ci.neon` (the standalone CI variant, `composer phpstan-ci` — generates just the Transfer classes
this package needs and ignores the two categories of class that only exist inside a real host shop:
Propel `Orm\Zed\*` models, and the aggregated `Generated\*\Ide\AutoCompletion` stub).

```bash
composer phpstan-ci
```

## License

MIT — see [LICENSE](LICENSE).
