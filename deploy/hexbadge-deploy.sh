#!/usr/bin/env bash
# Forced command for the GitHub Actions deploy key (see docs/deploy.md).
# Installed as /usr/local/sbin/hexbadge-deploy (root, 700) and referenced from
# /root/.ssh/authorized_keys with restrict,command="/usr/local/sbin/hexbadge-deploy".
# Adapted from Fides' deployment/fides-deploy.sh (same contract, same safety rails).
#
# Input: the version (vX.Y.Z) in $SSH_ORIGINAL_COMMAND and a `git archive` tar.gz on stdin.
# Order: validate -> backup (code, database) -> migrate -> sync -> lint -> health check.
# The migration runs BEFORE the code switch, so new code never runs against an old schema.
# Any failure, signal or unexpected exit after the sync started restores the previous code
# from this run's backup. Migrations are never reverted.
set -euo pipefail
umask 077
# Keep going if the SSH client disconnects: no death by SIGHUP, and writes to a closed
# stdout fail with EPIPE (handled in log()) instead of killing the script with SIGPIPE.
trap '' HUP PIPE

# TEST-ONLY overrides (HEXBADGE_DEPLOY_*): they let the local harness
# (deploy/test-hexbadge-deploy.sh) run against a scratch tree. sshd never passes them to a
# forced command (the key uses `restrict` and AcceptEnv does not list them), so in
# production the defaults below always apply.
VOLUME=/var/lib/docker/volumes/securehex_apps/_data
APP_DIR=${HEXBADGE_DEPLOY_APP_DIR:-$VOLUME/hexbadge}
BACKUP_ROOT=${HEXBADGE_DEPLOY_BACKUP_ROOT:-/root/backups}
WORK_ROOT=${HEXBADGE_DEPLOY_WORK_ROOT:-/root/hexbadge-deploy}
LOCK_FILE=${HEXBADGE_DEPLOY_LOCK:-/run/hexbadge-deploy.lock}
LOG_FILE=${HEXBADGE_DEPLOY_LOG:-/var/log/hexbadge-deploy.log}
RUNTIME_OWNER=${HEXBADGE_DEPLOY_RUNTIME_OWNER:-www-data}   # PHP user (uid 33 on host and in the containers)
HEALTH_DELAY_SECONDS=${HEXBADGE_DEPLOY_HEALTH_DELAY:-3}

APP_CONTAINER=securehex-badge       # admin vhost; securehex-earner mounts the same tree
DB_CONTAINER=securehex-mariadb
CONTAINER_APP_DIR=/home1/matiasti/hexbadge
ADMIN_HEALTH_URL=https://badge.securehex.cl/login
EARNER_HEALTH_URL=https://earner.securehex.cl/   # its footer prints "Versión X.Y.Z"
HEALTH_ATTEMPTS=5
HEALTH_TIMEOUT_SECONDS=15
LOCK_WAIT_SECONDS=600
MAX_BYTES=$((50 * 1024 * 1024))             # compressed artifact
MAX_UNPACKED_BYTES=$((200 * 1024 * 1024))   # sum of entry sizes in the tar listing
MAX_ENTRIES=20000
KEEP_OK_BACKUPS=10      # backups of successful deploys
KEEP_FAILED_BACKUPS=5   # backups of failed or rolled-back runs, counted separately
# Must match the `git archive` path list in .github/workflows/deploy.yml.
ALLOWED_TOP=(apps config database lib scripts src storage VERSION MIGRATION-PROD.sql CHANGELOG.md)
# Paths in the app dir that belong to the server, never to a release (anchored to the top).
# rsync --delete removes every other entry that is not in the release.
PROTECTED=(--exclude=/.env --exclude='/.env*' --exclude=/storage/ --exclude=/.git/
  --exclude='/apps/*/public/uploads/' --exclude='/apps/*/public/.well-known/')
# Release dirs that hold runtime data: missing entries are added, existing ones never touched.
RUNTIME_DIRS=(storage apps/admin/public/uploads apps/earner/public/uploads)

VERSION=invalid
PHASE=prepare   # prepare -> live (app dir may already be modified) -> finished
EXPECT_VERSION= # version the earner footer must show for the health check to pass
BACKUP_DIR=
TMP=

