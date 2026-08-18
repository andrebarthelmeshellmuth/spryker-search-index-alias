## What & why



## Checklist

- [ ] `composer validate --no-check-publish` passes
- [ ] `vendor/bin/phpcs` passes
- [ ] `vendor/bin/phpmd src text phpmd.xml` / `phpmd-public-methods.xml` / `phpmd-parameter-list.xml` pass
- [ ] `composer rector-dry-run` passes
- [ ] `composer check-floors` passes
- [ ] `composer phpstan` passes (run from a host shop)
- [ ] Tests added/updated, or explained why not needed
- [ ] `README.md`/`docs/` updated if behavior changed
