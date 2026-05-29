#!/bin/bash
set -e

# Install MW dev dependencies (PHPUnit, wikimedia/testing-access-wrapper)
# so the Integration suite can run inside the container. The official
# mediawiki:1.44 image ships production deps only.
#
# Opt-in via INSTALL_DEV_DEPS=1 — the dev-deps install rewrites
# /var/www/html/vendor and busts Apache's already-warm opcache, which
# manifests as "Class GuzzleHttp\Psr7\Rfc3986 not found" on the next
# request. Only the integration-tests workflow (and `npm run docker:up`)
# need this; e2e and ad-hoc shells skip it.
if [ "${INSTALL_DEV_DEPS:-0}" = "1" ] && [ ! -d /var/www/html/vendor/phpunit ]; then
    echo "Installing MW dev dependencies (one-time setup)..."
    if [ ! -x /usr/local/bin/composer ]; then
        apt-get update -qq
        apt-get install -y -qq unzip
        curl -sS https://getcomposer.org/installer | php -- \
            --install-dir=/usr/local/bin --filename=composer
    fi
    (cd /var/www/html && composer install --no-interaction --prefer-dist \
        --no-progress 2>&1 | tail -3)

    # Install pcov so the Integration suite can produce a Clover XML
    # for Codecov. Xdebug would also work, but pcov is far lighter —
    # negligible overhead even when active. PHP_PCOV_DIRECTORY is read
    # at runtime via the `-d` flag in the workflow, not baked in here.
    if ! php -m | grep -qi '^pcov$'; then
        apt-get install -y -qq --no-install-recommends ${PHPIZE_DEPS:-autoconf gcc g++ make pkg-config}
        printf "\n" | pecl install pcov 2>&1 | tail -3
        docker-php-ext-enable pcov
    fi

    # Apache's opcache pre-loaded the old vendor before our composer
    # install touched it; reload so the new autoloader (and the pcov
    # extension we just enabled) take effect.
    apache2ctl graceful || service apache2 reload || true
fi

# Wait for the mock IIIF server to be ready.
echo "Waiting for mock IIIF server..."
for i in $(seq 1 30); do
    if curl -sf http://iiif-mock:8111/health > /dev/null 2>&1; then
        echo "Mock IIIF server is ready."
        break
    fi
    sleep 1
done

# Install MediaWiki if not already done.
if [ ! -f /var/www/data/testwiki.sqlite ]; then
    echo "Installing MediaWiki..."
    mkdir -p /var/www/data
    php maintenance/run.php install \
        --dbtype=sqlite \
        --dbpath=/var/www/data \
        --dbname=testwiki \
        --pass=testpassword123 \
        --server='http://localhost:8080' \
        --scriptpath='' \
        "InstantIIIF Test Wiki" \
        "Admin"
fi

# Overwrite LocalSettings with our test config.
cp /var/www/html/extensions/InstantIIIF/.docker/LocalSettings.php /var/www/html/LocalSettings.php
chown www-data:www-data /var/www/html/LocalSettings.php

# Ensure www-data can access the SQLite database (exec runs as root).
chown -R www-data:www-data /var/www/data
chmod -R 755 /var/www/data

# Create cache directory for localisation cache (avoids SQLite contention).
mkdir -p /tmp/mw-cache
chown www-data:www-data /tmp/mw-cache

# Run database updates (in case schema changed).
php maintenance/run.php update --quick

# Rebuild localisation cache into files (prevents DB queries from load.php).
php maintenance/run.php rebuildLocalisationCache --quiet

# Create test pages with IIIF file references.
echo "Creating test pages..."

# Single-page image (Deutsche Fotothek). IIIF object IDs in the wild
# are extension-less ("Bsb11610364", "df_dk_0007450"); wikitext mirrors
# that so the spoofed `.jpg` Hooks appends never leaks into the rendered
# file-page URLs.
php maintenance/run.php edit "Meißen Rathaus" <<'WIKITEXT'
== Test: Single-page IIIF image ==

[[File:Df_dk_0007450|thumb|300px|Meißen Rathaus — single-page IIIF]]

This page tests a single-page IIIF image from Deutsche Fotothek.
WIKITEXT

# Multi-page document.
php maintenance/run.php edit "Kornhaus Mehrseitig" <<'WIKITEXT'
== Test: Multi-page IIIF document ==

Page 1:
[[File:Df_dk_multipage|thumb|300px|Kornhaus page 1]]

Page 2:
[[File:Df_dk_multipage|thumb|300px|page=2|Kornhaus page 2]]

Page 3:
[[File:Df_dk_multipage|thumb|300px|page=3|Kornhaus page 3]]
WIKITEXT

echo "Wiki setup complete."
echo "Access at http://localhost:8080/wiki/Main_Page"
echo "Admin login: Admin / testpassword123"
