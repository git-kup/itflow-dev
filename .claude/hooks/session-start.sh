#!/bin/bash
#
# ITFlow - SessionStart hook for Claude Code on the web.
#
# ITFlow has no composer or npm step - libraries are vendored in libs/ - so
# there is nothing to install in the usual sense. What a session actually needs
# is the other half of the stack: a database with the schema in it, a config.php
# (gitignored, so a fresh clone never has one), and the app served somewhere it
# can be requested. This script provides those three things.
#
# It installs through scripts/setup_cli.php rather than seeding tables by hand,
# so the documented install path is the one being exercised.
#
# Safe to run repeatedly: every step checks for the state it would create.

set -euo pipefail

# Web sessions only. A local checkout already has its own LAMP stack and this
# script would fight with it.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
DB_NAME="itflow"
DB_USER="itflow"
DB_PASS="itflow"
APP_HOST="127.0.0.1"
APP_PORT="8080"

# --- Database server ---------------------------------------------------------

if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
    echo "Installing MariaDB server..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq >/dev/null 2>&1 || true
    apt-get install -y -qq mariadb-server >/dev/null
fi

# No systemd in the container, so mysqld_safe is started directly.
if ! mysqladmin ping >/dev/null 2>&1; then
    echo "Starting MariaDB..."
    mkdir -p /var/run/mysqld
    chown mysql:mysql /var/run/mysqld
    setsid mysqld_safe --user=mysql >/tmp/mysqld.log 2>&1 < /dev/null &
    for _ in $(seq 1 60); do
        mysqladmin ping >/dev/null 2>&1 && break
        sleep 1
    done
fi

if ! mysqladmin ping >/dev/null 2>&1; then
    echo "MariaDB failed to start - see /tmp/mysqld.log" >&2
    exit 1
fi

mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
    CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
    GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"

# --- ITFlow install ----------------------------------------------------------

# config.php is gitignored, so its absence is what marks an uninstalled tree.
if [ ! -f "$PROJECT_DIR/config.php" ]; then
    echo "Installing ITFlow (scripts/setup_cli.php)..."
    (
        cd "$PROJECT_DIR/scripts"
        php setup_cli.php \
            --host=localhost \
            --username="$DB_USER" \
            --password="$DB_PASS" \
            --database="$DB_NAME" \
            --base-url="$APP_HOST:$APP_PORT" \
            --locale=en_US \
            --timezone=UTC \
            --currency=USD \
            --company-name="Dev Sandbox" \
            --country="United States" \
            --user-name="Dev Tester" \
            --user-email="dev@example.com" \
            --user-password="devpassword123" \
            --non-interactive >/dev/null
    )

    # The installer assumes https. The sandbox serves plain http, and
    # $config_https_only marks the session cookie Secure, which would make
    # every login silently fail to stick.
    sed -i "s/\$config_https_only = TRUE;/\$config_https_only = FALSE;/" "$PROJECT_DIR/config.php"
fi

# --- Web server --------------------------------------------------------------

if ! curl -s --noproxy '*' -o /dev/null "http://$APP_HOST:$APP_PORT/login.php"; then
    echo "Serving ITFlow on http://$APP_HOST:$APP_PORT ..."
    setsid php -S "$APP_HOST:$APP_PORT" -t "$PROJECT_DIR" \
        >/tmp/php-server.log 2>&1 < /dev/null &
    for _ in $(seq 1 15); do
        curl -s --noproxy '*' -o /dev/null "http://$APP_HOST:$APP_PORT/login.php" && break
        sleep 1
    done
fi

# --- Session environment -----------------------------------------------------

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    {
        echo "export ITFLOW_URL=\"http://$APP_HOST:$APP_PORT\""
        echo "export ITFLOW_DB=\"$DB_NAME\""
        echo "export ITFLOW_USER=\"dev@example.com\""
        echo "export ITFLOW_PASS=\"devpassword123\""
    } >> "$CLAUDE_ENV_FILE"
fi

cat <<EOF

ITFlow dev stack ready.

  App    http://$APP_HOST:$APP_PORT  (dev@example.com / devpassword123)
  DB     mysql $DB_NAME
  Logs   /tmp/php-server.log, /tmp/mysqld.log

  Lint   find . -path ./libs -prune -o -name '*.php' -print | xargs -n 20 php -l
  Schema mysql -e 'DROP DATABASE IF EXISTS itflow_lint; CREATE DATABASE itflow_lint;' \\
         && mysql itflow_lint < db.sql

There is no PHPUnit suite in this repository - CI runs the PHP lint above and a
db.sql import. Verify behaviour changes against the running app.
EOF
