# Optional Izprati installation reporting

Enabled only by an administrator under Settings > Izprati reporting. The checkbox is off on a fresh installation. Contact email is optional and explicitly submitted. Registering a site never creates a person, password, membership or subscription, and does not link to an existing customer by domain/email. The existing AccountPortal registration/login link is a separate explicit action.

The reporter uses the public AccountPortal version-1 WordPress installation endpoint, a locally generated site UUID and 256-bit site credential. No internal portal/n8n/service token is included. Events are persisted before sending and removed only on a matching acknowledgement. It retries network failures with backoff through WordPress cron; admin observations only queue state changes. Manual save/retry attempts delivery immediately. Disabled reporting never participates in local shipping/inventory decisions.

Disabling clears the local contact and queued observations, then attempts a minimal withdrawal (which also covers an ambiguous first registration response). No products, orders or stock are transmitted or modified. Site-address changes disable inherited reporting; fresh consent generates a new identity so a clone cannot report as or withdraw the original site. Plugin removal cannot guarantee a network notification, and a missed heartbeat is not an uninstall.

Run composer check. The new subprocess harness exercises disabled operation, outages, malformed acknowledgements, exact retry payloads, withdrawal, re-consent and clone isolation. It uses only synthetic data and WordPress doubles. The AccountPortal repository contains the database-backed and cross-repository runner in scripts/verify-wordpress-integration.ts, plus docs/wordpress-installations.md for rollout and rollback.

Release/deployment are separate from implementation. Publish only after AccountPortal admission, the database migration, n8n receipts, a hosted privacy-policy review and controlled WordPress acceptance are approved and verified. The ZIP built from this branch is a review artifact at the existing version, not a new published release.


## First setup registration (14 November 2026)

On the Plugins page and the plugin’s own setup screen, administrators see explicit Register and share store details / Continue without reporting actions. The request is POST-only, nonce-checked and requires manage_options. Neither viewing nor declining sends a request. The choice is scoped to the current site URL; clones require fresh consent. The existing Settings reporting page remains available for later changes. Optional contact email is never prefilled. Registration immediately attempts delivery; matching acknowledgements and durable cron retries remain unchanged.

The existing Connect action discloses automatic AccountPortal registration. Only a successful 2xx connect response accepted by the credential client fires the local service-connected hook. It persists a per-site intent and queues registration, including after an earlier optional-reporting decline. It does not implicitly share admin email. Cron retries delivery without adding a second HTTP request to the shipping connection. Queue contention retains the intent; clones cannot consume it. Existing copied/manual API keys do not silently enroll a site. A later explicit withdrawal cancels pending service registration. Successful service registration does not create a person/login or grant account access.
