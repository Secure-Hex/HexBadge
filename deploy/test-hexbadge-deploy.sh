#!/usr/bin/env bash
# Local harness for deploy/hexbadge-deploy.sh. Never touches production: it runs the
# script against a scratch tree using the HEXBADGE_DEPLOY_* test-only overrides, with
# `docker` and `curl` replaced by stubs on PATH (php -l runs on the host php).
#
#   deploy/test-hexbadge-deploy.sh [SCRATCH_DIR]
#
# Needs: bash, git, tar, rsync, flock, php. The "live" tree is v1.3.0 and the release is
# v1.4.0, both taken with `git archive` (the working tree is not read).
set -euo pipefail
REPO=$(cd "$(dirname "$0")/.." && pwd)
SCRIPT=$REPO/deploy/hexbadge-deploy.sh
S=${1:-$(mktemp -d)}
OLD=v1.3.0 NEW=v1.4.0
# Must match ALLOWED_TOP in deploy/hexbadge-deploy.sh and the list in deploy.yml.
PATHS=(apps config database lib scripts src storage VERSION MIGRATION-PROD.sql CHANGELOG.md)

pass=0 fail=0
ok() { echo "PASS: $*"; pass=$((pass + 1)); }
ko() { echo "FAIL: $*"; fail=$((fail + 1)); }
expect() { if eval "$2"; then ok "$1"; else ko "$1 ($2)"; fi; }

rm -rf "$S/app" "$S/bin" "$S/work" "$S/backups" "$S/art"
mkdir -p "$S/bin" "$S/art"
export HEXBADGE_DEPLOY_APP_DIR=$S/app HEXBADGE_DEPLOY_BACKUP_ROOT=$S/backups \
  HEXBADGE_DEPLOY_WORK_ROOT=$S/work HEXBADGE_DEPLOY_LOCK=$S/deploy.lock \
  HEXBADGE_DEPLOY_LOG=$S/deploy.log HEXBADGE_DEPLOY_RUNTIME_OWNER=$(id -un) \
  HEXBADGE_DEPLOY_HEALTH_DELAY=0 FAKE_DIR=$S
export PATH=$S/bin:$PATH

# docker stub: dump/migrate are recorded; any other exec runs locally in the app dir.
cat >"$S/bin/docker" <<'EOF'
#!/usr/bin/env bash
[[ $1 == exec ]] || exit 99
shift; while [[ $1 == -* ]]; do [[ $1 == -i ]] && shift || shift 2; done
shift   # container name
case $1 in
  mariadb-dump) echo "-- fake dump" ;;
  mariadb) cat >>"$FAKE_DIR/migrations.sql"; echo run >>"$FAKE_DIR/migrations.count" ;;
  *) cd "$HEXBADGE_DEPLOY_APP_DIR" && exec "$@" ;;
esac
EOF
# curl stub: 200 with the live VERSION in the earner footer, 500 while FAKE_BAD_VERSION is live.
cat >"$S/bin/curl" <<'EOF'
#!/usr/bin/env bash
v=$(<"$HEXBADGE_DEPLOY_APP_DIR/VERSION")
if [[ $v == "${FAKE_BAD_VERSION:-}" ]]; then printf 'error\n500'; else printf '<p class="about-ver">Versión %s</p>\n200' "$v"; fi
EOF
chmod +x "$S/bin/docker" "$S/bin/curl"

# Live tree: OLD release plus server-only data that a deploy must never touch.
mkdir -p "$S/app"
git -C "$REPO" archive "$OLD" -- "${PATHS[@]}" | tar -x -C "$S/app"
printf 'DB_HOST=securehex-mariadb\nDB_NAME=hexbadge\nDB_USER=app\nDB_PASS="s3cr3t"\nHERMES_CLIENT_ID=x\n' >"$S/app/.env"
mkdir -p "$S/app/storage/certificates" "$S/app/apps/earner/public/uploads/profiles" \
  "$S/app/apps/admin/public/.well-known/acme-challenge"
echo pdf >"$S/app/storage/certificates/c.pdf"
echo img >"$S/app/apps/earner/public/uploads/profiles/p.png"
echo tok >"$S/app/apps/admin/public/.well-known/acme-challenge/t"
echo bak >"$S/app/.env.bak.1"
echo stale >"$S/app/stale-top-level-file"
snapshot() { (cd "$S/app" && find .env .env.bak.1 storage apps/*/public/uploads apps/*/public/.well-known -type f -exec sha256sum {} + | sort); }
DATA_BEFORE=$(snapshot)

