# Changelog

All notable changes to this package are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Each version below also has a [GitHub release](../../releases) with the fuller write-up.

## [Unreleased]

### Documented
- OpenSearch 3.5 compatibility: verified end-to-end on a demoshop upgraded from 1.3.4; new
  `docs/opensearch-3.x-migration.md` covering the `index.knn` static-setting and `nmslib`-removal
  gotchas adopters hit (neither a change to this package).

## [2.0.1] - 2026-08-27

### Fixed
- Declared `propel/propel`, `spryker/config`, `spryker/search-extension` as direct requires (full
  dependency audit).
- Bumped `spryker/code-sniffer` 0.17.35 → 0.17.36.
- Applied Rector `IfToNullCoalescingAssignRector` (unpinned dev-tooling drift).

## [2.0.0] - 2026-08-23

### Changed
- **Breaking:** rebuild now defaults to `fromSchema=true` — the index mapping is derived from the
  package's own schema definition rather than the currently-live index. Use `--from-live` to opt back
  into the old behaviour.
- CI: bumped `actions/checkout` v4 → v7.

### Added
- Fresh-from-schema rebuild mode (`--from-schema`), now the default.
- Audit trail for index deletions; deleting an active rollout's target index is refused.

## [1.0.0] - 2026-08-21

### Added
- Initial release: Zed-driven blue/green search indices behind aliases — rebuild an index in the
  background (bulk-loaded from the publish tables, caught up via a RabbitMQ mirror queue), verify it,
  then flip it onto live atomically. Zero downtime, including on first adoption of an installation
  where core ships no alias mechanism. Verified against OpenSearch 1.3.4: 4000 concurrent reads
  across 40 atomic flips, zero non-200 responses (vs 3.3% for the remove-then-add sequence).
- Full Zed GUI (Overview/History, Rebuild/Flip/Abort/Rollback), deploy-time flip-pending /
  rollback-pending flags for a `deploy-flip` console command, and a `check-installation` diagnostic
  that round-trips real probe messages through every queue/broker path.

[Unreleased]: ../../compare/v2.0.1...HEAD
[2.0.1]: ../../releases/tag/v2.0.1
[2.0.0]: ../../releases/tag/v2.0.0
[1.0.0]: ../../releases/tag/v1.0.0
