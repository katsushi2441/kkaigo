#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""厚労省「介護サービス情報公表システム」のオープンデータ CSV を落とす。

  /usr/bin/python3 scripts/fetch_kaigo.py            # 対象種別 × 公開されている全時点

配布元: https://www.mhlw.go.jp/stf/kaigo-kouhyou_opendata.html
  ファイル名 jigyosho_<種別コード>_all_<出力日時>.csv。**種別コードは介護保険のサービス種別**
  （110 訪問介護／430 居宅介護支援＝ケアマネ事業所／710 夜間対応型訪問介護／760 定期巡回・随時対応型）。
  提供時期は毎年6月末・12月末時点。**2026-09-22 時点でページに残っているのは2時点だけ**
  （20250106＝2024年12月末、20260709＝2026年6月末）。古い時点は消えるので、落としたものは捨てない。
  利用条件: 厚労省HP利用規約（政府標準利用規約 2.0 相当）＝営利・非営利を問わず二次利用可、出典明記。
  CSV は UTF-8 BOM 付き。列は WAM NET とほぼ同じ（緯度経度・法人番号・事業所番号・定員・URL）。
  訪問系の「定員」は 0 が入っている＝定員という考え方が無い。0人とは書かない。

落とし先は /mnt/data/kkaigo/raw/<出力日>/<code>.csv（ルートディスクを圧迫させない）。取得済みは飛ばす。
"""
from __future__ import annotations

import csv
import io
import json
import os
import re
import sys
import urllib.request
from pathlib import Path

INDEX = "https://www.mhlw.go.jp/stf/kaigo-kouhyou_opendata.html"
RAW = Path(os.environ.get("KKAIGO_RAW_DIR", "/mnt/data/kkaigo/raw"))
ROOT = Path(__file__).resolve().parent.parent
CATALOG = ROOT / "data" / "kaigo_catalog.json"
UA = {"User-Agent": "kkaigo/1.0 (+https://kurage.exbridge.jp/kkaigo.php/)"}
CODES = ["110", "430", "710", "760"]


def listing() -> list[tuple[str, str, str]]:
    req = urllib.request.Request(INDEX, headers=UA)
    with urllib.request.urlopen(req, timeout=120) as r:
        h = r.read().decode("utf-8", "replace")
    out = []
    for u, code, d in re.findall(r'href="([^"]+/jigyosho_(\d+)_all_(\d{8})\d*\.csv)"', h):
        if code in CODES:
            out.append((code, d, u if u.startswith("http") else "https://www.mhlw.go.jp" + u))
    return sorted(set(out))


def peek(path: Path) -> tuple[str, int]:
    raw = path.read_bytes()
    text = raw.decode("utf-8-sig") if raw[:3] == b"\xef\xbb\xbf" else raw.decode("utf-8", "replace")
    rows = list(csv.DictReader(io.StringIO(text)))
    kinds = {r.get("サービスの種類", "") for r in rows if r.get("サービスの種類")}
    return "／".join(sorted(kinds)), len(rows)


def main() -> int:
    jobs = listing()
    print(f"対象 {len(jobs)} ファイル（種別 {CODES}）")
    catalog = json.loads(CATALOG.read_text(encoding="utf-8")) if CATALOG.exists() else {}
    for code, d, u in jobs:
        p = RAW / d / f"{code}.csv"
        p.parent.mkdir(parents=True, exist_ok=True)
        if not (p.exists() and p.stat().st_size > 1000):
            req = urllib.request.Request(u, headers=UA)
            with urllib.request.urlopen(req, timeout=600) as r:
                p.write_bytes(r.read())
        kind, n = peek(p)
        catalog.setdefault(code, {})["name"] = kind
        catalog[code].setdefault("counts", {})[d] = n
        print(f"  {d} 種別{code} {kind} {n:,}件")
    CATALOG.parent.mkdir(parents=True, exist_ok=True)
    CATALOG.write_text(json.dumps(catalog, ensure_ascii=False, indent=1), encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
