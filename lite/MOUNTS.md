# How code reaches `/var/www/drupal`: no bind mounts

Neither the Lite `drupal` container nor a replica's `drupal-<site>` container bind-mounts the
Drupal tree from the host. The code is **copied into the image at build time**. The only
volumes are named Docker volumes for files and the Solr core.

## `drupal` (the Lite site, container `isle-lite-drupal-1`)

- `docker-compose.lite.yml` (service `drupal`) only changes the build context to `./lite/drupal`.
- `lite/drupal/Dockerfile` runs `COPY --link rootfs /`, which bakes
  `lite/drupal/rootfs/var/www/drupal` (the git-ignored `islandora-lite-site` clone) into the image.
- Volumes, from upstream `docker-compose.yml` (service `drupal`). All are named volumes:

  | Volume | Target |
  | --- | --- |
  | `drupal-public-files` | `/var/www/drupal/web/sites/default/files` |
  | `drupal-private-files` | `/var/www/drupal/private` |
  | `drupal-solr-config` | `/opt/solr/server/solr/default` |

- The running container does not see host edits under `lite/drupal/rootfs/var/www/drupal`
  until you run `make build && make up`.

## `drupal-<site>` (a replica added with `make site-add SITE=<site>`)

Defined in `lite/sites/_template/docker-compose.site.yml` and rendered to
`lite/sites/<site>/docker-compose.yml`.

- Built from `lite/site-image/Dockerfile`, `FROM lite` (the image built by the `drupal` service), with
  build contexts `stage` = `lite/sites/<site>/stage` and `sitefiles` = `lite/sites/<site>/site-files`:
  - `RUN rm -rf /var/www/drupal/config/sync`, then
    `COPY --from=stage --chown=nginx:nginx / /var/www/drupal/`: the site's `config/sync`,
    `web/themes/custom/*` and `web/modules/custom` (staged by `make site-intake`) are copied over the Lite tree.
  - `COPY --from=sitefiles / /opt/lite/site-files/`: small public files named in config (theme logo, ...).
  - `COPY rootfs/ /`: first-start scripts, finalize, placeholders, Solr installer, MySQL client wrapper.
- Volumes. All are named volumes:

  | Volume | Target |
  | --- | --- |
  | `drupal-<site>-public` | `/var/www/drupal/web/sites/default/files` |
  | `drupal-<site>-private` | `/var/www/drupal/private` |
  | `solr-core-<core>` | `/opt/solr/server/solr/<core>` |

- Changes reach the container only by rerunning `make site-deploy SITE=<site>` (intake + build + up).
  After a change to the Lite tree or `lite/drupal/`, rebuild Lite first, because replicas are built `FROM` it.

## Live editing: `SITE_DEV_MOUNTS=true`

You can turn on bind mounts per site in `lite/sites/<site>/site.env`:

```bash
SITE_DEV_MOUNTS=true
make site-render SITE=<site> && make site-up SITE=<site>
```

`make site-render` (`lite/bin/lite-site`, `dev_mount_dirs`) then writes **read-only** bind mounts into
`lite/sites/<site>/docker-compose.yml` at the `#__DEV_MOUNTS__` line of the template. It mounts only the folders
that exist at render time, and prints each one:

| Host (your checkouts, not `stage/`) | Container |
| --- | --- |
| `lite/sites/<site>/themes/<name>` (each theme in `SITE_THEMES`) | `/var/www/drupal/web/themes/<name>` |
| `$SITE_CODE_DIR/web/modules/custom` | `/var/www/drupal/web/modules/custom` |
| `$SITE_CODE_DIR/web/themes/custom` | `/var/www/drupal/web/themes/custom` |

- Edits show up without a rebuild. Twig, library and info changes still need `make site-drush SITE=<site> CMD=cr`.
- The mounts sit over the copies baked into the image, which still come from `stage/` and are used again
  when you set `SITE_DEV_MOUNTS=false`, re-render and run `make site-up`.
- The mounts are read-only on purpose: the container never writes into your git checkouts.
- `config/sync` is never mounted. Config still goes through intake and the image, and the database is the source of truth.
- The image rewrites `core_version_requirement` in theme info files (`lite/site-image/Dockerfile`), but a mounted
  theme is used as is. If its `core_version_requirement` excludes `^11`, Drupal 11 marks it incompatible.
  `make site-render` warns about this. (Drupal 11 ignores the old `core: 8.x` key.)
- Only these folders are mounted. A mount of all of `/var/www/drupal` would hide the `vendor/` and `web/core`
  that the Lite image installed.
- If you add a theme or a `custom` folder later, re-render. `make site-check`/`site-deploy` fail until you do,
  because the rendered file no longer matches.
- Sites created before this change have no `SITE_DEV_MOUNTS` line (the default is `false`). Because the
  template changed, run `make site-render SITE=<site>` once anyway.
