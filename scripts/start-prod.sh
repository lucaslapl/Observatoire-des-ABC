#!/bin/bash
# Démarre l'Observatoire des ABC en production : compilation TypeScript puis
# serveur Node (dist/). À utiliser avec un gestionnaire de processus (systemd,
# PM2, ou l'outil NodeJS de Plesk).
set -e
cd "$(dirname "$0")/.."
npm run build
exec node dist/server/index.js
