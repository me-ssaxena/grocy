#!/usr/bin/env bash
# Backs up Grocy (SQLite database and data files) to a Google Drive shared drive: restic (encrypted,
# deduplicated snapshots) stores one repository through rclone (Drive access with a service account).
#
#   grocy-backup schedule   run every day at BACKUP_TIME (the container's command)
#   grocy-backup run        back up now
#   grocy-backup verify     restore the latest database snapshot to a scratch copy and compare counts
#   grocy-backup <restic command and flags>   e.g. snapshots, ls latest, dump, restore
set -euo pipefail

HOST=grocy   # fixed, so snapshots group together even though the container's hostname changes on redeploy
DATA_DIR=/data
DB_FILE=$DATA_DIR/grocy.db
SA_FILE=/tmp/gdrive-service-account.json
SNAPSHOT_DIR=/tmp/grocy-db # the database copy is snapshotted from here, so it restores as /grocy-db/...

log() { printf '%s [backup] %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$*" >&2; }

missing_config() {
  local v missing=()
  for v in RESTIC_PASSWORD GDRIVE_SERVICE_ACCOUNT GDRIVE_SHARED_DRIVE_ID; do
    [[ -n ${!v:-} ]] || missing+=("$v")
  done
  printf '%s' "${missing[*]}"
}

setup() {
  local missing
  missing=$(missing_config)
  [[ -z $missing ]] || { log "not configured, set in Coolify: $missing"; return 1; }

  # The service account key, as raw JSON or base64 (easier to paste into a single-line env var)
  umask 077
  if [[ $GDRIVE_SERVICE_ACCOUNT == *'{'* ]]; then
    printf '%s' "$GDRIVE_SERVICE_ACCOUNT" > "$SA_FILE"
  else
    printf '%s' "$GDRIVE_SERVICE_ACCOUNT" | base64 -d > "$SA_FILE"
  fi

  # rclone remote "gdrive:", configured entirely from the environment (the empty file just
  # stops rclone logging that it has no config file)
  export RCLONE_CONFIG=/tmp/rclone.conf
  : > "$RCLONE_CONFIG"
  export RCLONE_CONFIG_GDRIVE_TYPE=drive
  export RCLONE_CONFIG_GDRIVE_SCOPE=drive
  export RCLONE_CONFIG_GDRIVE_SERVICE_ACCOUNT_FILE=$SA_FILE
  export RCLONE_CONFIG_GDRIVE_TEAM_DRIVE=$GDRIVE_SHARED_DRIVE_ID
  if [[ -n ${GDRIVE_FOLDER_ID:-} ]]; then
    export RCLONE_CONFIG_GDRIVE_ROOT_FOLDER_ID=$GDRIVE_FOLDER_ID
  fi

  export RESTIC_REPOSITORY="rclone:gdrive:${BACKUP_REPO_PATH:-grocy-restic}"
}

# Creates the repository on first use. restic exits with 10 when it doesn't exist yet.
ensure_repo() {
  local out rc=0
  out=$(restic cat config 2>&1 >/dev/null) || rc=$?
  case $rc in
    0) ;;
    10) log "no repository at $RESTIC_REPOSITORY yet, creating it"; restic init ;;
    *) log "can't open the repository: $out"; return 1 ;;
  esac
}

ping() { # healthchecks.io-style monitoring: <url>/start, <url>, <url>/fail
  [[ -n ${BACKUP_HEALTHCHECK_URL:-} ]] || return 0
  curl -fsS -m 10 --retry 3 -o /dev/null "${BACKUP_HEALTHCHECK_URL%/}$1" || log "healthcheck ping failed"
}

# A consistent copy of the live database (safe while Grocy writes to it), checked before upload
copy_db() { # <target file>
  [[ -f $DB_FILE ]] || { log "database not found at $DB_FILE"; return 1; }
  rm -f "$1"
  sqlite3 "$DB_FILE" ".backup '$1'"
  local check
  check=$(sqlite3 "$1" 'PRAGMA quick_check;')
  [[ $check == ok ]] || { log "database copy failed its integrity check: $check"; return 1; }
}

# ---- the parts of a backup; each runs in its own process (so `set -e` applies inside it) ----

part_db() {
  mkdir -p "$SNAPSHOT_DIR"
  trap 'rm -rf "$SNAPSHOT_DIR"' EXIT
  copy_db "$SNAPSHOT_DIR/grocy.db"
  restic backup --host "$HOST" --tag db "$SNAPSHOT_DIR/grocy.db"
}

