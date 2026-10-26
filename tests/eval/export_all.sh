#!/bin/sh
# Exports the 10 projects (inside rc1_web). Usage: sh export_all.sh
B="${OMOP_EVAL_BASE:-http://localhost/redcap_v17.1.4/ExternalModules/?prefix=omop_cdm_export}"
rm -rf /tmp/eval/zips && mkdir -p /tmp/eval/zips
for kv in P1:23 P2:24 P3:25 P4:26 P5:30 P6:31 P7:33 P8:34 P9:35 P10:29; do
  k=${kv%%:*}; pid=${kv##*:}; s=$(echo $k | tr P p)
  mkdir -p /tmp/eval/zips/$k && cd /tmp/eval/zips/$k
  t=$(curl -s -o out.zip -w '%{time_total}' "$B&page=pages/export&pid=$pid&run=download")
  unzip -q out.zip && rm out.zip
  echo "$k $t $(ls *.csv | wc -l) $(cat *.csv | wc -l)"
done