# Every line goes to the log file; stdout (the SSH channel) is best effort.
log() {
  local line
  line="$(date -u +%FT%TZ) [$VERSION] $*"
  printf '%s\n' "$line" >>"$LOG_FILE" 2>/dev/null || true
  printf '%s\n' "$line" 2>/dev/null || true
}
# Runs a command with its output captured, then relays that output through log().
run() {
  local rc=0 l
  "$@" >"$TMP/cmd.out" 2>&1 || rc=$?
  while IFS= read -r l || [[ -n $l ]]; do log "  $l"; done <"$TMP/cmd.out"
  return "$rc"
}

# check URL [TEXT]: 200 and, if given, TEXT somewhere in the body.
check() {
  local out code
  out=$(curl -sS --max-time "$HEALTH_TIMEOUT_SECONDS" -w '\n%{http_code}' "$1" 2>/dev/null || true)
  code=${out##*$'\n'}
  [[ $code == 200 ]] || { log "  $1 -> '${code:-none}'"; return 1; }
  [[ -z ${2:-} || $out == *"$2"* ]] || { log "  $1 -> 200 but does not show '$2'"; return 1; }
}

health() {
  local i
  for ((i = 1; i <= HEALTH_ATTEMPTS; i++)); do
    if check "$ADMIN_HEALTH_URL" && check "$EARNER_HEALTH_URL" "Versión $EXPECT_VERSION<"; then
      log "health ok (admin 200, earner 200 showing $EXPECT_VERSION)"
      return 0
    fi
    log "health attempt $i/$HEALTH_ATTEMPTS failed"
    ((i < HEALTH_ATTEMPTS)) && sleep "$HEALTH_DELAY_SECONDS"
  done
  return 1
}

restore_previous() {
  set +e
  log "restoring code from $BACKUP_DIR"
  local r=$TMP/restore ok=1
  rm -rf "$r" && mkdir -p "$r" || ok=0
  tar -xzf "$BACKUP_DIR/app.tar.gz" -C "$r" || ok=0
  # --chmod as in the forward sync: the restore dir is 700 (umask 077) and rsync -a
  # would otherwise copy that mode onto the app dir root.
  ((ok)) && { run rsync -a --delete --chmod=D755,F644 "${PROTECTED[@]}" "$r/" "$APP_DIR/" || ok=0; }
  EXPECT_VERSION=$PREV_VERSION
  if ((ok)) && health; then log "previous code is live again"; else log "WARNING: restore incomplete or health check still failing; check by hand"; fi
  ((ok))
}

# Keeps the newest $2 backup dirs among those whose OUTCOME matches ($1 = ok | failed).
prune_backups() {
  local want=$1 keep=$2 d outcome
  find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d -name 'hexbadge-deploy-*' -printf '%T@ %p\n' \
    | sort -rn | cut -d' ' -f2- | while IFS= read -r d; do
        # No OUTCOME file (killed run) counts as failed.
        if [[ -f $d/OUTCOME && $(<"$d/OUTCOME") == ok ]]; then outcome=ok; else outcome=failed; fi
        [[ $outcome == "$want" ]] || continue
        if ((keep > 0)); then keep=$((keep - 1)); else rm -rf -- "$d"; fi
      done
}

# Single exit path for errors, signals and set -e aborts. Never recurses.
on_exit() {
  local rc=$? result
  trap - EXIT INT TERM
  set +e
  if ((rc == 0)); then
    result=ok
  elif [[ $PHASE == live ]]; then
    log "ERROR: aborted after the sync started (exit $rc). Rolling back."
    if restore_previous; then result="rolled back (exit $rc)"; else result="FAILED, rollback incomplete (exit $rc)"; fi
    log "migrations were NOT reverted (forward-only)"
  else
    result="failed before any code change (exit $rc)"
  fi
  if [[ -n $BACKUP_DIR && -d $BACKUP_DIR ]]; then
    if ((rc == 0)); then echo ok >"$BACKUP_DIR/OUTCOME"; else echo failed >"$BACKUP_DIR/OUTCOME"; fi
    prune_backups ok "$KEEP_OK_BACKUPS"
    prune_backups failed "$KEEP_FAILED_BACKUPS"
  fi
  [[ -n $TMP ]] && rm -rf "$TMP"
  log "RESULT: $result"
  ((rc == 0)) || rc=1
  exit "$rc"
}

die() { log "ERROR: $*"; exit 1; }

# Adds what the release brings under a runtime dir (e.g. a new storage/oidc/ and its
# .htaccess) without overwriting or deleting anything already there. New dirs belong to
# the PHP user so the app can write into them; files stay root-owned and read-only.
seed_runtime() {
  local rel=$1 p
  [[ -d $STAGE/$rel ]] || return 0
  while IFS= read -r -d '' p; do
    if [[ -d $STAGE/$rel/$p ]]; then
      [[ -d $APP_DIR/$rel/$p ]] || install -d -m 755 -o "$RUNTIME_OWNER" -g "$RUNTIME_OWNER" "$APP_DIR/$rel/$p"
    else
      [[ -e $APP_DIR/$rel/$p ]] || install -m 644 "$STAGE/$rel/$p" "$APP_DIR/$rel/$p"
    fi
  done < <(cd "$STAGE/$rel" && find . -printf '%P\0')
}

if [[ ! ${SSH_ORIGINAL_COMMAND:-} =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  log "ERROR: expected a single argument vX.Y.Z"
  log "RESULT: rejected (bad version argument)"
  exit 2
fi
VERSION=$SSH_ORIGINAL_COMMAND
EXPECT_VERSION=${VERSION#v}

trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

exec 9>"$LOCK_FILE"
flock -w "$LOCK_WAIT_SECONDS" 9 || die "another deploy holds $LOCK_FILE"

install -d -m 700 "$WORK_ROOT" "$BACKUP_ROOT"
TMP=$(mktemp -d "$WORK_ROOT/run.XXXXXX")

log "receiving artifact"
head -c $((MAX_BYTES + 1)) >"$TMP/release.tar.gz"
(($(stat -c %s "$TMP/release.tar.gz") <= MAX_BYTES)) || die "artifact larger than $MAX_BYTES bytes"
[[ -s $TMP/release.tar.gz ]] || die "empty artifact"

# Validate every entry before extracting anything.
tar -tvzf "$TMP/release.tar.gz" >"$TMP/list-verbose" 2>/dev/null || die "artifact is not a valid tar.gz"
tar -tzf "$TMP/release.tar.gz" >"$TMP/list-names"
total=0 entries=0
while IFS= read -r line; do
  # First char of the `tar -tv` mode string is the entry type: '-' regular file,
  # 'd' directory; 'l' symlink, 'h' hard link, 'c'/'b' device, 'p' fifo are refused.
  [[ ${line:0:1} == - || ${line:0:1} == d ]] || die "refusing non-regular entry: ${line:0:120}"
  read -r _ _ size _ <<<"$line"
  [[ $size =~ ^[0-9]+$ ]] || die "cannot read entry size: ${line:0:120}"
  total=$((total + size)) entries=$((entries + 1))
  ((total <= MAX_UNPACKED_BYTES)) || die "artifact unpacks to more than $MAX_UNPACKED_BYTES bytes"
  ((entries <= MAX_ENTRIES)) || die "artifact has more than $MAX_ENTRIES entries"
done <"$TMP/list-verbose"
while IFS= read -r name; do
  [[ -n $name && $name != /* ]] || die "refusing absolute or empty path"
  [[ ! $name =~ (^|/)\.\.(/|$) ]] || die "refusing path with ..: $name"
  top=${name%%/*}
  allowed=0
  for a in "${ALLOWED_TOP[@]}"; do [[ $top == "$a" ]] && allowed=1 && break; done
  ((allowed)) || die "refusing unexpected top-level entry: $top"
done <"$TMP/list-names"

STAGE=$TMP/stage
mkdir -m 755 "$STAGE"
run tar -xzf "$TMP/release.tar.gz" -C "$STAGE" --no-same-owner --no-same-permissions || die "extract failed"
# Belt and braces: nothing but regular files and directories may have landed.
[[ -z $(find "$STAGE" ! -type f ! -type d -print -quit) ]] || die "extracted tree contains special files"
for f in apps/admin/public/index.php apps/earner/public/index.php src/bootstrap.php VERSION MIGRATION-PROD.sql; do
  [[ -f $STAGE/$f ]] || die "artifact lacks $f"
done
[[ $(<"$STAGE/VERSION") == "$EXPECT_VERSION" ]] || die "artifact VERSION '$(<"$STAGE/VERSION")' does not match $VERSION"
[[ -f $APP_DIR/.env && -f $APP_DIR/VERSION ]] || die "app dir $APP_DIR lacks .env or VERSION, refusing to deploy over it"
PREV_VERSION=$(<"$APP_DIR/VERSION")

# Backup: code and database, before anything changes.
BACKUP_DIR=$BACKUP_ROOT/hexbadge-deploy-$VERSION-$(date -u +%Y%m%dT%H%M%SZ)
install -d -m 700 "$BACKUP_DIR"
log "backup -> $BACKUP_DIR (live version $PREV_VERSION)"
mapfile -t app_entries < <(cd "$APP_DIR" && find . -mindepth 1 -maxdepth 1 \
  ! -name '.env*' ! -name storage ! -name .git -printf '%P\n')
run tar -czf "$BACKUP_DIR/app.tar.gz" -C "$APP_DIR" \
  --exclude='apps/*/public/uploads' --exclude='apps/*/public/.well-known' -- "${app_entries[@]}" \
  || die "code backup failed"

# .env parser: only DB_HOST, DB_NAME, DB_USER and DB_PASS; KEY=value per line, a trailing
# CR is dropped, one pair of matching surrounding quotes is removed, nothing is expanded.
db_host='' db_name='' db_user='' db_pass=''
while IFS= read -r line || [[ -n $line ]]; do
  line=${line%$'\r'}
  [[ $line =~ ^DB_(HOST|NAME|USER|PASS)=(.*)$ ]] || continue
  value=${BASH_REMATCH[2]}
  if [[ $value =~ ^\"(.*)\"$ || $value =~ ^\'(.*)\'$ ]]; then value=${BASH_REMATCH[1]}; fi
  case ${line%%=*} in
    DB_HOST) db_host=$value ;;
    DB_NAME) db_name=$value ;;
    DB_USER) db_user=$value ;;
    DB_PASS) db_pass=$value ;;
  esac
done <"$APP_DIR/.env"
[[ -n $db_name && -n $db_user ]] || die "DB_NAME / DB_USER missing in .env"
[[ $db_host == "$DB_CONTAINER" ]] || die "DB_HOST is '$db_host', expected $DB_CONTAINER"
# MYSQL_PWD travels through the environment, never on a command line.
dump() {
  MYSQL_PWD=$db_pass docker exec -i -e MYSQL_PWD "$DB_CONTAINER" \
    mariadb-dump --single-transaction --routines --triggers -u "$db_user" "$db_name" \
    | gzip >"$BACKUP_DIR/db.sql.gz"
}
run dump || die "database dump failed"
log "database dump ok ($(stat -c %s "$BACKUP_DIR/db.sql.gz") bytes)"

# Migration before the code switch. MIGRATION-PROD.sql is cumulative and idempotent, but
# it also carries one-off data backfills (e.g. 008 assigns company-less users to a
# company); so it only runs when the release changes it.
# ponytail: whole-file compare; per-migration tracking if the file keeps growing.
if cmp -s "$STAGE/MIGRATION-PROD.sql" "$APP_DIR/MIGRATION-PROD.sql"; then
  log "migration skipped (MIGRATION-PROD.sql unchanged)"
else
  log "migration (MIGRATION-PROD.sql changed)"
  migrate() {
    MYSQL_PWD=$db_pass docker exec -i -e MYSQL_PWD "$DB_CONTAINER" \
      mariadb -u "$db_user" "$db_name" <"$STAGE/MIGRATION-PROD.sql"
  }
  run migrate || die "migration failed (code untouched; schema may be partially migrated)"
fi
unset db_pass

PHASE=live
log "sync release -> $APP_DIR"
run rsync -a --delete --chmod=D755,F644 "${PROTECTED[@]}" "$STAGE/" "$APP_DIR/" || die "sync failed"
for d in "${RUNTIME_DIRS[@]}"; do run seed_runtime "$d" || die "cannot seed $d"; done

log "php -l (outside lib/)"
lint() {
  (cd "$STAGE" && find . -path ./lib -prune -o -type f -name '*.php' -print0) \
    | docker exec -i -w "$CONTAINER_APP_DIR" "$APP_CONTAINER" xargs -0 -n1 php -l \
    | { grep -v '^No syntax errors detected' || true; }
}
run lint || die "php lint failed"

health || die "health check failed"

PHASE=finished
log "deployed $VERSION (was $PREV_VERSION)"
