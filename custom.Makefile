# Islandora Lite targets. Included automatically by the upstream Makefile (-include custom.Makefile).
# All targets are prefixed lite- so they never shadow an upstream target.

LITE_SITE_REPO ?= https://github.com/digitalutsc/islandora-lite-site.git
LITE_SITE_BRANCH ?= drupal-11
LITE_SITE_DIR := lite/drupal/rootfs/var/www/drupal
LITE_OWN_FILES := lite custom.Makefile docker-compose.lite.yml INTENT.md README.lite.md
LITE_EXEC := docker compose exec -T drupal with-contenv bash -lc

# Upstream scripts (ping.sh, up.sh, generate-certs.sh) need GNU `timeout`, which macOS
# lacks; without it `make up` reports "Site failed to come online" or follows the log
# forever. Provide lite/bin/timeout (perl alarm) for every target when the host has none.
ifeq ($(shell command -v timeout 2>/dev/null),)
export PATH := $(CURDIR)/lite/bin:$(PATH)
endif

.PHONY: lite-init lite-site lite-up lite-ping lite-hydrate lite-drush lite-shell lite-login lite-status lite-upstream-check lite-upstream-pull

lite-init: ## Lite: create .env + override symlink, fetch the site, then upstream init (secrets, certs, build)
	if [ ! -f .env ]; then cp lite/env.sample .env; echo "Created .env from lite/env.sample"; fi
	if [ ! -e docker-compose.override.yml ]; then ln -s docker-compose.lite.yml docker-compose.override.yml; echo "Linked docker-compose.override.yml -> docker-compose.lite.yml"; fi
	$(MAKE) lite-site
	$(MAKE) init

lite-site: ## Lite: clone islandora-lite-site into the build context (if missing) and copy the complete composer.lock over it
	if [ ! -d "$(LITE_SITE_DIR)/.git" ]; then \
		echo "Cloning $(LITE_SITE_REPO) ($(LITE_SITE_BRANCH)) into $(LITE_SITE_DIR)"; \
		git clone --branch "$(LITE_SITE_BRANCH)" "$(LITE_SITE_REPO)" "$(LITE_SITE_DIR)"; \
	else \
		echo "Site already present at $(LITE_SITE_DIR) ($$(git -C $(LITE_SITE_DIR) rev-parse --short HEAD))"; \
	fi
	cp lite/site/composer.lock "$(LITE_SITE_DIR)/"
	echo "Applied lite/site overlay (composer.lock: the committed drupal-11 lock lacks the composer_site.json packages)"

lite-up: lite-init ## Lite: lite-init then upstream up (falls back to lite-ping where upstream ping.sh cannot run)
	$(MAKE) up || $(MAKE) lite-ping

lite-ping: ## Lite: readiness check without GNU timeout (macOS has none, so upstream ping.sh always fails there)
	URL="$$(grep '^URI_SCHEME=' .env | cut -d= -f2 | tr -d '\"')://$$(grep '^DOMAIN=' .env | cut -d= -f2 | tr -d '\"')"; \
	for i in 1 2 3 4 5 6 7 8 9 10; do \
		if curl -fs -m 10 "$$URL/" | grep -q Islandora; then echo "Site available at: $$URL"; exit 0; fi; \
		echo "Site is not live yet ($$i/10)"; sleep $$((5 * i)); \
	done; echo "Site failed to come online: $$URL" >&2; exit 1

lite-hydrate: ## Lite: re-point Solr/Blazegraph/IIIF/ffmpeg config at this stack (re-runnable)
	$(LITE_EXEC) 'lite-hydrate.sh'

lite-drush: ## Lite: run drush in the drupal container, e.g. make lite-drush CMD="status"
	$(LITE_EXEC) 'drush $(CMD)'

lite-shell: ## Lite: interactive shell in the drupal container
	docker compose exec drupal with-contenv bash -l

lite-login: ## Lite: one-time login link
	$(LITE_EXEC) 'drush uli'

lite-status: ## Lite: upstream status plus the active service list
	$(MAKE) status
	echo ""
	echo "Active services: $$(docker compose config --services | sort | tr '\n' ' ')"
	echo "Disabled (profile): $$(docker compose --profile disabled config --services | sort | comm -13 <(docker compose config --services | sort) - | tr '\n' ' ')"

lite-upstream-check: ## Lite: prove no upstream-tracked file is modified (prints nothing when clean)
	git fetch -q upstream
	git diff --stat upstream/main -- . $(foreach f,$(LITE_OWN_FILES),':(exclude)$(f)')
	git status --short -- . $(foreach f,$(LITE_OWN_FILES),':(exclude)$(f)') | grep -v '^??' || true

lite-upstream-pull: ## Lite: merge upstream/main, then list mirrored upstream files that changed
	git fetch upstream
	git merge --no-edit upstream/main
	echo ""
	echo "Upstream files mirrored by Lite that changed in this pull (port by hand, see INTENT.md §7):"
	git diff --stat ORIG_HEAD HEAD -- sample.env docker-compose.yml drupal/Dockerfile drupal/rootfs/etc || true