part_files() {
  # config.php, storage/ (product and recipe pictures, files) and plugins/. The database is covered by
  # part_db; viewcache is regenerated, and backups/ holds update.sh's archives of old installs.
  restic backup --host "$HOST" --tag files \
    --exclude "$DATA_DIR/*.db" --exclude "$DATA_DIR/*.db-*" \
    --exclude "$DATA_DIR/viewcache" --exclude "$DATA_DIR/backups" \
    "$DATA_DIR"
}

steps() {
  ensure_repo
  restic unlock # clears a lock left by a run that was killed (e.g. by a redeploy)

  # Database first, so a long upload of pictures never delays it. A part that fails doesn't stop the other.
  local part failed=()
  for part in db files; do
    log "$part"
    "${BASH_SOURCE[0]}" "_part_$part" || { log "$part FAILED"; failed+=("$part"); }
  done

  log "applying retention"
  restic forget --host "$HOST" --prune \
    --keep-daily "${BACKUP_KEEP_DAILY:-7}" \
    --keep-weekly "${BACKUP_KEEP_WEEKLY:-4}" \
    --keep-monthly "${BACKUP_KEEP_MONTHLY:-6}"

  if [[ $(date +%u) == 7 ]]; then
    log "weekly integrity check"
    restic check
  fi

  ((${#failed[@]} == 0)) || { log "failed: ${failed[*]}"; return 1; }
}

run() {
  log "starting backup"
  ping /start
  # A separate process: bash ignores `set -e` in anything run as an `if` condition, so a failed
  # step would otherwise be reported as success
  if setup && "${BASH_SOURCE[0]}" _steps; then
    log "backup finished"
    ping ""
  else
    log "BACKUP FAILED"
    ping /fail
    return 1
  fi
}

table_counts() { # <database file>: "table: rows" for every table
  local t
  sqlite3 "$1" "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name" |
    while read -r t; do printf '%s: %s\n' "$t" "$(sqlite3 "$1" "SELECT count(*) FROM \"$t\"")"; done
}

verify() {
  setup
  local restored=/tmp/grocy-verify.db live=/tmp/grocy-live.db
  trap 'rm -f "$restored" "$live"' EXIT
  log "restoring the latest database snapshot"
  restic dump --host "$HOST" --tag db latest "$SNAPSHOT_DIR/grocy.db" > "$restored"
  local check
  check=$(sqlite3 "$restored" 'PRAGMA integrity_check;')
  log "integrity check of the restored copy: $check"
  copy_db "$live"
  echo "table: live rows / restored rows"
  awk -F': ' 'NR == FNR { live[$1] = $2; next }
    { printf "%s: %s / %s%s\n", $1, ($1 in live ? live[$1] : "-"), $2, (live[$1] == $2 ? "" : "   <- differs"); delete live[$1] }
    END { for (t in live) printf "%s: %s / -   <- differs\n", t, live[t] }' \
    <(table_counts "$live") <(table_counts "$restored")
  log "restore check finished (the live database has had changes since the snapshot, so some counts can differ)"
  [[ $check == ok ]]
}

seconds_until() { # HH:MM in the container's time zone
  local h m s now target
  read -r h m s < <(date '+%H %M %S')
  now=$((10#$h * 3600 + 10#$m * 60 + 10#$s))
  target=$((10#${1%%:*} * 3600 + 10#${1##*:} * 60))
  if ((target <= now)); then target=$((target + 86400)); fi
  echo $((target - now))
}

schedule() {
  local at=${BACKUP_TIME:-03:30} missing
  [[ $at =~ ^[0-9]{1,2}:[0-9]{2}$ ]] || { log "BACKUP_TIME must be HH:MM, got '$at'"; exit 1; }
  missing=$(missing_config)
  if [[ -n $missing ]]; then
    # Idle instead of exiting, so the restart policy doesn't loop; set the variables and redeploy
    log "backups are OFF until these are set in Coolify: $missing"
    ping /fail
    exec sleep infinity
  fi
  setup
  # Check access at startup, so a credentials problem shows in the logs straight away
  if ensure_repo; then log "repository ok: $RESTIC_REPOSITORY"; else log "will retry at the next backup"; fi
  log "backups run every day at $at (${TZ:-UTC})"
  while true; do
    sleep "$(seconds_until "$at")"
    run || true
    sleep 61 # don't start twice in the same minute
  done
}

case ${1:-schedule} in
  schedule) schedule ;;
  run) run ;;
  verify) verify ;;
  _steps) steps ;; # internal, called by run (after setup)
  _part_db) part_db ;;
  _part_files) part_files ;;
  *) setup; exec restic "$@" ;;
esac
