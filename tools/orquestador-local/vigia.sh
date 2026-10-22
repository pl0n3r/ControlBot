#!/bin/zsh
# Reinicia el orquestador si deja de responder (revisa cada 60 s). Úsalo junto con `caffeinate -dimsu`.
cd "$(dirname "$0")"
PORT="${1:-8777}"
while true; do
  if ! curl -s -m 8 -o /dev/null "localhost:$PORT/data.json"; then
    pkill -f "orq.py $PORT"; sleep 2
    nohup python3 orq.py "$PORT" >>/tmp/orq.out 2>&1 &
    echo "$(date) reinicio" >> /tmp/vigia.log
  fi
  sleep 60
done
