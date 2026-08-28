# Migrating to OpenSearch 3.x

Verified live end-to-end: a Spryker demoshop upgraded from **OpenSearch 1.3.4 to 3.5.0** (Lucene 10.3.2),
a full `search:setup` + re-export/reindex converged normally on 3.5, `search-index-alias:check-installation`
re-run.

**This package needs no code change for OpenSearch 3.x.** The blue-green rebuild uses only long-stable
index APIs — create-with-mapping, `_alias` actions, `_settings`, `_count`, `_reindex`, `_bulk`, and index
open/close — all of which behave identically across 1.3.x → 3.5 and Elasticsearch 8.x. The mirror-queue
convergence and the atomic alias flip are unaffected by the engine version.

## Two adopter notes for OpenSearch 3.x k-NN scopes

These apply to a project whose `schema.json` enables k-NN — not a change to this package, but a rebuild
touches both:

- **`index.knn` is a static setting.** It can only be set at index-creation time. A plain `search:setup`
  re-run against an already-open concrete index fails with
  `Can't update non dynamic settings [[index.knn]] for open indices [[..._page/...]]`. Add `index.knn` to
  your project's `SearchElasticsearchConfig::getStaticIndexSettings()` (the open-index skip list). A
  `search-index-alias` **rebuild is unaffected** — it always creates a fresh index, where static settings
  apply cleanly at creation; this only bites the plain `search:setup` path.
- **`nmslib` was removed in OpenSearch 3.0.** A `knn_vector` field must name `engine: lucene` (or
  `faiss`); a `--from-live` rebuild that clones a live index still carrying an `nmslib` `knn_vector`
  mapping will fail to recreate it on 3.x. Fix the schema first, then rebuild from schema.

## The upgrade-time trap this package will surface on every rebuild

OpenSearch 3.x's bundled neural-search `SemanticMappingTransformer` runs on **every index create** — which
means every `search-index-alias` rebuild. It rejects any target mapping that declares
`"some-field": { "type": "object", "properties": {} }` with
`class java.util.ArrayList cannot be cast to class java.util.Map`: PHP's `json_decode` turns the empty
`{}` into `[]`, and Spryker PUTs `"properties": []`. This is a property of the merged schema, not of this
package. Spryker Cloud Commerce removed the empty blocks from five core packages (ticket SC-25160); other
packages (e.g. `spryker-feature/self-service-portal`'s `ssp_asset.json`) may still carry one. Because
Spryker merges schema fragments with `array_replace_recursive`, which cannot delete a key, a project-level
override has to make `properties` **non-empty** rather than remove it — one inert, never-populated field:

```json
{
    "mappings": {
        "<index_source>": {
            "properties": {
                "<the-offending-field>": {
                    "type": "object",
                    "properties": { "_os3_object_guard": { "type": "boolean", "index": false } }
                }
            }
        }
    }
}
```

## Capability delta 1.3.x → 3.5

Probed directly against a live OpenSearch 1.3.14 and a live 3.5.0. The genuine additions are the `hybrid`
query (neural-search plugin, OpenSearch ≥ 2.10) and `_search/pipeline` (OpenSearch ≥ 2.8). `_plugins/_ml`
(ML Commons) was already present on 1.3.x — 3.x adds in-cluster model serving on top. `pinned` and
`_plugins/_ltr` are in neither stock image. None of these are used by this package. See
`spryker-community/search-ranking`'s migration guide for the full table.
