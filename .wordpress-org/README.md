# WordPress.org publication

This directory contains Plugin Directory assets and publication notes. It is
excluded from the merchant-installable ZIP by `scripts/build-plugin-zip.sh`.

## Target identity

- Display name: **Изпрати.БГ Shipping for WooCommerce**
- Requested slug: `izprati-bg-shipping`
- Main plugin file: `octavawms-woocommerce.php`
- WordPress.org account: use the TagOnTrack/Изпрати.БГ company account and a
  regularly monitored company email address.

The WordPress.org submission UI derives a provisional slug from the display
name. Change it to `izprati-bg-shipping` before review begins if the UI assigns
a different slug. The slug cannot be changed after approval.

## Submission package

Build from a clean checkout at the intended release commit:

```bash
composer check
composer validate --strict
./scripts/build-plugin-zip.sh 1.6.2
unzip -l dist/izprati-bg-shipping-1.6.2.zip
```

The submission ZIP must contain exactly one top-level directory named
`izprati-bg-shipping`. Directory artwork in this folder must not be included
inside that ZIP.

## Review notes

The plugin connects WooCommerce to the hosted Изпрати.БГ shipping service.
Its `readme.txt` contains the required external-service disclosure, including
the transmitted data categories and links to the public service terms and
privacy policy. The plugin has no bundled third-party PHP dependencies and
ships readable JavaScript and CSS source.

The 1.6.1 baseline passed Woo QIT Security and Validation. Version 1.6.2 adds
WordPress.org disclosures and publication safeguards without changing runtime
routes or the service contract.

## Verification evidence

- `composer check`: 211 tests, 867 assertions, no failures or warnings.
- Official WordPress Plugin Check 2.1.0, all non-experimental categories:
  0 errors and 65 warnings.
- Official WordPress.org readme validator: no errors or warnings; optional notes
  only (upgrade notice, screenshots, and donate link).
- Release ZIP SHA-256:
  `e5e3dded7120d99de0173985fb7b8c7a9b71e83f67929127c45da890adba9716`.

The Plugin Check warnings were reviewed rather than suppressed. Sixty-two are
nonce-flow heuristics: privileged AJAX handlers verify their nonce in the outer
dispatcher, while checkout fields are processed inside WooCommerce's checkout
flow. Two concern the prepared, allowlisted WooCommerce REST-key lookup, and one
concerns the bundled translation loader. The preceding 1.6.1 package also
passed Woo QIT Security and Validation.

## SVN layout after approval

```text
assets/
trunk/
tags/1.6.2/
```

Copy the contents of the built ZIP's `izprati-bg-shipping/` directory directly
into `trunk/` (not into a nested subdirectory), copy the same release to
`tags/1.6.2/`, and copy this directory's PNG assets to the SVN `assets/`
directory. Set PNG MIME types before committing.

Do not place credentials, order data, labels, signed URLs, or legal-entity
evidence in Git, the ZIP, SVN, the public readme, or WordPress.org support.
