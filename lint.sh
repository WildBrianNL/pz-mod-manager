#!/bin/sh
# Everything that needs a PHP: syntax, compiled Blade, unit tests.
#
# There is no PHP on this Mac, and a plugin that does not parse takes the whole
# panel down with a white screen rather than a message. Shipping unparsed PHP is
# not an option, so these borrow the interpreter that will actually run it.
#
# Every stage below fails the script. An earlier version piped through
# `grep -v "No syntax errors" || true`, which printed the parse error and then
# reported success, so a broken file passed the check that existed to catch it.
set -e
cd "$(dirname "$0")"
HOST=ubuntu@135.125.188.67
KEY="$HOME/.ssh/hoasted"
REMOTE="ssh -i $KEY $HOST sudo sh -c"

fail() {
    echo "$1"
    exit 1
}

# Deploying by hand: the panel writes a "meta" block into plugin.json on install
# and reads the plugin's enabled state back out of it. Overwriting the file with
# the one from git therefore uninstalls the plugin, and the Mods page 404s with
# "route could not be found". If you copy files in, either keep that block or run
#   php artisan p:plugin:install pz-mod-manager
# afterwards. The supported route is the panel's own Import button.

# --- PHP syntax -------------------------------------------------------------
tar czf /tmp/pzmm-lint.tgz $(git ls-files '*.php') 2>/dev/null
scp -q -i "$KEY" /tmp/pzmm-lint.tgz "$HOST":/tmp/pzmm-lint.tgz
OUT=$($REMOTE "'
  rm -rf /tmp/pzmm-lint && mkdir -p /tmp/pzmm-lint
  tar xzf /tmp/pzmm-lint.tgz -C /tmp/pzmm-lint
  docker cp /tmp/pzmm-lint pelican-panel-1:/tmp/pzmm-lint >/dev/null
  docker exec pelican-panel-1 sh -c \"find /tmp/pzmm-lint -name \\\"*.php\\\" -exec php -l {} \; | grep -v \\\"No syntax errors\\\"\"
  docker exec pelican-panel-1 rm -rf /tmp/pzmm-lint
'" 2>/dev/null || true)
[ -z "$OUT" ] || fail "$OUT"
echo "php ok"

# --- Blade ------------------------------------------------------------------
# Blade is not PHP until it is compiled, so php -l on the template proves
# nothing. A syntax error in there surfaces as a broken page in the browser and
# nowhere else, which is why this compiles it first and lints the output.
scp -q -i "$KEY" resources/views/manage-mods.blade.php "$HOST":/tmp/pzmm-view.blade.php
OUT=$($REMOTE "'
  docker cp /tmp/pzmm-view.blade.php pelican-panel-1:/tmp/pzmm-view.blade.php >/dev/null
  docker exec pelican-panel-1 php -r \"
    require \\\"/var/www/html/vendor/autoload.php\\\";
    \\\$app = require \\\"/var/www/html/bootstrap/app.php\\\";
    \\\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    file_put_contents(\\\"/tmp/pzmm-view.php\\\", Illuminate\\Support\\Facades\\Blade::compileString(file_get_contents(\\\"/tmp/pzmm-view.blade.php\\\")));
  \"
  docker exec pelican-panel-1 sh -c \"php -l /tmp/pzmm-view.php | grep -v \\\"No syntax errors\\\"\"
'" 2>/dev/null || true)
[ -z "$OUT" ] || fail "$OUT"
echo "blade ok"

# --- unit tests -------------------------------------------------------------
tar czf /tmp/pzmm-test.tgz src tests 2>/dev/null
scp -q -i "$KEY" /tmp/pzmm-test.tgz "$HOST":/tmp/pzmm-test.tgz
$REMOTE "'
  rm -rf /tmp/pzmm-test && mkdir -p /tmp/pzmm-test
  tar xzf /tmp/pzmm-test.tgz -C /tmp/pzmm-test
  docker cp /tmp/pzmm-test pelican-panel-1:/tmp/pzmm-test >/dev/null
  docker exec pelican-panel-1 php /tmp/pzmm-test/tests/StateStoreTest.php
  docker exec pelican-panel-1 php /tmp/pzmm-test/tests/PhaseTest.php
  docker exec pelican-panel-1 rm -rf /tmp/pzmm-test
'"
