# SilverShop Product Identity

[![CI](https://github.com/silvershop/silvershop-product-identity/actions/workflows/ci.yml/badge.svg)](https://github.com/silvershop/silvershop-product-identity/actions/workflows/ci.yml)

Product-identity **master data** for [SilverShop](https://github.com/silvershop/silvershop) on Silverstripe CMS 6:
**EAN/GTIN** and **MPN** fields on both `Product` and `Variation`, with a reusable GS1 check-digit validator and CMS
validation.

This is a deliberately small, **dependency-light foundation** (it requires only `silvershop/core`). GTIN/EAN and MPN
are product master data consumed by *many* things — the product feed (`g:gtin` / `g:mpn`), the Product JSON-LD rich
result (`gtin` / `mpn`), conversion tracking (catalogue matching), plus POS/scanning, stock, packing slips,
search-by-barcode and import/export — so it is owned by none of them. Every consumer reads it **guarded**
(`class_exists` / `hasMethod`) and degrades gracefully when it is absent.

## Requirements

- PHP 8.3+
- `silverstripe/framework` ^6.0, `silverstripe/cms` ^6.0
- `silvershop/core` ^6.2

## Installation

```bash
composer require silvershop-suite/product-identity
```

Then run `dev/build?flush=all`.

## What it adds

Two fields, on **both** `SilverShop\Page\Product` and `SilverShop\Model\Variation\Variation`:

| Field  | Type          | Purpose                                                                   |
|--------|---------------|---------------------------------------------------------------------------|
| `GTIN` | `Varchar(14)` | EAN-8 / UPC-A (12) / EAN-13 / GTIN-14 barcode, stored digits-only         |
| `MPN`  | `Varchar(70)` | Manufacturer part number (alphanumeric, free-form)                        |

The GTIN is **per-variant**: each sellable variant carries its own barcode, so the variation field is the
authoritative GTIN. The product's field covers simple products and acts as the **group-level fallback** for variants
that have no GTIN of their own.

On the product edit form the fields sit on the **Content** tab directly after the product code (SKU); on the variation
form they follow the variant's product code. They are plain, **non-translatable** fields — barcodes and part numbers
are locale-invariant.

## Validation

`SilverShop\ProductIdentity\GtinValidator` is a stateless helper you can reuse anywhere (importers, POS, tests):

```php
use SilverShop\ProductIdentity\GtinValidator;

GtinValidator::isValid('4006381333931'); // true  (valid EAN-13)
GtinValidator::isValid('4006381333930'); // false (bad check digit)
GtinValidator::normalise('40 06381-333931'); // "4006381333931"
```

A code is valid when, after stripping spaces and hyphens, it is **digits only**, of length **8, 12, 13 or 14**, with
a correct **GS1 mod-10 check digit**. The check proves the code is *well-formed* only — not that it is a real,
registered product (a GS1 / third-party lookup is out of scope for v1).

Enforcement runs through the `updateValidate()` extension hook on both Product and Variation, so it blocks both CMS
saves and programmatic `->write()`:

- **Empty is always allowed.**
- A **non-empty, invalid** GTIN is rejected with a field-level error.

### `validation_mode`

Blocking is the default (Google and other channels reject invalid GTINs). Shops mid data-cleanup can downgrade it to
a non-blocking warning:

```yaml
SilverShop\ProductIdentity\GtinValidator:
  validation_mode: warn   # 'block' (default) | 'warn'
```

## Reading the identifiers (for consumers)

Use the resolved accessors rather than the raw fields — they implement the variant→product fallback in one place:

```php
$buyable->getResolvedGtin();  // variation's own GTIN, else the product's; a product returns its own
$buyable->getResolvedMpn();   // same fallback for MPN
$buyable->getHasIdentifier(); // true when a GTIN or MPN is present (own or inherited)
```

Consumers should guard the call so the shop still works when this module is not installed:

```php
$gtin = $buyable->hasMethod('getResolvedGtin') ? $buyable->getResolvedGtin() : '';
```

## Core-merge candidate

GTIN/EAN belongs alongside the SKU as first-class product identity. This module is kept generic and tiny on purpose
so the field set and validator can later be proposed **into `silvershop/core`**. Until then it is a standalone
foundation that core does not require.

## Licence

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
