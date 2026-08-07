<p align="center">
  <img src=".github/assets/vt-one-logo.png" alt="V&amp;T Innovations" width="280">
</p>

<h1 align="center">Contao Multilingual Pagetree</h1>

<p align="center">Manage multilingual Contao websites in one shared page tree.</p>

<p align="center">
  <a href="https://packagist.org/packages/vtinnovations/contao-multilingual-pagetree"><img src="https://img.shields.io/packagist/v/vtinnovations/contao-multilingual-pagetree" alt="Packagist version"></a>
  <img src="https://img.shields.io/badge/PHP-%5E8.1-777BB4?logo=php&amp;logoColor=white" alt="PHP ^8.1">
  <img src="https://img.shields.io/badge/Contao-%5E5.0-F47C00?logo=contao&amp;logoColor=white" alt="Contao ^5.0">
  <img src="https://img.shields.io/badge/licence-proprietary-blue" alt="Proprietary licence">
</p>

<p align="center"><em>Deutsche Fassung (Standardsprache): <a href="README.md">README.md</a></em></p>

---

Contao Multilingual Pagetree adds an editorial translation workflow to Contao without creating a separate page tree for every language. All languages of a website share one page structure, and translations are edited through language tabs inside the normal Contao editing forms. Where required, a language can also maintain its content independently.

- **Package:** `vtinnovations/contao-multilingual-pagetree`
- **Type:** `contao-bundle`
- **Namespace:** `Vtinnovations\ContaoMultilingualPagetree`
- **Licence:** proprietary

## Features

- one shared page tree for all configured languages
- language tabs inside the standard Contao editing forms
- connected translations with the field states **Inherit**, **Custom translation** and **Deliberately empty**
- free-language content with independent articles and content elements
- translated aliases for pages, news, events and FAQs
- per-language URLs: protocol, own domain and entry point
- same domain with path prefixes, separate domains, or a mix of both
- language switcher as a frontend module with availability checking
- canonical URLs, `hreflang` and `x-default`
- editorial review status after source-language changes
- integrity scan with a preview before repairs
- strict or fallback page availability per target language
- isolated management per website root in multi-site installations

## Status

The package is functionally implemented and has not been released under a version tag yet. The changelog tracks the state of the `main` branch under `## [Unreleased]`.

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | `^8.1` |
| Contao | `^5.0` (`contao/core-bundle`) |
| Composer | for installation and updates |
| PHPUnit | `^10.5` (development only) |

The news, calendar and FAQ integrations only become active when the corresponding Contao bundle is installed.

## Installation

```bash
composer require vtinnovations/contao-multilingual-pagetree
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

The Contao Manager can be used instead. When updating, replace the package directory completely so that removed files cannot survive.

The database update is required: the package creates its own tables and columns.

## Filesystem and runtime directories

The package only ever writes below `var/`, and therefore outside the public directory:

| Directory | Purpose |
| --- | --- |
| `var/contao-multilingual-pagetree/state/` | internal operating state |
| `var/contao-multilingual-pagetree/licences/` | stored licence status per website root |

Both directories must be writable by the PHP process. A directory that does not exist yet is a valid initial state.

## Backend access

| Entry point | Location |
| --- | --- |
| Language management | **Site structure** → globe action on the website root |
| Licence panel | **Site structure** → edit website root |
| Language tabs | Editing form of supported records |
| Permissions | **Users** and **User groups** |

Language management of a root offers, per language: language code, label, flag, language URL, page availability, content translation mode and publication.

## Language URLs

Every language can define its own protocol, domain and entry point. All three fields are optional.

| Domain | Entry point | Result |
| --- | --- | --- |
| *(empty)* | *(empty)* | previous URL strategy: source language unprefixed, other languages below their language code |
| *(empty)* | `/en` | website root domain plus `/en` |
| `www.example.ru` | *(empty)* | the root of that domain |
| `www.example.ru` | `/ru` | that domain plus `/ru` |
| any | `/` | the root of whichever domain is effective |

An empty field and an explicit `/` are different states. Ambiguous mappings are rejected on save — for example two published languages with the same hostname and entry point, mappings that become identical only after normalisation, a distinction made by protocol alone, or a hostname that already belongs to another website root.

Details and examples: [user guide](docs/USER-GUIDE.en.md).

## Translating content

**Connected mode** – structure, type and order stay under source-language control; approved fields are translated. Content elements are edited in the same form as the source language; only the values belong to the selected language.

**Free mode** – the target language owns its articles and content elements. Source content is not rendered automatically.

Switching modes does not delete content.

## Page availability

| Mode | Behaviour |
| --- | --- |
| **Fallback to default language** | pages and content without a translation render the source content under the language URL |
| **Strict** | pages without an available translation are not reachable; untranslated content renders no source text |

The setting applies per target language and also governs untranslated content fields.

## Permissions

Access follows native Contao mechanisms: administrators always have access; other backend users require the site-structure module, the relevant page mount and the normal table and field rights. There is no separate bundle-specific licence permission.

Every write operation is checked server side. A control hidden in the form is not a permission.

## Licensing

The package requires a valid licence per website root. The exact configured domain of that root is authoritative.

| Feature | Without licence | Free | Pro |
| --- | --- | --- | --- |
| Create and edit additional languages | Not available | Available | Available |
| Edit translations | Not available | Available | Available |
| Editorial review status | Not available | Available | Available |
| Free content mode | Not available | Not available | Pro only |
| Integrity repair | Not available | Not available | Pro only |
| Frontend output of existing translations | Available | Available | Available |

Operation, states and error handling: [licence management](docs/PRODUCT-REGISTRATION.en.md).

## Security model

- Permissions are enforced server side, not through the visibility of controls.
- Backend write actions run over POST with the Contao request token.
- Language, root and record references are validated against the stored configuration; manipulated values are rejected.
- Website roots are isolated from one another; language-specific domains are compared exactly, without wildcards and without inheriting parent domains.
- Licence data is kept outside the public directory.
- Defined licence operations contact a trusted HTTPS service; exchanged data is authenticated and integrity-protected.
- When a check fails, restricted functionality stays disabled.
- Licence keys and complete authentication material appear neither in browser output nor in ordinary logs.

Internal verification, communication and storage mechanisms are deliberately not documented publicly.

## External communication

The package contacts external services only during explicitly triggered licence operations and at the server-authenticated update endpoint. Opening the page settings triggers no external check. Frontend delivery and editorial work happen without external calls.

## Logging

The package writes to its own Monolog channels. Licence operations log result categories and references — never keys and never complete response contents.

## Console commands

```bash
vendor/bin/contao-console contao-multilingual-pagetree:integrity:scan
vendor/bin/contao-console contao-multilingual-pagetree:integrity:repair
vendor/bin/contao-console contao-multilingual-pagetree:data-report
vendor/bin/contao-console contao-multilingual-pagetree:registration
```

The integrity scan never changes data. Review the preview before confirming repairs; ambiguous relations are not resolved automatically.

## Frontend integration

The **language switcher** frontend module (`language_switcher`, category *Miscellaneous*) offers horizontal or vertical flags, labels, or flags with labels. Its existing unavailable-language, hide-active-language and custom module-template options remain available. Page availability is configured per additional root language: untranslated pages are either hidden with a real 404 or rendered through the source page while the selected language stays active. Content fallback is configured separately per language: missing connected content is either omitted or rendered from the source without copying it. Content elements no longer show editorial review controls; page translation review remains available.

Canonical URLs, `hreflang` and `x-default` are emitted automatically and always use the protocol, hostname and entry point of the target language.

## Deployment

```bash
composer install --no-dev --optimize-autoloader
vendor/bin/contao-console contao:migrate
vendor/bin/contao-console cache:clear --env=prod
vendor/bin/contao-console cache:warmup --env=prod
```

After changing a language URL a cache rebuild is required, because mappings and path prefixes are cached.

## Clearing the cache

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console cache:clear --env=prod
```

