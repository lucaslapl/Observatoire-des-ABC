#!/bin/bash
# Arrête le serveur Observatoire des ABC (tue tout process qui écoute sur le port 4000).
cd "$(dirname "$0")/.." || exit 1

pid_file="data/server.pid"
if [ -f "$pid_file" ]; then
  pid=$(cat "$pid_file")
  rm -f "$pid_file"
  if [ -n "$pid" ]; then
    cmd.exe /c "taskkill /F /PID $pid" >/dev/null 2>&1
  fi
fi

# Filet de sécurité : tuer tout listener restant sur :4000
remaining=$(netstat.exe -ano 2>/dev/null | grep ":4000" | grep -i "LISTENING" | head -1 | awk '{print $NF}' | tr -d '\r')
if [ -n "$remaining" ]; then
  cmd.exe /c "taskkill /F /PID $remaining" >/dev/null 2>&1
  echo "serveur arrêté (pid $remaining)"
else
  echo "aucun serveur actif sur le port 4000"
fi