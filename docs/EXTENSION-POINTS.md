# Supported extension points

Only the interfaces listed here are supported for third-party use. Everything
else in `Vtinnovations\ContaoMultilingualPagetree\` is internal: it may change in
any release without notice, even in a patch version.

Third-party implementations are isolated. A contributor or rule that throws is
logged and skipped; it never aborts the core policies or a scan.

## Translation field policy contributors

Register additional translatable fields for a supported entity.

```php
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldPolicyContributorInterface;
use Vtinnovations\ContaoMultilingualPagetree\Translation\TranslationFieldRegistration;

final class ProductNoteTranslationFields implements TranslationFieldPolicyContributorInterface
{
    public function registrations(): iterable
    {
        yield new TranslationFieldRegistration('tl_content', 'note', 'string', 'product_note');
    }
}
```

Services implementing the interface are tagged automatically.

**Contract**

- Value types: `string`, `headline`, `serialized_array`, `boolean`, `integer`, `nullable`.
- Content-element registrations must name a content type.
- Structural, technical and publication fields can never be reclassified; such
  declarations are ignored.
- Core policy entries always win over contributor entries.
- Duplicate declarations are resolved deterministically by contributor class name,
  so registration order never changes the outcome.
- Registered fields participate automatically in overlays, translation forms,
  review fingerprints and integrity checks — no additional hooks are required.

## Integrity rules

Add checks to the integrity scanner.

```php
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRuleInterface;
```

Services implementing the interface are tagged automatically.

**Contract**

- `scan()` must be **read-only**. Writing during a scan is a contract violation.
- Execution order is deterministic: descending priority, then rule name.
- `getSupportedEntities()` returning `[]` means "all entities".
- Return an `IntegrityIssueCollection`; issue codes should be stable strings.
- Only issues marked `REPAIR_AUTOMATIC` or `REPAIR_CONFIRMATION` are planned;
  `REPAIR_MANUAL` is reported and left to an editor.
- Exceptions are caught, logged and reported as a `rule_failure` issue.

## Read-only services

These services may be consumed but not replaced. Their method signatures are
stable; their concrete classes are not part of the contract.

| Service | Purpose |
| --- | --- |
| `Availability\PageAvailabilityResolver` | Strict/fallback page availability for one page and language |
| `Availability\ResourceAvailabilityResolver` | Availability of the complete current resource, including detail records |
| `Availability\SiteLanguageRegistryInterface` | Configured languages, default language and modes of one root site |
| `Content\ContentTranslationModeResolver` | Connected or free mode for one page and language |
| `Detail\DetailTargetResolverInterface` | Target URL of the current detail record in another language |
| `Translation\TranslationOverlayResolver` | Field-state-aware value resolution |
| `Review\SourceFingerprintCalculator` | Deterministic fingerprint of the translatable source state |

## Not extension points

- DCA callback classes (`Backend\*`)
- Event listeners and hook classes (`EventListener\*`)
- Route decorators (`Routing\*`)
- Storage implementations (`Database*` classes)
- The DI extension and bundle class
- Console commands

Do not extend or replace these. If you need behaviour they do not provide, open
an issue describing the use case.
