# Changelog

All notable changes to this package are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project intends to follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
once a first version is tagged.

## [Unreleased]

The package has not been released yet. Everything below describes the state of
the `main` branch and will form the first release notes.

### Renamed

- The product is now **Contao Multilingual Pagetree**. The Composer package is
  `vtinnovations/contao-multilingual-pagetree`, the PHP namespace is
  `Vtinnovations\ContaoMultilingualPagetree`, the bundle class is
  `VtinnovationsContaoMultilingualPagetreeBundle` and the internal identifier is
  `contao_multilingual_pagetree`.
- The former package name `vtinnovations/contao-language-flow` is declared as a
  Composer conflict so both cannot be installed side by side.
- **No persisted identifier changed.** The database tables `tl_inline_language`
  and `tl_*_translation`, the `tl_module` fields `inlineLangStyle` and
  `inlineLangHideActive`, the `language_switcher` module type and the free-content
  ownership columns are all retained, so no rename migration is required and no
  production data is touched.

### Fixed

- **A partially replaced package could pass the new language URL resolver into
  the previous page-registry constructor's entry-point-normalizer slot.** The
  decorator now keeps `EntryPointNormalizer` in its established third argument
  and receives `LanguageUrlResolver` as a separate fourth dependency. Both are
  named explicitly in the service definition and both are used, preventing
  argument-order drift while remaining safe during a complete package upgrade.

- **A language served from its own domain answered 404 at that domain's root.**
  Contao resolves a website root from `tl_page.dns`, and for the path `/` it
  asks nothing else - so a hostname that only exists on a language record found
  no root, produced no routes at all, and 404'd before any language logic ran.
  The first repair targeted `getRootPageFromUrl`, but in Contao 5.3 that name is
  a static frontend helper, not a hook, so the listener was never called. `/xx`
  appeared to work only because a non-empty path takes Contao's alias branch.
  The active route-provider decorator now detects an empty root collection,
  resolves the exact published language hostname through the canonical URL
  resolver, bootstraps the owning root's native route and then builds the normal
  language route from it. No wildcard, parent or sibling match is accepted, a
  root's own domain remains Contao's responsibility, and ambiguous ownership is
  refused.

- **A language with a domain of its own but no entry point had its language code
  appended anyway.** The derivation looked only at whether the language was the
  default one, so a Russian language configured with the domain
  `bauland-ru.taheri.cool` and an empty entry point resolved to
  `bauland-ru.taheri.cool/ru`: the domain root returned 404, the language
  navigation linked to `/ru`, and canonical and `hreflang` carried it too. The
  rule is now context sensitive - an empty entry point means the domain root
  when the language has its own domain, and keeps the previous language-code
  strategy only when it does not. The URL-prefix registry now consumes that
  canonical mapping instead of duplicating the decision, and
  the mapping records which rule produced its entry point. Inherited-domain
  records, including an explicit `/en`, are unaffected.

- **Translated content values were never persisted.** The form rendered and
  prefilled correctly - it used `onload_callback` and a field `load_callback` -
  but persistence hung on `onbeforesubmit_callback`, so nothing reached the
  translation store through Save, Save and close, Save and new or Save and go
  back. Each approved field now carries a `save_callback`, which Contao runs
  after the widget has validated and normalised the value and before the column
  is written: the translated value is captured there and the element's own value
  is handed back, so the source language is rewritten unchanged. A single
  `onsubmit_callback` then stores the whole submission - it runs after every
  field and before the submit button is evaluated, so every native save action
  reaches it without any button-specific or JavaScript handling. Saving a
  translation no longer creates a version of the source element, a field that
  still equals the source is never materialised into the store, a failed save is
  reported instead of silently succeeding, and a successful save invalidates the
  affected root only.

- **Editing an additional-language content element failed with "Not implemented
  for tl_content_translation".** That table hangs below `tl_content` through
  `ptable`, which makes it a third level under the article module - a level
  Contao has no edit operation for. It is now what it always should have been:
  storage. Additional-language content is edited through the *native*
  `tl_content` form, and a translation adapter swaps the values for the selected
  language, so the element type, the palette, every subpalette, the RTE, the
  pickers and every third-party field are natively correct with nothing rebuilt.
  A translated save writes only approved values to the store and hands Contao
  the element's own values back, so the source language is never overwritten and
  two languages can never share a row. Opening a tab creates nothing.

- **An additional-language content element opened with an empty element type**
  and the generic default palette instead of the native form of its source
  element. Contao selects a palette by reading the selector columns of the
  edited table directly, so a translation row without a stored element type can
  only ever resolve the default palette - no callback can correct it afterwards.
  The palette selector is now mirrored from the source element into the
  translation row before the palette is resolved, which restores the complete
  native form for every element type. The mirrored value stays read-only and is
  never accepted from a submission, so a connected translation cannot change its
  own structure. A non-destructive, idempotent migration fills the selector of
  translations written while the column was missing.

