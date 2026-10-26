# v0.1.0
## 10/26/2026

1. [](#new)
    * First release. Lists the `git:` packages of `user/.dependencies` with their
      pinned, installed and latest release tag, and checks out a new tag in place.
    * Admin2 page "Private packages" (super-admins only), with an Update button
      per package, backed by `GET /private-updater/packages` and
      `POST /private-updater/packages/{name}/update`.
    * CLI: `bin/plugin private-updater status | sync | update <name> [<tag>]`.
    * Refuses unknown or malformed tags, packages modified on the server and
      symlinked development copies. Updates are locked and logged to
      `logs/private-updater.log`.
