# WordPress.org publication

This directory contains Plugin Directory assets and publication notes. It is
excluded from the merchant-installable ZIP by `scripts/build-plugin-zip.sh`.

## Target identity

- Display name: **Изпрати.БГ Shipping for WooCommerce**
- Requested slug: `izprati-bulgaria-shipping`
- Main plugin file: `octavawms-woocommerce.php`
- WordPress.org account: use the TagOnTrack/Изпрати.БГ company account and a
  regularly monitored company email address.

The WordPress.org submission UI derives a provisional slug from the display
name. Change it to `izprati-bulgaria-shipping` before review begins if the UI assigns
a different slug. The slug cannot be changed after approval.

The 9 October review identifies the currently assigned submission slug as
`shipping-labels-bulgaria`. Request `izprati-bulgaria-shipping` explicitly in
the existing review thread before approval. The package directory and text
domain are aligned with that request; the local filename alone is not a slug
reservation.

## 9 October review follow-up (unreleased)

The local review fixes bundle Leaflet 1.9.4 with readable source, its BSD license,
source map and marker images. Checkout no longer downloads executable assets
from unpkg.com. The readme discloses optional OpenStreetMap tiles and location
use. Missing-configuration notices are restricted to this integration's settings.
Guest point lookup now requires the WordPress nonce verifier unconditionally.

`scripts/build-plugin-zip.sh [version] [channel]` defaults to `wordpress-org`,
which excludes bundled PO/MO files. Use the `marketplace` channel to retain them;
its output has a `-marketplace.zip` suffix. Built-in brand string mappings remain
part of the implementation in both channels.

The code now removes the unsupported PHP `Tested up to` header, makes checkout
attribution a default-off merchant setting, and prepares release 1.6.3.

Owner identity verification uses the reviewer's TXT method. Add
`wordpressorg-tasselchof-verification` at the owner domain root without
replacing existing TXT records, and read it back through public DNS before
replying.

## Submission package

Build from a clean checkout at the intended release commit:

```bash
composer check
composer validate --strict
./scripts/build-plugin-zip.sh 1.6.3
unzip -l dist/izprati-bulgaria-shipping-1.6.3.zip
```

The submission ZIP must contain exactly one top-level directory named
`izprati-bulgaria-shipping`. Directory artwork in this folder must not be included
inside that ZIP.

## Review notes

The plugin connects WooCommerce to the hosted Изпрати.БГ shipping service.
Its `readme.txt` contains the required external-service disclosure, including
the transmitted data categories and links to the public service terms and
privacy policy. The plugin has no bundled third-party PHP dependencies and
ships readable JavaScript and CSS source.

The 1.6.1 baseline passed Woo QIT Security and Validation. Version 1.6.3 adds
the WordPress.org disclosures, publication safeguards, and reviewer-requested
remediations without changing runtime routes or the hosted service contract.

## Verification evidence

- `composer check`: 254 tests, 920 assertions, no failures or warnings.
- `composer validate --strict`, JavaScript syntax validation, and
  `git diff --check`: passed.
- Official WordPress.org readme validator: no errors or warnings; optional notes
  only (upgrade notice, screenshots, and donate link).
- WordPress.org ZIP: 65 files under one `izprati-bulgaria-shipping/` root,
  no bundled PO/MO catalogs or development files. SHA-256:
  `349ca9a1b20358a1ca48c30dd816cec8d6cbb49749c5b7780e9bae3156ca59dc`.
- Marketplace ZIP: 71 files under the same root, including six PO/MO catalogs.
  SHA-256:
  `e32a6cd7602f69be3c161d1ecc6c5ccd048be108ce38f0859f31169d3299014a`.

The final WordPress.org archive was checked with the official Plugin Check
2.1.0 release on WordPress 7.1.3 using all non-experimental categories: 0
errors and 65 warnings. Sixty-two warnings are nonce-flow heuristics on
request-reading code whose mutating entry points enforce nonces; two cover the
intentional WooCommerce credential-table query, and one covers the translation
loader retained for non-WordPress.org distributions. The preceding 1.6.1
package also passed Woo QIT Security and Validation.

## SVN layout after approval

```text
assets/
trunk/
tags/1.6.3/
```

Copy the contents of the built ZIP's `izprati-bulgaria-shipping/` directory directly
into `trunk/` (not into a nested subdirectory), copy the same release to
`tags/1.6.3/`, and copy this directory's PNG assets to the SVN `assets/`
directory. Set PNG MIME types before committing.

Do not place credentials, order data, labels, signed URLs, or legal-entity
evidence in Git, the ZIP, SVN, the public readme, or WordPress.org support.
