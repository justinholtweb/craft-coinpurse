#!/usr/bin/env bash
# Coin Purse control-panel smoke test.
#
# A settings screen that 500s is invisible to the PHP suite — every service under it can be green
# while the template that renders them is broken. Run inside the plugin-testing container:
#
#     ddev exec bash /var/www/craft-coinpurse/tests/integration/cp-smoke.sh
#
# Local harness credentials only; this site is not reachable from anywhere else.
set -uo pipefail

BASE="http://localhost"
JAR=$(mktemp)
FAIL=0

csrf() {
    curl -sS -b "$JAR" -c "$JAR" "$BASE/actions/users/session-info" \
        -H 'Accept: application/json' | sed -n 's/.*"csrfTokenValue":"\([^"]*\)".*/\1/p'
}

TOKEN=$(csrf)

LOGIN=$(curl -sS -b "$JAR" -c "$JAR" -X POST "$BASE/actions/users/login" \
    -H 'Accept: application/json' -H "X-CSRF-Token: $TOKEN" \
    --data-urlencode "loginName=admin" \
    --data-urlencode "password=claudepassword")

# Craft 5 answers a successful CP login with the user model, not a `success` flag.
if ! echo "$LOGIN" | grep -q '"isCurrent":true'; then
    echo "  ✗ could not sign in: $LOGIN"
    exit 1
fi

echo "  ✓ signed in"

for path in "admin/coinpurse" "admin/coinpurse/diagnostics" "admin/coinpurse/settings" "admin/coinpurse/log"; do
    CODE=$(curl -sS -b "$JAR" -c "$JAR" -o /tmp/cp-page.html -w '%{http_code}' "$BASE/$path")

    if [ "$CODE" = "200" ]; then
        echo "  ✓ /$path renders"
    else
        echo "  ✗ /$path returned $CODE"
        grep -o '<title>[^<]*</title>' /tmp/cp-page.html | head -1
        grep -o 'message">[^<]\{0,200\}' /tmp/cp-page.html | head -1
        FAIL=1
    fi
done

rm -f "$JAR"
exit $FAIL
