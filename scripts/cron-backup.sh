#!/bin/bash
set -u

# Le CRON de Plesk a un PATH minimal : on rétablit les répertoires standards.
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin${PATH:+:$PATH}"

self="$0"
DIR="${self%/*}"
if [ "$DIR" = "$self" ]; then DIR="."; fi
cd "$DIR/.." 2>/dev/null || exit 1
ROOT_DIR="$PWD"

# Journal : data/backups si créable, sinon racine de l'app.
LOG_DIR="$ROOT_DIR/data/backups"
LOG_FILE="$ROOT_DIR/cron-backup.log"
if mkdir -p "$LOG_DIR" >/dev/null 2>&1; then
  LOG_FILE="$LOG_DIR/cron.log"
fi

log() {
  # %T est un builtin bash (pas besoin de `date`).
  local msg
  msg="$(printf '%(%Y-%m-%d %H:%M:%S)T %s' -1 "$*")"
  printf '%s\n' "$msg" >> "$LOG_FILE"
  printf '%s\n' "$msg"
}

node_major() {
  local v="$1"
  v="${v#v}"
  printf '%s' "${v%%.*}"
}

log "=== backup ==="

if [ ! -f "dist/src/cli.js" ]; then
  if command -v npm >/dev/null 2>&1; then
    log "dist manquant -> npm run build"
    npm run build >> "$LOG_FILE" 2>&1
    if [ $? -ne 0 ] || [ ! -f "dist/src/cli.js" ]; then
      log "ERREUR: build impossible"
      exit 1
    fi
  else
    log "ERREUR: dist/src/cli.js introuvable et npm indisponible"
    log " -> relancer 'Installer NPM' dans Plesk (postinstall compile dist/)"
    exit 1
  fi
fi

NODE=""
if [ -n "${NODE_BIN:-}" ] && [ -x "$NODE_BIN" ]; then
  NODE="$NODE_BIN"
fi

if [ -z "$NODE" ]; then
  for p in "$(command -v node 2>/dev/null)" \
    "$ROOT_DIR/node" \
    /opt/plesk/nodejs/*/bin/node \
    /opt/plesk/node/*/bin/node \
    /usr/local/bin/node \
    /usr/bin/node \
    /usr/bin/nodejs
  do
    [ -x "$p" ] || continue
    m="$(node_major "$("$p" --version 2>/dev/null)")"
    [ -n "$m" ] || continue
    if [ "$m" -ge 24 ] 2>/dev/null; then NODE="$p"; break; fi
  done
fi

if [ -z "$NODE" ]; then
  for f in /proc/[0-9]*/cmdline; do
    grep -aq "dist/server/index.js" "$f" 2>/dev/null || continue
    p="$(readlink -f "${f%/cmdline}/exe" 2>/dev/null || true)"
    [ -n "$p" ] || continue
    m="$(node_major "$("$p" --version 2>/dev/null)")"
    if [ -n "$m" ] && [ "$m" -ge 24 ] 2>/dev/null; then NODE="$p"; break; fi
  done
fi

if [ -z "$NODE" ]; then
  log "ERREUR: aucun binaire Node >= 24 trouve"
  log " -> definissez NODE_BIN=/chemin/vers/node dans la tache planifiee"
  exit 1
fi

log "node: $NODE $("$NODE" --version)"
log "lancement du backup (delai max 180 s)"
if command -v timeout >/dev/null 2>&1; then
  timeout 180 "$NODE" dist/src/cli.js backup >> "$LOG_FILE" 2>&1
else
  "$NODE" dist/src/cli.js backup >> "$LOG_FILE" 2>&1
fi
rc=$?
if [ "$rc" -eq 124 ]; then
  log "ERREUR: delai de 180 s depasse (backup bloque ?). La base reste coherente (WAL)."
elif [ "$rc" -ne 0 ]; then
  log "ERREUR: backup en echec (code $rc) - voir les lignes au-dessus"
else
  log "backup termine (code 0)"
fi
exit "$rc"