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
# Safe to run repeatedly: every step checks for the state it would create, and
# the checks are on the state itself rather than on a marker that implies it.

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
APP_URL="http://$APP_HOST:$APP_PORT"

fail() {
    echo "session-start: $1" >&2
    exit 1
}

# True when the app answers with a real page, not merely when the port accepts a
# connection - a PHP fatal still completes a TCP handshake and returns a 500.
app_is_up() {
    [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 --noproxy '*' \
        "$APP_URL/login.php" 2>/dev/null)" = "200" ]
}

# The schema, not config.php, is what proves an install finished. setup_cli.php
# writes config.php before it imports db.sql and then refuses to run while
# config.php exists, so a half-finished install would otherwise look done to
# every later session and could never repair itself.
install_is_complete() {
    [ -f "$PROJECT_DIR/config.php" ] &&
        [ "$(mysql "$DB_NAME" -N -B -e \
            "SELECT COUNT(*) FROM settings WHERE company_id = 1;" 2>/dev/null)" = "1" ]
}

# --- PHP ---------------------------------------------------------------------

for ext in mysqli mbstring gd curl zip intl openssl; do
    php -m | grep -qx "$ext" || fail "PHP extension '$ext' is missing - ITFlow needs it."
done

# --- Database server ---------------------------------------------------------

if ! command -v mariadbd >/dev/null 2>&1 && ! command -v mysqld >/dev/null 2>&1; then
    echo "Installing MariaDB server..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq >/dev/null 2>&1 || true
    apt-get install -y -qq mariadb-server >/dev/null ||
        fail "apt-get could not install mariadb-server."
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

mysqladmin ping >/dev/null 2>&1 || fail "MariaDB did not start - see /tmp/mysqld.log"

mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4;
    CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
    GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"

# --- ITFlow install ----------------------------------------------------------

if ! install_is_complete; then

    # Clear a partial install out of the way. The installer aborts rather than
    # overwrites, so leaving either half behind means it will not run at all.
    if [ -f "$PROJECT_DIR/config.php" ]; then
        echo "Previous install is incomplete - reinstalling..."
        rm -f "$PROJECT_DIR/config.php"
        mysql -e "DROP DATABASE \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4;
            GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    fi

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
            --non-interactive >/tmp/itflow-setup.log 2>&1
    ) || fail "setup_cli.php failed - see /tmp/itflow-setup.log"

    # The installer assumes https. The sandbox serves plain http, and
    # $config_https_only marks the session cookie Secure, which would make
    # every login silently fail to stick.
    sed -i "s/\$config_https_only = TRUE;/\$config_https_only = FALSE;/" "$PROJECT_DIR/config.php"

    install_is_complete || fail "install finished but the schema is not there - see /tmp/itflow-setup.log"
fi

# --- Web server --------------------------------------------------------------

if ! app_is_up; then
    echo "Serving ITFlow on $APP_URL ..."
    setsid php -S "$APP_HOST:$APP_PORT" -t "$PROJECT_DIR" \
        >/tmp/php-server.log 2>&1 < /dev/null &
    for _ in $(seq 1 20); do
        app_is_up && break
        sleep 1
    done
fi

# --- Health check ------------------------------------------------------------
#
# Everything above is reported as done only once it is verified. A hook that
# announces a working stack it has not checked is worse than one that fails.

app_is_up || fail "the app is not answering on $APP_URL - see /tmp/php-server.log"
install_is_complete || fail "the database is not installed."

# --- Session environment -----------------------------------------------------

if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    {
        echo "export ITFLOW_URL=\"$APP_URL\""
        echo "export ITFLOW_DB=\"$DB_NAME\""
        echo "export ITFLOW_USER=\"dev@example.com\""
        echo "export ITFLOW_PASS=\"devpassword123\""
    } >> "$CLAUDE_ENV_FILE"
fi

cat <<EOF

ITFlow dev stack ready (verified: app answering, schema installed).

  App    $APP_URL  (dev@example.com / devpassword123)
  DB     mysql $DB_NAME
  Logs   /tmp/php-server.log, /tmp/mysqld.log, /tmp/itflow-setup.log

  Lint   find . -path ./libs -prune -o -name '*.php' -print | xargs -n 20 php -l
  Schema mysql -e 'DROP DATABASE IF EXISTS itflow_lint; CREATE DATABASE itflow_lint;' \\
         && mysql itflow_lint < db.sql

There is no PHPUnit suite in this repository - CI runs the PHP lint above and a
db.sql import. Verify behaviour changes against the running app.
EOF
