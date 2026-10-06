# Islandora Lite on isle-site-template

This repository is an unmodified clone of
[Islandora-Devops/isle-site-template](https://github.com/Islandora-Devops/isle-site-template)
(remote `upstream`, branch `lite`) plus the files listed below. See `INTENT.md` for the
reasoning and the full design.

| Ours | Purpose |
| --- | --- |
| `custom.Makefile` | `lite-*` targets (`make help` lists them) |
| `docker-compose.lite.yml` | override: disables everything but drupal, mariadb, solr, blazegraph, cantaloupe, fits, traefik; points `drupal` at `lite/drupal` |
| `lite/env.sample` | `.env` defaults (`isle-lite`, `islandora.io`, `ISLANDORA_TAG`) |
| `lite/drupal/` | drupal image build context: Dockerfile, s6 first-start install, D11-safe drush settings command |
| `lite/site/` | the complete `composer.lock` (320 packages) copied over the site clone; the lock committed on `drupal-11` lacks the `composer_site.json` packages |

## Quick start

```bash
make lite-init      # .env, override symlink, site clone + overlay, secrets, certs, image build
make lite-up        # upstream `make up` (starts the stack, waits for "Install Completed") + lite-ping
make lite-login     # drush uli
```

macOS note: upstream scripts need GNU `timeout`. When the host has none,
`custom.Makefile` puts `lite/bin/timeout` (a perl shim) on `PATH` for every make target,
so plain `make up` works too. `make lite-ping` is an extra readiness check.

Site: http://islandora.io (admin password: `cat secrets/DRUPAL_DEFAULT_ACCOUNT_PASSWORD`).
Dev-mode URLs: http://solr.islandora.io, http://blazegraph.islandora.io/bigdata/,
http://islandora.io/cantaloupe/iiif/2/, http://traefik.islandora.io.

HTTPS: `make traefik-https-mkcert && make down-traefik up && make lite-hydrate`.

## Everyday

```bash
make lite-drush CMD="status"      # any drush command
make lite-shell                   # shell in the drupal container
make lite-hydrate                 # re-point Solr/Blazegraph/IIIF config at this stack
make lite-status                  # upstream status + active/disabled services
make down / make up               # stop / start, data kept
make clean                        # upstream: DESTRUCTIVE, removes volumes, secrets, certs, .env
```

The site tree lives at `lite/drupal/rootfs/var/www/drupal` (a clone of
`digitalutsc/islandora-lite-site`, branch `drupal-11`, git-ignored here). It is baked
into the image: after changing it, `make build && make up`.

## Updating from upstream

```bash
make lite-upstream-check   # must print nothing: no upstream file is modified
make lite-upstream-pull    # fetch + merge upstream/main, lists mirrored files that changed
```

Then port changes by hand from `sample.env`, `docker-compose.yml`, `drupal/Dockerfile`
and `drupal/rootfs/etc/s6-overlay/` into their `lite/` mirrors (INTENT.md §7).