### Added

- **Native content-element translation form.** The additional-language form of a
  content element is now the *native* `tl_content` form: the same legends, the
  same field order, the same widgets, the same RTE configuration, the same
  selectors and subpalettes, and the fields third-party bundles contribute.
  - The per-field "translation state" selectors, the "current source value"
    previews and the "translatable content" legend are gone from content
    elements. The active language tab already states the editing language.
  - Whether a translated value is a real translation, an untouched fallback or a
    deliberate blank is now derived from the submission itself and stored in the
    existing `fieldStates` column. No new storage format was introduced and no
    provenance control is shown to editors.
  - What an untranslated field renders is decided by the language's own
    **page availability** setting: the fallback rule renders the source text, the
    strict rule renders nothing. No separate content-level fallback mode exists.
  - Only columns the canonical field policy approves are ever persisted, so a
    crafted submission cannot turn a structural or technical field into a
    translated value.
  - Page, article, news, event and FAQ translation forms and their per-field
    states are deliberately unchanged.
- **Per-language URL mapping.** Every language record of a website root can now
  define its own protocol (inherit, HTTPS or HTTP), its own optional hostname
  and its own optional entry point, persisted as `urlProtocol`, `urlDomain` and
  `urlEntryPoint` on `tl_inline_language`. This supports separate-domain sites
  (`www.xyz.com`, `www.xyz.de`, `www.xyz.ru`), same-domain path-based sites
  (`/`, `/de`, `/ru`) and any mixture of the two.
  - One central immutable value object and one central resolver produce every
    effective protocol, hostname and entry point. Incoming request resolution,
    routing, page URLs, canonical URLs, `hreflang`, `x-default`, the language
    switcher, detail switching, collision validation, cache keys and redirects
    all consume that single service.
  - An empty entry point preserves the previous URL strategy of that record; an
    explicit `/` forces the domain root. The two states stay distinct and the
    migration never converts one into the other.
  - A language hostname associates an incoming request with the *owning*
    website root only, through exact hostname matching against persisted
    mappings. No arbitrary host is ever rewritten to a root, and no browser or
    posted hostname becomes authoritative.
  - Saving is rejected for duplicate hostname/entry-point pairs, mappings that
    collide after normalisation, protocol-only ambiguity, several languages
    claiming `/` on one hostname and hostnames owned by another website root.
  - Licensing is unchanged: the authoritative licence context remains the
    owning website-root context, and a language-specific frontend domain never
    becomes a licence domain.
- **Root-scoped licence management.** Administrators manage the licence directly
  on the relevant website root, through four dedicated operations - **activate**
  for a root that has no licence yet, **replace** to exchange an existing key,
  **refresh** to fetch the status again and **verify** to recheck the stored
  status locally - plus **remove**. Every operation is its own POST action,
  authorised server side against that exact root and its exact configured
  domain. Status and errors are translated, root isolation is preserved and
  temporary connectivity problems do not remove a previously active local
  status. The console command `contao-multilingual-pagetree:registration`
  remains available for administration, and `contao:migrate` installs the
  required bundle schema.
- **Licences issued for several domains.** A licence can authorise more than one
  host, and the issued host set is part of what is signed. Every website root is
  still activated individually and keeps its own stored status; a root is
  licensed only when its exact configured domain is one of the issued hosts.
  Authorisation stays exact - apex and `www`, parents, children and siblings
  remain different hosts, and the reported domain allowance is never read as a
  wildcard. A status stored by an earlier release is preserved untouched and
  reports **Refresh required** until one refresh has fetched the issued set.
- **Release validation tooling** verifies that distributable artefacts contain
  the required runtime files and production material before publication. The
  release build now refuses to run before that material is proven, and both the
  build and the artefact check re-prove it afterwards, so an artefact that could
  not validate a real response cannot be published.
- **Canonical, URL-driven language handling.** The rendered language is
  determined by the matched URL and the language configuration of that URL's root
  page. The default language stays unprefixed, secondary languages stay prefixed,
  and browser or session preferences never change what a URL renders. Legacy
  `?language=` links and prefixed default-language URLs redirect to their
  canonical form.
- **Field-state-aware translations.** Every supported field carries one explicit
  state: inherit from source, use a custom translation, or leave deliberately
  empty. Values such as `0`, `false` and empty structured data survive intact.
- **Detail-record language switching** for news, calendar events and FAQs,
  including translated reader-page and detail aliases.
- **Pre-render translation overlays.** Translations are applied to the record
  Contao is about to render, before rendering starts. Contao renders every
  element exactly once through its own pipeline; the bundle never instantiates a
  content element, calls `generate()` or replaces a rendered buffer.