In the Contao Manager this corresponds to the action that clears the application cache.

## Tests

```bash
composer test
composer test:unit
composer test:integration
composer lint
composer security
```

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Additional languages cannot be created | Check the licence status of the root and its configured domain |
| A language URL has no effect | Rebuild the cache; check the domain and entry-point fields |
| A language on its own domain is unreachable | Check the exact hostname; `www` variants and parent domains do not count |
| Saving a language URL is rejected | Read the message: hostname and entry point are already taken or ambiguous |
| Translations do not appear in the frontend | Check the publication of the language and page availability |
| Unexpected data state | Run `integrity:scan` and review the preview |

Further steps: [runbook](docs/RUNBOOK.en.md) and [server diagnostics](docs/SERVER-SETUP-DIAGNOSTICS.md).

## Known limitations

- For content elements a translation is only stored in fields for which a column exists in the translation store. Fields contributed by third-party extensions are shown in the normal form but become translatable only after registration through the provided extension point.
- When a language is moved to its own domain later, previously valid addresses carrying the language code lose their route. A Contao redirect page or a webserver rule is the intended mechanism for permanent redirects.
- Integrity repair does not resolve ambiguous relations on its own.
- The package requires Contao 5; Contao 4 is not supported.

## Documentation

| Document | German | English |
| --- | --- | --- |
| User guide | [USER-GUIDE.de.md](docs/USER-GUIDE.de.md) | [USER-GUIDE.en.md](docs/USER-GUIDE.en.md) |
| Licence management | [PRODUCT-REGISTRATION.de.md](docs/PRODUCT-REGISTRATION.de.md) | [PRODUCT-REGISTRATION.en.md](docs/PRODUCT-REGISTRATION.en.md) |
| Runbook | [RUNBOOK.de.md](docs/RUNBOOK.de.md) | [RUNBOOK.en.md](docs/RUNBOOK.en.md) |
| Extension points | – | [EXTENSION-POINTS.md](docs/EXTENSION-POINTS.md) |
| Server diagnostics | – | [SERVER-SETUP-DIAGNOSTICS.md](docs/SERVER-SETUP-DIAGNOSTICS.md) |
| Manual verification | – | [MANUAL-VERIFICATION.md](docs/MANUAL-VERIFICATION.md) |
| Changes | [CHANGELOG.md](CHANGELOG.md) | – |
| Upgrading | [UPGRADE.md](UPGRADE.md) | – |

## Uninstalling

Back up the database and files first. Removing the Composer package does not delete stored translation data. Inspect the retained data beforehand with `integrity:scan` or `data-report`.

## Licence and copyright

Proprietary. Copyright: V&T Innovations Team, [www.v-t.one](https://www.v-t.one).

Support: [issue tracker](https://github.com/vtinnovations/contao-multilingual-pagetree/issues).
