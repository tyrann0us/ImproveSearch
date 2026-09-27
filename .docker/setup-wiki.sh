#!/bin/bash
set -e

# Install MW dev dependencies (PHPUnit, wikimedia/testing-access-wrapper)
# so the Integration suite can run inside the container. The official
# mediawiki:1.44 image ships production deps only.
#
# Opt in with INSTALL_DEV_DEPS=1. The dev-deps install rewrites
# /var/www/html/vendor and invalidates Apache's warm opcache, which
# shows up as "Class GuzzleHttp\Psr7\Rfc3986 not found" on the next
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
    # for Codecov. Xdebug would also work, but pcov is much lighter,
    # with negligible overhead even when active. PHP_PCOV_DIRECTORY is read
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

# Wait for the database to accept connections.
echo "Waiting for database..."
for i in $(seq 1 60); do
    if php -r 'exit(@mysqli_connect("database", "wikiuser", "wikipassword", "testwiki") ? 0 : 1);'; then
        echo "Database is ready."
        break
    fi
    sleep 1
done

# Install MediaWiki if not already done.
if ! php -r 'exit(@mysqli_connect("database","wikiuser","wikipassword","testwiki")->query("SELECT 1 FROM page LIMIT 1") ? 0 : 1);' 2>/dev/null; then
    echo "Installing MediaWiki..."
    php maintenance/run.php install \
        --dbtype=mysql \
        --dbserver=database \
        --dbname=testwiki \
        --dbuser=wikiuser \
        --dbpass=wikipassword \
        --pass=testpassword123 \
        --server='http://localhost:8080' \
        --scriptpath='' \
        "ImproveSearch Test Wiki" \
        "Admin"
fi

# Overwrite LocalSettings with our test config.
cp /var/www/html/extensions/ImproveSearch/.docker/LocalSettings.php /var/www/html/LocalSettings.php
chown www-data:www-data /var/www/html/LocalSettings.php

# Create cache directory for the localisation cache.
mkdir -p /tmp/mw-cache
chown www-data:www-data /tmp/mw-cache

# Run database updates (in case schema changed).
php maintenance/run.php update --quick

# Rebuild localisation cache into files (prevents DB queries from load.php).
php maintenance/run.php rebuildLocalisationCache --quiet

# Create the test corpus. Each page covers one of the claims in the README.
echo "Creating test pages..."

# Substring title matching: "bridge" must suggest this page, although the
# title does not start with the term.
php maintenance/run.php edit "Stone Bridge" <<'WIKITEXT'
The '''Stone Bridge''' crosses the river north of the old town.
WIKITEXT

# Prefix match on the same term, so the ordering (start of title first) is
# observable.
php maintenance/run.php edit "Bridge Street" <<'WIKITEXT'
'''Bridge Street''' leads to the [[Stone Bridge]].
WIKITEXT

# Case and umlaut folding: "muller" must find this page.
php maintenance/run.php edit "Müller Mill" <<'WIKITEXT'
The '''Müller Mill''' has stood here since 1897.
WIKITEXT

# Clean index and snippets: neither the file name nor the template call may
# survive into searchindex.si_text. The address lives in a kept parameter, so
# "Wharf Road 7" must still find the page through the text index.
php maintenance/run.php edit "Warehouse" <<'WIKITEXT'
{{Address|street=Wharf Road|number=7|sortkey=7}}
[[File:Stone-Bridge-1909-Boehme-Publishers.jpg|thumb|The warehouse around 1909.]]
The '''Warehouse''' stands at the harbour basin.<ref>Town history, p. 203.</ref>
[[Category:Local history]]
WIKITEXT

# Redirect: must appear in neither the suggestion list nor the results.
php maintenance/run.php edit "Old Bridge" <<'WIKITEXT'
#REDIRECT [[Stone Bridge]]
WIKITEXT

# Index the corpus through the extension's content handler.
php maintenance/run.php rebuildtextindex

echo "Wiki setup complete."
echo "Access at http://localhost:8080/wiki/Main_Page"
echo "Admin login: Admin / testpassword123"
