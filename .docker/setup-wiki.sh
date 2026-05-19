#!/bin/bash
set -e

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

# Run database updates (in case schema changed).
php maintenance/run.php update --quick

# Create test pages with IIIF file references.
echo "Creating test pages..."

# Single-page image (Deutsche Fotothek).
php maintenance/run.php edit "Meißen Rathaus" <<'WIKITEXT'
== Test: Single-page IIIF image ==

[[File:Df_dk_0007450.jpg|thumb|300px|Meißen Rathaus — single-page IIIF]]

This page tests a single-page IIIF image from Deutsche Fotothek.
WIKITEXT

# Multi-page document.
php maintenance/run.php edit "Kornhaus Mehrseitig" <<'WIKITEXT'
== Test: Multi-page IIIF document ==

Page 1:
[[File:Df_dk_multipage.jpg|thumb|300px|Kornhaus page 1]]

Page 2:
[[File:Df_dk_multipage.jpg|thumb|300px|page=2|Kornhaus page 2]]

Page 3:
[[File:Df_dk_multipage.jpg|thumb|300px|page=3|Kornhaus page 3]]
WIKITEXT

echo "Wiki setup complete."
echo "Access at http://localhost:8080/wiki/Main_Page"
echo "Admin login: Admin / testpassword123"
