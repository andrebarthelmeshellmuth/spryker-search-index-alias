# Contributing to search-index-alias

Thanks for considering a contribution — issues and PRs are welcome. This is a single-maintainer
open-source project, so response times may vary.

## Getting started

```
composer install
```

Requires PHP 8.3+ (CI also runs against 8.4). This package is a Zed-only Spryker module: it wraps
core's own `Elastica\Client`/`SourceIdentifier` (no Client-layer code, deliberately — see the
README's "How it works"). If you're working on a change that needs to be exercised end-to-end,
you'll need a Spryker demo shop with this package installed as a local path repository, wired
against a real OpenSearch/Elasticsearch cluster and RabbitMQ broker — see the README's Installation
and Testing sections.

## Before opening a PR

These are the checks CI runs; running them locally first saves a review round-trip:

```
composer validate --no-check-publish
vendor/bin/phpcs
vendor/bin/phpmd src text phpmd.xml
vendor/bin/phpmd src text phpmd-public-methods.xml
vendor/bin/phpmd src text phpmd-parameter-list.xml
composer rector-dry-run
composer check-floors
```

`check-floors` re-resolves dependencies to the lowest versions allowed by `composer.json` and
asserts every vendor symbol used in `src/` still exists at that floor — it's the check most likely
to catch an accidental "works on my shop" dependency bump.

`composer phpstan` and the Codeception suites (`tests/SprykerCommunityTest`) both need to run from
inside a host Spryker shop — they use the shop's generated Locator and
`Generated\Shared\Transfer\*` classes, neither of which this package can produce standalone. If you
can't spin one up, open the PR anyway — CI covers style/rector/dependency-floor checks, and the
static-analysis/functional passes will be run before merging.

## Making a change

- Keep PRs focused — one change per PR.
- Branch from and target `main`; branches are merged via squash, so intermediate commit messages
  don't need to be polished.
- Match the existing code style — `phpcs` and `rector-dry-run` above catch most deviations.
- Every mutation against the cluster goes through the `_aliases` actions API as a single atomic
  call (never a remove-then-add pair) — this is the whole point of the package, see the README's
  "How it works" for why. A change that can't preserve that property is worth discussing in an
  issue first.
- The mapping-diff classifier (additive vs. breaking) is safety-critical: getting it wrong is
  silent and can permanently corrupt an index's mapping (see the README). Changes to
  `MappingDiffClassifier` need real test coverage against every mapping-change shape, not just the
  common nested/object case.
- Update `README.md`/`docs/` when behavior changes.

## Reporting bugs or requesting features

Use the issue templates — they ask for the information needed to reproduce a bug or evaluate a
request. For security issues, see [SECURITY.md](SECURITY.md) instead of opening a public issue.

## License

By contributing, you agree your contribution is licensed under this project's [MIT license](LICENSE).