# Artifacts: NEW as built by CI, and variants for the failure cases.
git -C "$REPO" archive --format=tar.gz -o "$S/art/new.tar.gz" "$NEW" -- "${PATHS[@]}"
variant() {  # name, then a command run inside the unpacked release
  local d=$S/art/$1; shift
  rm -rf "$d" && mkdir -p "$d" && tar -xzf "$S/art/new.tar.gz" -C "$d"
  (cd "$d" && eval "$*")
  # Entries without a "./" prefix, like git archive (a top-level "." would be refused).
  (cd "$d" && tar -czf "$d.tar.gz" -- *)
}
variant badphp 'echo "<?php syntax error(" > src/broken.php'
variant evil 'mkdir -p docker && echo x > docker/x'
variant hermes 'mkdir -p storage/oidc && echo "Require all denied" > storage/oidc/.htaccess && echo "-- 016" >> MIGRATION-PROD.sql'

deploy() { SSH_ORIGINAL_COMMAND=$1 bash "$SCRIPT" <"$2" >"$S/last.out" 2>&1; }
live() { cat "$S/app/VERSION"; }

echo "== invalid versions are rejected before anything happens"
for bad in '1.4.0' 'v1.4' 'v1.4.0 x' 'v1.4.0;id' ''; do
  rc=0; deploy "$bad" "$S/art/new.tar.gz" || rc=$?
  expect "reject '$bad' (exit 2)" '[[ $rc == 2 && $(live) == 1.3.0 ]]'
done
expect "no backup taken for rejected versions" '[[ ! -d $S/backups ]] || [[ -z $(ls -A "$S/backups") ]]'

echo "== artifact checks fail before any code change"
rc=0; deploy v9.9.9 "$S/art/new.tar.gz" || rc=$?
expect "VERSION mismatch refused" '[[ $rc == 1 && $(live) == 1.3.0 ]] && grep -q "does not match" "$S/last.out"'
rc=0; deploy v1.4.0 "$S/art/evil.tar.gz" || rc=$?
expect "unexpected top-level entry (docker/) refused" '[[ $rc == 1 && $(live) == 1.3.0 ]] && grep -q "unexpected top-level entry: docker" "$S/last.out"'

echo "== failure after sync restores the previous code"
rc=0; FAKE_BAD_VERSION=1.4.0 deploy v1.4.0 "$S/art/new.tar.gz" || rc=$?
expect "health failure -> exit 1, rolled back" '[[ $rc == 1 && $(live) == 1.3.0 ]] && grep -q "RESULT: rolled back" "$S/last.out"'
rc=0; deploy v1.4.0 "$S/art/badphp.tar.gz" || rc=$?
expect "php -l failure -> rolled back, broken file gone" '[[ $rc == 1 && $(live) == 1.3.0 && ! -e $S/app/src/broken.php ]] && grep -q "RESULT: rolled back" "$S/last.out"'
expect "stale file restored by rollback" '[[ -f $S/app/stale-top-level-file ]]'
expect "runtime data and .env untouched after rollbacks" '[[ "$(snapshot)" == "$DATA_BEFORE" ]]'

echo "== happy path"
rc=0; deploy v1.4.0 "$S/art/new.tar.gz" || rc=$?
expect "deploy ok" '[[ $rc == 0 && $(live) == 1.4.0 ]] && grep -q "RESULT: ok" "$S/last.out"'
expect "migration skipped (file unchanged)" '[[ ! -f $S/migrations.count ]] && grep -q "migration skipped" "$S/last.out"'
expect "runtime data, .env, .env.bak and .well-known preserved" '[[ "$(snapshot)" == "$DATA_BEFORE" ]]'
expect "stale top-level entry removed by --delete" '[[ ! -e $S/app/stale-top-level-file ]]'
expect "backup has code and db dump" 'b=$(ls -td "$S"/backups/hexbadge-deploy-v1.4.0-* | head -1); [[ -s $b/app.tar.gz && -s $b/db.sql.gz && $(<"$b/OUTCOME") == ok ]]'
expect "backup excludes .env, storage and uploads" '! tar -tzf "$b/app.tar.gz" | grep -qE "(^|/)(\.env|storage/|apps/[a-z]+/public/uploads)"'
expect "DB password never logged" '! grep -q s3cr3t "$S/deploy.log"'

echo "== release that changes MIGRATION-PROD.sql and adds storage/oidc"
rc=0; deploy v1.4.0 "$S/art/hermes.tar.gz" || rc=$?
expect "deploy ok" '[[ $rc == 0 ]] && grep -q "RESULT: ok" "$S/last.out"'
expect "migration ran once, before the sync" '[[ $(wc -l <"$S/migrations.count") == 1 ]] && grep -q -- "-- 016" "$S/migrations.sql" && [[ $(grep -n "migration (" "$S/last.out" | cut -d: -f1) -lt $(grep -n "sync release" "$S/last.out" | cut -d: -f1) ]]'
expect "storage/oidc seeded with its .htaccess" '[[ -d $S/app/storage/oidc && -f $S/app/storage/oidc/.htaccess ]]'
expect "existing runtime data still untouched" '[[ "$(snapshot | grep -v oidc)" == "$DATA_BEFORE" ]]'

echo "== $pass passed, $fail failed (scratch: $S)"
((fail == 0))