- **Strict and fallback page availability** per non-default language. Strict
  requires a published page translation; fallback renders the source page content
  under a canonical target-language URL.
- **Availability-aware switcher and metadata.** Language switcher entries,
  canonical tags, `hreflang` and `x-default` reflect the actual availability of
  the complete current resource, including detail records.
- **Explicit translation-field allowlists** with structural, technical and
  publication fields protected by default, plus a tagged contributor interface
  for third-party fields.
- **Source-change tracking and review status** (`unreviewed`, `up_to_date`,
  `needs_review`) based on a SHA-256 fingerprint of the translatable source
  fields, with an explicit "mark as reviewed" action.
- **Connected and free content translation modes** per non-default language.
  Connected keeps the source structure authoritative; free gives the language an
  independent article and content tree that renders natively through Contao.
- **Integrity subsystem** with read-only scanning, stable issue codes, severity
  levels, repair planning, previews, transactional execution, safe cascading
  cleanup and quarantine instead of destructive guessing.
- **Console commands**
  `contao-multilingual-pagetree:integrity:scan`,
  `contao-multilingual-pagetree:integrity:repair` (dry run by default) and
  `contao-multilingual-pagetree:data-report` (read-only).
- **Compatibility matrix** covering every supported Contao 5 minor line as
  mandatory CI jobs, including real container compilation and repeated database
  migrations.

### Security

- Every state-changing backend action of the bundle now goes through one central
  guard that requires a non-GET request method, a valid Contao CSRF token and
  table-level backend permission — verified server side, in that order. The
  "mark as reviewed" control and the content-mode change were previously
  reachable as GET links with a token; they are now submitted forms.
- Redirect targets after a backend action are generated from validated
  parameters only and are never taken from request input.
- Table and column identifiers used in SQL are resolved from the field-policy
  registry or fixed constants and validated against a pattern; all values are
  bound as parameters.
- Integrity deletion is restricted to bundle-managed translation tables. Source
  and default-language content can never be deleted by a repair; free records may
  only be quarantined.

### Changed

- **The product is now issued under one licence model: free of charge, for life,
  granting every feature.** There is no paid tier and no time-limited tier any
  more. `free` is the only accepted package value, and its baseline now carries
  all four capabilities, so free content mode and integrity repair no longer sit
  behind a tier this product does not sell. Free of charge is deliberately not
  licence-free: activation, signature verification, exact-domain binding and
  per-root scope are unchanged, and an installation that has activated nothing
  still gets nothing.
  - A document carrying an end date is refused outright rather than honoured
    until it runs out. It is rejected at activation, so it is never written, and
    rejected again at the entitlement gate, so a copy arriving through the
    server-initiated updater cannot grant anything either. The administrator
    sees the new `term_not_supported` category.
  - The expired-tier free fallback is gone with the tier it fell back to. A
    withdrawn entitlement now stays withdrawn: `free_available` can no longer
    turn one back on, because this product's only tier *is* the free one. The
    unreachable `expired` capability denial and the `granted_free_fallback`
    status were removed rather than left as dead vocabulary.
- **The module-entry signal is claimed per website root, not per session.**
  Entitlement is scoped to a root and each root carries its own key, so opening
  the licence section of a second root in one backend session is a second entry;
  a single session-wide marker silently dropped it. The marker still holds a
  bare flag under a name carrying only the root id - never the key, the host or
  anything derived from them.


- **The public documentation was rewritten with German as the default language
  and English as the alternate.** `README.md` is German and `README.en.md` is
  its full English equivalent; each links to the other. The runbook and the
  licence guide follow the same convention as the user guide, so
  `docs/RUNBOOK.md` and `docs/PRODUCT-REGISTRATION.md` are replaced by
  `.de.md`/`.en.md` pairs. Both READMEs document the corrected language-URL
  semantics, the entitlement matrix, the runtime directories and the observable
  security model; internal verification, communication and storage mechanisms
  remain deliberately undocumented. Tests now assert that every relative
  document link resolves, that each language pair cross-links, and that no
  public document names a class of the licensing subsystem.
- **Every first-party source file carries the same header**, derived from the
  package metadata (name, author, licence and website). Eleven files had none
  and two were missing the website line; each format keeps the comment syntax it
  already used.
- The bundle logs to its own Monolog channels, `contao_multilingual_pagetree` and
  `contao_multilingual_pagetree_integrity`, instead of the shared Contao channel.
- Package metadata is prepared for distribution: no hardcoded `version`, explicit
  authors and support links, `minimum-stability`/`prefer-stable`, plugin
  allowlist and archive exclusions.

### Known limitations

See the "Known limitations" section of `README.md`.
