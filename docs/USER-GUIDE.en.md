# User guide – English

*Deutsche Fassung (Standardsprache): [USER-GUIDE.de.md](USER-GUIDE.de.md)*

## Core concept

Each Contao website root has one source language and any number of configured target languages. Target languages are managed through the globe action on the root page. Every root page forms an independent website boundary.

## Initial setup

1. Open **Site structure** and edit the website root.
2. Check its primary domain.
3. In the licence panel, activate the license issued for that domain.
4. Save the root and open language management through the globe icon.
5. Add the target languages. Exactly one language is the source or fallback language.
6. Select availability and content modes for every target language.

## Editing translations

Supported records show language tabs in their normal editing form. For every translatable field, select:

- **Inherit from source:** use the current source value.
- **Use custom translation:** use the entered language value.
- **Leave deliberately empty:** keep the field empty in this language.

Technical, structural, and publication fields remain protected. Publication status and publication windows can be controlled per language where the form offers them.

This applies to pages, articles, news, events and FAQs. Content elements are edited differently - see **Translating content elements**.

## Connected and free content

Connected mode keeps structure, type, and order under source-language control. Only approved fields are translated. Free mode gives the target language independent articles and content elements; source content is not rendered automatically.

Switching modes does not delete content. Before switching, the interface explains which records will become active or inactive.

## Translating content elements

Choose the language with the language tabs at the top of the form. The form of a target language is the same form as the source language: the same sections, the same field order, the same editor and the same selectors. You translate directly in the normal fields.

There are no extra per-field selectors and no separate section for translatable content. The active language tab already shows which language you are editing.

As long as a field has no saved translation, the form shows the source-language text. It only becomes a translation once you change that text and save. Text you leave unchanged stays connected to the source language and keeps following later changes there.

Fields that belong to the structure of the element - element type, image selection, image size or CSS settings, for example - are controlled by the source language in connected mode and are therefore not editable.

You edit the same content element as in the source language - only the values belong to the language you selected. A text element therefore stays a text element in every language and shows the same form. In free mode you choose the element type yourself, as usual.

## Untranslated content

What happens to a field that has not been translated yet is decided by the **Page availability** setting of that language in the website root language settings:

- **Fallback to default language:** untranslated content is rendered in the source language.
- **Strict:** untranslated content is not rendered; no source-language text appears.

If you deliberately clear a field and save it, it stays empty in that language - even when fallback is enabled.

## Review after source changes

Page translation review shows whether a translation is unreviewed, current, or needs review after a source-language change. **Mark as reviewed** records the current editorial state; it does not alter publication or routing. Content-element language tabs intentionally contain no review controls.

## Language switcher and URLs

Without further settings the default language remains unprefixed and target languages use prefixes such as `/de/`. For each additional root language, **Page availability** either hides untranslated pages (including direct requests) or shows the source page while keeping the selected language active. **Content translation mode** either omits missing connected content translations or shows source content without copying it. The existing switcher module supports horizontal and vertical flags, labels, or both; unavailable-language handling, hiding the active language and custom `mod_*` templates continue to apply.

## Language URL: protocol, domain and entry point

Every language of a website root can get an address of its own in the **Language URL** section. All three fields are optional.

### Protocol

- **Inherit from website root** (default): the language uses the protocol configured on the root page.
- **HTTPS** or **HTTP**: the language always uses that protocol.

Protocol alone never distinguishes two languages. Two languages sharing the same hostname and entry point must not differ only by protocol.

### Domain

Leave empty to use the website root domain. Otherwise enter exactly one hostname, for example `www.xyz.de` or `de.example.org`.

The hostname is taken exactly as entered: only letter case and an accidental final dot are cleaned up. `example.com` and `www.example.com` remain two different addresses; `www` is never added or removed. Protocols, paths, query strings, fragments, ports and wildcards are rejected.

### Entry point

What an empty field means depends on whether the language has a domain of its own:

- **with its own domain:** the language is served from that domain's root. The domain `bauland-ru.taheri.cool` becomes `https://bauland-ru.taheri.cool` - the language code is *not* appended.
- **without its own domain:** the previous URL strategy is kept - the default language unprefixed, every other language below its language code.

An empty field and an explicit `/` are **not** the same thing.

- `/` means the language lives at the root of its domain.
- `/de` means the language lives below that path prefix.

Convenient input is normalised: `de` becomes `/de`, `/de/` becomes `/de`. An entry point always matches on complete path segments: `/de` covers `/de`, `/de/` and `/de/about`, but never `/demo` or `/development`.

### Examples

Same domain with entry points:

| Language | Domain | Entry point | Address |
| --- | --- | --- | --- |
| English | *(empty)* | `/` | `https://www.xyz.com/` |
| German | *(empty)* | `/de` | `https://www.xyz.com/de` |
| Russian | *(empty)* | `/ru` | `https://www.xyz.com/ru` |

Its own domain without an entry point:

| Language | Domain | Entry point | Address |
| --- | --- | --- | --- |
| German | *(empty)* | *(empty)* | `https://bauland.taheri.cool` |
| English | *(empty)* | `/en` | `https://bauland.taheri.cool/en` |
| Russian | `bauland-ru.taheri.cool` | *(empty)* | `https://bauland-ru.taheri.cool` |

Separate domains:

| Language | Domain | Entry point | Address |
| --- | --- | --- | --- |
| English | *(empty)* | `/` | `https://www.xyz.com/` |
| German | `www.xyz.de` | `/` | `https://www.xyz.de/` |
| Russian | `www.xyz.ru` | `/` | `https://www.xyz.ru/` |

Mixed:

| Language | Domain | Entry point | Address |
| --- | --- | --- | --- |
| English | *(empty)* | `/` | `https://www.xyz.com/` |
| German | `www.xyz.de` | `/de` | `https://www.xyz.de/de` |
| Russian | *(empty)* | `/ru` | `https://www.xyz.com/ru` |

### What is not allowed

So that an incoming request stays unambiguous, saving is rejected for:

- two published languages with the same hostname **and** the same entry point,
- two mappings that become identical only after normalisation,
- two languages differing only by protocol,
- more than one language claiming `/` on the same hostname,
- a hostname that already belongs to another website root.

Two languages may both use `/` only when their hostnames differ. Ambiguous configurations are never resolved by picking a winner; they are rejected with a message.

Canonical URLs, `hreflang`, `x-default`, the language switcher and detail switching for news, events and FAQs always use the target language's protocol, hostname and entry point.

## Integrity and repair

The integrity scan reports issues such as missing sources, duplicate translations, invalid aliases, and cross-site relations. Scanning never changes data. Always review the preview before confirming repairs. Ambiguous relations are not guessed or merged automatically.

## Licence management

Manage the licence from the edit form of the relevant website root. The displayed domain must match the domain for which the key was issued. **Activate** sets up a root that has no licence yet; **Replace** exchanges the key of one that has; **Refresh** fetches the status again; **Verify** rechecks the stored status locally; **Remove** deletes only the stored licence status, never editorial content.

If an action reports an error, retain the displayed reference and pass it to the administrator. Never include licence keys in tickets, screenshots, or logs.

## Disabling and uninstalling

Back up the database and files before making changes. Stop editorial changes first, then inspect retained data with the report or integrity scan. Removing the Composer package does not automatically delete stored translation data.
