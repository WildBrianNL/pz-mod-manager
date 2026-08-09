#!/bin/sh
# php -l for every PHP file, run inside the panel container.
#
# There is no PHP on this Mac, and a plugin that does not parse takes the whole
# panel down with a white screen rather than a message. Shipping unparsed PHP is
# not an option, so the check borrows the interpreter that will actually run it.
set -e
cd "$(dirname "$0")"
HOST=ubuntu@135.125.188.67
KEY="$HOME/.ssh/hoasted"

tar czf /tmp/pzmm-lint.tgz $(git ls-files '*.php') 2>/dev/null
scp -q -i "$KEY" /tmp/pzmm-lint.tgz "$HOST":/tmp/pzmm-lint.tgz
ssh -i "$KEY" "$HOST" "sudo sh -c '
  rm -rf /tmp/pzmm-lint && mkdir -p /tmp/pzmm-lint
  tar xzf /tmp/pzmm-lint.tgz -C /tmp/pzmm-lint
  docker cp /tmp/pzmm-lint pelican-panel-1:/tmp/pzmm-lint >/dev/null
  docker exec pelican-panel-1 sh -c \"find /tmp/pzmm-lint -name \\\"*.php\\\" -exec php -l {} \; | grep -v \\\"No syntax errors\\\" || true\"
  docker exec pelican-panel-1 rm -rf /tmp/pzmm-lint
'"
echo "lint ok"

# The unit tests need a PHP too, and the same interpreter is the right one.
tar czf /tmp/pzmm-test.tgz src tests 2>/dev/null
scp -q -i "$KEY" /tmp/pzmm-test.tgz "$HOST":/tmp/pzmm-test.tgz
ssh -i "$KEY" "$HOST" "sudo sh -c '
  rm -rf /tmp/pzmm-test && mkdir -p /tmp/pzmm-test
  tar xzf /tmp/pzmm-test.tgz -C /tmp/pzmm-test
  docker cp /tmp/pzmm-test pelican-panel-1:/tmp/pzmm-test >/dev/null
  docker exec pelican-panel-1 php /tmp/pzmm-test/tests/StateStoreTest.php
  docker exec pelican-panel-1 rm -rf /tmp/pzmm-test
'"
