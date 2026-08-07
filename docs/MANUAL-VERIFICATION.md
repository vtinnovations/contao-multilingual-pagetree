# Manual production verification checklist

This checklist covers behaviour that can only be confirmed on a running Contao
installation with a real database. Automated tests cover the service layer; the
items below exercise Contao's own routing, rendering, DCA, permission and cache
machinery.

**Nothing in this list may be marked verified unless it was actually executed on
a live installation.** As of the current state of `main`, no item has been
verified: the development environment used for the implementation has no PHP,
Composer, database or Contao runtime available.

Record the result as `pass`, `fail` or `n/a`, with the stack (Contao and PHP
version) and the date.

| # | Item | What to check | Status |
| --- | --- | --- | --- |
| 1 | Clean installation | Package installs, bundle registers, migrations run, no fatal error without any language configuration | not verified |
| 2 | Upgrade from legacy schema | Migrate an inline-language/Language Flow database; run `contao:migrate` twice; second pass reports no changes | not verified |
| 3 | Multiple roots | Two root sites with separate language sets do not influence each other | not verified |
| 4 | Different default languages | Site A default `en`, site B default `de`; both unprefixed | not verified |
| 5 | Strict mode | Page without a published translation is 404, not the source page | not verified |
| 6 | Fallback mode | Same page renders source content under the prefixed URL | not verified |
| 7 | Connected content | Source structure renders with translated fields; order and type follow the source | not verified |
| 8 | Free content | Only that language's free articles/elements render; source articles do not | not verified |
| 9 | News detail switching | Translated news alias and reader page resolve; missing translation is unavailable | not verified |
| 10 | Event detail switching | As above, including recurring-event parameters | not verified |
| 11 | FAQ detail switching | As above | not verified |
| 12 | Canonical tags | Exactly one canonical per page; correct prefixing; detail pages use the translated detail alias | not verified |
| 13 | `hreflang` | Only available languages; no list-page substitutes for detail pages | not verified |
| 14 | `x-default` | Points at the default-language equivalent; emitted at most once | not verified |
| 15 | Disabled switcher languages | Hide and disabled modes render as configured; disabled entries have no `href` | not verified |
| 16 | Review status | Badge shows correct state; source change flips `up_to_date` to `needs_review` | not verified |
| 17 | Integrity scan | Backend/CLI scan completes read-only and reports expected issues | not verified |
| 18 | Integrity repair dry run | Preview lists actions and changes nothing | not verified |
| 19 | Source deletion cascade | Deleting a source removes only its own connected translations | not verified |
| 20 | Language disable and restoration | Disabling retains data and removes routes; restoring brings content back | not verified |
| 21 | Backend permissions | A non-admin without table access cannot review, switch mode or repair | not verified |
| 22 | Preview mode | Unpublished translations are visible only in an authorised preview | not verified |
| 23 | Cache warm-up | `cache:clear` and a warm request produce the correct language | not verified |
| 24 | Cache invalidation | Alias, publication and configuration changes take effect | not verified |
| 25 | CLI commands | `integrity:scan`, `integrity:repair --execute --force`, `data-report`, and their exit codes | not verified |
| 26 | Logs | Events land in the bundle channels with no content or tokens | not verified |
| 27 | Accessibility | Keyboard operation, `aria-current`, `aria-disabled`, screen-reader labels in all switcher styles | not verified |
| 28 | Production error pages | A 404 shows the Contao error page with no internal detail | not verified |

## Product registration

These items require a real installation and an issued test licence. See
[`PRODUCT-REGISTRATION.en.md`](PRODUCT-REGISTRATION.en.md). The checklist covers
observable administrator behaviour only; internal verification details are not
part of public acceptance documentation.

| # | Item | What to check | Status |
| --- | --- | --- | --- |
| R1 | Activation | A valid key issued for the displayed root domain activates and the panel immediately reports active/lifetime | not verified |
| R2 | Refresh | Refresh completes and retains the active status when no licence data changed | not verified |
| R3 | Temporary outage | A temporary connectivity problem is reported safely and does not remove a previously active local status | not verified |
| R4 | Wrong domain | A key issued for another exact domain is refused with a domain-specific administrator message | not verified |
| R5 | Invalid key | A missing or invalid key is refused without changing stored status | not verified |
| R6 | Permissions | A user without normal access to the root page cannot activate, refresh or remove | not verified |
| R7 | Request protection | Invalid request tokens and non-POST actions cannot change licence state | not verified |
| R8 | Root isolation | Activating root A does not alter the panel or capabilities of root B | not verified |
| R9 | Capability behaviour | Without an active licence, protected editorial changes are refused while existing frontend content remains available | not verified |
| R10 | Diagnostics | Failures show a safe translated message and optional reference without exposing the key or internal data | not verified |
| R11 | Distributed package | The upload-ready package installs and exposes the same user-visible activation and refresh behaviour | not verified |

## Security spot checks

| # | Item | What to check | Status |
| --- | --- | --- | --- |
| S1 | GET cannot change state | Opening the review or mode-change URL directly is refused | not verified |
| S2 | CSRF | Submitting without or with a stale token is refused | not verified |
| S3 | Redirects | A crafted redirect parameter cannot leave the installation | not verified |
| S4 | Cross-site manipulation | A record id from another root cannot be reviewed or repaired | not verified |
| S5 | Escaping | Aliases and previews containing markup render escaped in the backend | not verified |

## Performance spot checks

| # | Item | What to check | Status |
| --- | --- | --- | --- |
| P1 | Route generation | Query count stays bounded on a large page tree | not verified |
| P2 | Switcher and metadata | One resolved availability result is reused, not recomputed | not verified |
| P3 | Review lists | No per-row source query | not verified |
| P4 | Integrity scan | Bounded per-root scan completes on a large dataset | not verified |
