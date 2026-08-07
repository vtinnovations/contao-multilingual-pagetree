# Licence management

*Deutsche Fassung (Standardsprache): [PRODUCT-REGISTRATION.de.md](PRODUCT-REGISTRATION.de.md)*

This public guide describes administration and expected behaviour only. Internal verification, communication, storage and release-protection mechanisms are intentionally not documented here.

## Obtain and activate a licence

Contao Multilingual Pagetree requires a valid license from [www.v-t.one](https://www.v-t.one).

1. Ensure that the Contao website root has the correct primary domain.
2. Open **Site structure → edit website root**.
3. Enter the licence key in the licence panel.
4. Select **Activate licence**.
5. After successful activation, the panel immediately updates the status, licence domain, term, and activation state.

The licence applies to exactly the displayed website root and its configured domain. A different domain requires a licence issued for that domain.

## One licence, several domains

A licence can be issued for more than one domain. What counts is always the exact domain: `example.com`, `www.example.com` and `shop.example.com` are three different domains, and a licence for one of them never covers another automatically.

Each website root is activated individually - with the same licence, as long as its configured domain is one of the domains the licence was issued for. The stored status stays separate per root.

If the stored status still comes from an earlier version of this product, the panel reports **Refresh required**. Run **Refresh licence** once in that case; the previously stored status stays untouched until it succeeds.

## Actions

- **Activate licence:** first activation of this website root.
- **Replace licence:** exchange an existing key for a different one.
- **Refresh licence:** deliberately fetch the licence status again.
- **Verify licence:** recheck the already stored licence status locally, without fetching it again.
- **Remove licence:** remove the stored status for this root. Content and translations remain untouched.

Simply opening Page Settings does not initiate an external verification.

## Troubleshooting

The interface distinguishes conditions including missing or invalid keys, domain or product mismatches, not-yet-valid or expired licences, unsupported responses, connectivity problems, and local storage failures.

Retain the displayed **Reference** when requesting help. Never include the licence key, response contents, or credentials in support tickets or logs.

Temporary service unavailability does not automatically change a previously valid local status.

## Licence model

One model, no variants: the licence is free of charge, issued for life, and grants every feature. A licence carrying an end date is refused rather than honoured until it runs out, and a paid package is not accepted at all.

This does not make the product licence-free. Activation, signature verification, exact-domain binding and per-root scope apply exactly as they would to a paid product; only the price is zero.

## Entitlement overview

| Feature | Without licence | With licence |
| --- | --- | --- |
| Create and edit additional languages | Not available | Available |
| Edit translations | Not available | Available |
| Editorial review status | Not available | Available |
| Free content mode | Not available | Available |
| Integrity repair | Not available | Available |
| Frontend output of existing translations | Available | Available |

The table describes administrator-visible access only.

Back to the [project overview](../README.en.md).
