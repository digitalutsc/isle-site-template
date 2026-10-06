# syntax=docker/dockerfile:1
# Islandora Lite drupal image. Mirrors ../../drupal/Dockerfile (upstream) and adds what
# isle-dc's `make lite_dev` used to install at runtime.
ARG REPOSITORY
ARG TAG
FROM ${REPOSITORY}/drupal:${TAG}

ARG TARGETARCH

# Lite needs ImageMagick (+ PHP imagick for media_thumbnails_pdf) and ffmpeg
# (media_thumbnails_video). Detect the PHP version instead of hardcoding it.
RUN apk add --no-cache imagemagick ffmpeg \
      "php$(php -r 'echo PHP_MAJOR_VERSION . PHP_MINOR_VERSION;')-pecl-imagick"

COPY --link rootfs /

# - The many GitHub vcs repositories exhaust the unauthenticated API limit, after which
#   composer falls back to git@github.com; the image has no ssh, so force https.
# - git >= 2.35 refuses repositories with a different owner ("dubious ownership").
RUN --mount=type=cache,id=lite-drupal-composer-${TARGETARCH},sharing=locked,target=/root/.composer/cache \
    composer config -g use-github-api false && \
    composer config -g github-protocols https && \
    git config --system --add safe.directory '*' && \
    composer install -d /var/www/drupal --no-interaction --no-progress && \
    printf '\n// Islandora Lite: settings derived from the container environment.\ninclude "/etc/islandora/lite/settings.lite.php";\n' \
      >> /var/www/drupal/web/sites/default/settings.php && \
    chown -R nginx:nginx /var/www/drupal && \
    cleanup.sh
