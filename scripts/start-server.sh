#!/bin/bash
# Démarre le serveur Observatoire des ABC en arrière-plan (sous Windows, port 4000).
export PATH="$HOME/bin:$PATH"
cd "$(dirname "$0")/.." || exit 1
mkdir -p data

# Tuer un éventuel serveur déjà actif sur le port 4000
bash scripts/stop-server.sh >/dev/null 2>&1

nohup npx tsx server/index.ts >/tmp/abc-server.log 2>&1 </dev/null &

# Attendre que le port 4000 soit en écoute et enregistrer le VRAI PID
for i in $(seq 1 20); do
  pid=$(netstat.exe -ano 2>/dev/null | grep ":4000" | grep -i "LISTENING" | head -1 | awk '{print $NF}' | tr -d '\r')
  if [ -n "$pid" ]; then
    echo "$pid" > data/server.pid
    echo "serveur démarré (pid $pid) → http://localhost:4000"
    exit 0
  fi
  sleep 1
done

echo "Échec : le serveur n'a pas démarré (voir /tmp/abc-server.log)"
exit 1