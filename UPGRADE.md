# Upgrade guide

This guide covers upgrading to **Contao Multilingual Pagetree** from an earlier
state of the same bundle. The package has not been released yet, so the guide is
written against the `Unreleased` state on `main`.

**Always back up the database and the files directory before upgrading.** Several
migrations write to production tables, and none of them can be rolled back
automatically.

## 1. From `vtinnovations/contao-language-flow`

The product was renamed. The Composer package, the PHP namespace and the bundle
class changed; **no database identifier changed**.

```bash
composer remove vtinnovations/contao-language-flow
composer require vtinnovations/contao-multilingual-pagetree
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

The new package declares a Composer conflict with the old one, so the two can
never be installed at the same time. Because table names, field names, the
`language_switcher` module type and the stored ownership columns are retained,
existing content, field states, review metadata and free-content records keep
working without any data migration.

What you must update yourself:

- Custom code that referenced `Vtinnovations\ContaoLanguageFlow\…` classes.
- Custom services that injected bundle services by their old class name.
- Custom templates that extended or copied `mod_language_switcher`.
- Third-party field-policy contributors: implement
  `Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldPolicyContributorInterface`.

## 2. From the original inline-language bundle

The retained table `tl_inline_language` is the original language configuration
table. Upgrading adds columns rather than replacing tables:

| Migration | Adds | Behaviour |
| --- | --- | --- |
| Field states | `fieldStates` JSON per translation | Classifies legacy snapshot values conservatively: equal to source → `inherit`, different and non-empty → `custom`, ambiguous empty → `inherit`. Existing non-empty maps are never overwritten. |
| Page availability | `pageAvailabilityMode` | Every existing non-default language becomes `fallback`, matching previous behaviour. |
| Switcher display | `unavailableLanguageDisplay` | Existing modules become `hide`, matching previous behaviour. |
| Review status | `reviewStatus`, `reviewedSourceRevision`, `reviewedSourceSnapshot`, `reviewedAt`, `reviewedBy` | Every existing translation becomes `unreviewed`. No reviewed baseline is invented. |
| Content mode | `contentTranslationMode` | Every existing non-default language becomes `connected`, matching previous behaviour. |
| Integrity indexes | Lookup indexes only | Adds no uniqueness constraint, so existing duplicates cannot make the migration fail. |
| Language URL | `urlProtocol`, `urlDomain`, `urlEntryPoint` | All three stay empty for existing records, which preserves their previous URL behaviour exactly: the default language unprefixed, every other language below its language code, all on the website root domain and protocol. The migration only normalises values that already exist; an unusable value is cleared rather than guessed, and an empty entry point is never turned into `/`. |

Every migration is idempotent: running `contao:migrate` again performs no further
changes. A partially completed migration resumes safely because each migration
re-derives what is still missing rather than assuming a prior state.

### After migrating from a legacy install

1. Because historical records did not store editorial intent, a legacy empty
   value cannot be distinguished from an untranslated snapshot. Fields that were
   deliberately emptied must be set to **Leave deliberately empty** once.
2. Every translation starts as **Not yet reviewed**. One explicit review per
   record is required before the system can distinguish `up_to_date` from
   `needs_review`.
3. Run the integrity scan and resolve reported duplicates. Ambiguous duplicates
   are reported, never deleted automatically.

```bash
vendor/bin/contao-console contao-multilingual-pagetree:integrity:scan --root=<rootPageId>
```

## 3. Recommended upgrade procedure

1. Back up the database and files.
2. Read this guide and `CHANGELOG.md`.
3. Run an integrity scan on the current installation and resolve `critical` and
   `error` issues first.
4. Upgrade on a staging copy of production data.
5. Run `contao:migrate` and then run it a second time to confirm idempotency.
6. Clear the cache.
7. Run the integrity scan again.
8. Verify routes, the switcher, canonical/`hreflang` output and the backend
   translation forms using the checklist in `docs/MANUAL-VERIFICATION.md`.
9. Deploy to production and repeat steps 5–8 there.

## 4. Downgrade

There is no automated downgrade. Restore the database backup taken before the
upgrade. Removing the Composer package alone does **not** remove multilingual
data — see "Disabling and uninstalling" in `README.md`.
