#!/bin/bash
# H3クリップができたら PV を作って公開する（kpvgen build → deploy_pv）
cd /home/kojima/work/kpvgen
until [ -s outputs/h3_queue/h3-kkaigo-8s.mp4 ]; do sleep 30; done
echo "== H3 done $(date +%H:%M:%S)"
/usr/bin/python3 kpvgen.py build specs/kkaigo.json 2>&1 | tail -8
mp4=$(ls outputs/kkaigo-*s/kkaigo-*s.mp4 2>/dev/null | head -1); poster=$(dirname "$mp4")/poster.jpg
[ -s "$mp4" ] || { echo "!! PV が無い"; echo PV_FAIL; exit 1; }
cd /home/kojima/work/kkaigo/pv && /usr/bin/python3 deploy_pv.py "$mp4" "$poster" 2>&1 | tail -6
echo PV_DONE
