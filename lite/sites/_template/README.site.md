# __SITE__: production replica on isle-site-lite

This folder was scaffolded by `make site-add SITE=__SITE__` in
[isle-site-lite](../../../README.lite.md). It is a local working folder, git-ignored and not
versioned (the folder name must stay `__SITE__`), driven with `make site-* SITE=__SITE__`
from the isle-site-lite root.

Here: `site.env` (settings), `docker-compose.yml` (rendered from `site.env`, re-render with
`make site-render`), `site-files/` (small public files named in config, such as the theme
logo), `NOTES.md` (assess report and decisions).

Provided by you:

| Where | What |
| --- | --- |
| `repo/` | the site's Drupal composer project (`git clone <internal GitHub> repo`) |
| `themes/<name>/` | each theme listed in `SITE_THEMES` (private GitHub) |
| `db/` | the database handover: a `mysqldump` file (`.sql`, `.sql.gz`, `.sql.xz`) or a `.tar.gz` of raw MySQL 8.0 `.ibd` files |

Then, from the isle-site-lite root:

```bash
make site-check  SITE=__SITE__   # validate the inputs above (read-only); repeat until "Ready for intake"
make site-intake SITE=__SITE__   # verify code/themes, stage them, normalize the database to db/__SITE__.sql.xz, write NOTES.md
make site-build  SITE=__SITE__   # image FROM the Lite image + this site's config/sync, themes, site-files
make site-up     SITE=__SITE__   # start db-__SITE__ + drupal-__SITE__, first start localizes (updb, Solr, placeholders)
make site-login  SITE=__SITE__
```

Site URL: `http://__SITE__.islandora.io` (or https when the stack runs with `URI_SCHEME=https`).
Files are never part of a handover: at first start every `file_managed` row is re-pointed to
a generic file of its media type (`SITE_PLACEHOLDERS=relink`). Production accounts are never
used either: their rows are dropped from the normalized dump and the Lite dev site's accounts
are copied in (log in as `admin` with `secrets/DRUPAL_DEFAULT_ACCOUNT_PASSWORD`).
