#!/bin/bash
set -u

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
ROOT_DIR="$(dirname -- "$SCRIPT_DIR")"
cd "$ROOT_DIR" || exit 1

LOG_DIR="$ROOT_DIR/data/backups"
mkdir -p "$LOG_DIR"
LOG_FILE="$LOG_DIR/cron.log"

log() {
  printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG_FILE"
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
  for cand in \
    "$(command -v node 2>/dev/null)" \
    /opt/plesk/nodejs/*/bin/node \
    /opt/plesk/node/*/bin/node \
    /usr/local/bin/node \
    /usr/bin/node
  do
    [ -x "$cand" ] || continue
    v="$("$cand" --version 2>/dev/null | sed 's/[^0-9]*\([0-9]*\).*/\1/')"
    [ -n "$v" ] || continue
    if [ "$v" -ge 24 ] 2>/dev/null; then
      NODE="$cand"
      break
    fi
  done
fi

if [ -z "$NODE" ]; then
  for e in /proc/[0-9]*/exe; do
    [ -e "$e" ] || continue
    p="$(readlink -f "$e" 2>/dev/null || true)"
    case "$p" in
      */node)
        v="$("$p" --version 2>/dev/null | sed 's/[^0-9]*\([0-9]*\).*/\1/')"
        [ -n "$v" ] || continue
        if [ "$v" -ge 24 ] 2>/dev/null; then
          NODE="$p"
          break
        fi
        ;;
    esac
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
