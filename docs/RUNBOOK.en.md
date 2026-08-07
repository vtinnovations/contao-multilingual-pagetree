# Operational runbook

*Deutsche Fassung (Standardsprache): [RUNBOOK.de.md](RUNBOOK.de.md)*

Concise operating instructions for Contao Multilingual Pagetree in production.
Commands are shown for a Contao Managed Edition; adjust the console path if your
installation differs.

## Before installation

- Confirm the target stack is in the supported matrix ([README.en.md](../README.en.md) → Requirements).
- Back up the database **and** the `files/` directory.
- Review the existing multilingual implementation and record which languages each
  root site should serve.
- Confirm, per root site, which language is the default (fallback) language.
- Confirm the licence status of every root site whose languages are to be edited.
- Install and verify on staging with a copy of production data first.

## After installation

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

1. Configure languages per root site (Site structure → globe action on the root).
   Exactly one language per root must be the fallback.
2. Choose the page-availability mode (strict or fallback) per non-default language.
3. Choose the content translation mode (connected or free) per non-default language.
4. Configure the language URL per language: protocol, domain and entry point.
   Point every additional hostname at the installation in DNS and in the webserver
   configuration, and issue a certificate for it.
5. Add the language-switcher frontend module to the layout if visitors need it.
6. Verify the canonical routes of every language against its configured mapping —
   a language on its own domain with an empty entry point is served from that
   domain's root and must not carry its language code.
7. Verify the switcher, canonical tag, `hreflang` and `x-default` on a page and on
   a news/event/FAQ detail page.
8. Warm the cache and confirm that a second request serves the same language.
9. Run a read-only integrity scan and inspect the logs.

```bash
vendor/bin/contao-console contao-multilingual-pagetree:integrity:scan --root=<rootPageId>
```

## Before an upgrade

- Back up the database and files.
- Read `UPGRADE.md` and `CHANGELOG.md`.
- Run the integrity scan and resolve `critical` and `error` issues.
- Reproduce the upgrade on a CI-supported stack in staging.

## After an upgrade

```bash
vendor/bin/contao-console contao:migrate
vendor/bin/contao-console contao:migrate     # second pass must report no changes
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao-multilingual-pagetree:integrity:scan --root=<rootPageId>
```

Then verify routes, frontend metadata, connected/free content and the backend
translation forms. Clear only the caches you need; a full production cache flush
is only required when route configuration or root-site language configuration
changed.

## Routine operations

| Task | Command |
| --- | --- |
| Read-only integrity scan of one site | `integrity:scan --root=<id>` |
| Scan one language | `integrity:scan --root=<id> --language=de` |
| Machine-readable report | `integrity:scan --root=<id> --format=json` |
| Repair dry run | `integrity:repair --root=<id>` |
| Apply non-destructive repairs | `integrity:repair --root=<id> --execute` |
| Apply destructive repairs | `integrity:repair --root=<id> --execute --force` |
| Report retained bundle data | `data-report` |
| Show licence status | `registration` |

Exit codes: `0` clean, `1` warnings or repairable issues, `2` errors or critical
issues, `3` scan or execution failure. Use them in monitoring.

## Incident response

### A translated page returns 404

Expected when the language uses **strict** mode and no published page translation
exists. Check the language's availability mode, then the translation's published
state and its `start`/`stop` window. Switch the language to fallback mode if the
source page should be served instead. The integrity scan reports orphaned or
alias-less translations.

### A language's own domain returns 404 at its root

Check that the hostname stored on the language record is exactly the hostname
being requested. The comparison is exact: a `www` variant, a parent domain and a
sibling subdomain are all different hostnames and are deliberately not matched.
Then confirm that the language is published, that the hostname resolves to this
installation, and that the cache has been rebuilt since the mapping changed.

### A duplicate or unexpected route appears

Run the integrity scan and look for `duplicate_alias` and
`duplicate_translation`. A former fallback URL redirects permanently once a
translated alias exists; that redirect is intentional. Clear the route/page cache
after resolving alias conflicts.

### A previously working language URL stops working

Moving a language to its own domain, or changing its entry point, retires the
previous address. Existing links are not rewritten. Configure a Contao redirect
page or a webserver rule for the retired address.

### Content appears in the wrong language

Check the content mode of the language. In free mode only that language's free
records render and the source structure is never used as a fallback. In connected
mode only source records render. Look for `cross_language_relation` and
`cross_site_relation` in the scan output — these are quarantined, not deleted.

### A detail record is unavailable in one language

Detail records always require their own published translation, even when the
reader page is available through page fallback. This is by design. Verify the
news/event/FAQ translation's published state and alias.

### `hreflang` looks stale

Metadata is emitted per request from the resolved availability, so stale output
almost always means a cached page. Invalidate the affected page or root cache.
Confirm the translation is published and within its publication window.

### A migration failed

Migrations are idempotent: fix the reported cause and run `contao:migrate` again.
No migration deletes ambiguous data; duplicates are reported by the integrity
scanner instead. If the failure persists, restore the backup and open an issue
with the console output — it contains no credentials or content.

### An integrity repair failed

The executor rolls back inside a transaction and reports `rolled_back`; partial
completion is reported precisely and never as success. Re-run the scan: a plan
built before the data changed is rejected as stale by design. Re-preview and
re-confirm.

### A cross-site relation warning appears

A record points at a record of another root site. The subsystem quarantines it
(it stops rendering and keeps its data) and never re-attaches it by guessing. An
editor must decide the correct relation.

### Content-mode misconfiguration

Switching modes never deletes data. The other mode's records remain stored and
inactive; switching back restores rendering. The confirmation dialog states how
many records become inactive.

### Route cache problems

Clear the Contao/Symfony cache. Route generation reads the language configuration
per root site and never performs an integrity scan, so a persistent problem
indicates a data issue — run the scan.

### Licensed functions are unavailable

See [licence management](PRODUCT-REGISTRATION.en.md). Check the licence status of
the affected root site and its configured domain. When a check fails, restricted
functionality stays disabled and existing translations continue to be delivered.

## Monitoring

Log channels: `contao_multilingual_pagetree` and
`contao_multilingual_pagetree_integrity`. Alert on `error` and `critical`. Normal
fallback behaviour and missing optional translations are not logged as errors.
Logs contain codes, table names, record ids, root ids and language codes — never
translated content, licence keys, tokens or credentials.
