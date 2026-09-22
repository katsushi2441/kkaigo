#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""配布ZIP（kappstore 同梱物）を作る。

  /usr/bin/python3 scripts/make_zip.py

**同梱の LICENSE と README は必ずこのプロジェクトのものを入れる。**
他製品からコピーしたまま末尾の注意書きが別製品になっていた前例がある（kgakudo）。
最後に中身を並べて、製品名が混ざっていないか目で確かめられるように出力する。
"""
from __future__ import annotations

import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TOP = "kurage-kaigo-navi"
OUT = ROOT / "outputs" / f"{TOP}.zip"

FILES = [
    (ROOT / "php" / "LICENSE", "LICENSE"),
    (ROOT / "php" / "README.md", "README.md"),
    (ROOT / "php" / "kkaigo.php", "kkaigo.php"),
    (ROOT / "php" / "kkaigo_data" / "kkaigo.sqlite", "kkaigo_data/kkaigo.sqlite"),
    (ROOT / "php" / "kkaigo_data" / ".htaccess", "kkaigo_data/.htaccess"),
    (ROOT / "outputs" / "kkaigo_ogp.png", "images/ogp/kkaigo.png"),
    (ROOT / "scripts" / "fetch_kaigo.py", "scripts/fetch_kaigo.py"),
    (ROOT / "scripts" / "build_db.py", "scripts/build_db.py"),
]

BAD = ("khoudei", "kshuro", "kghome", "放課後等デイ", "就労継続支援", "土砂災害", "グループホーム", "共同生活援助")


def main() -> int:
    for src, _ in FILES:
        if not src.exists():
            print(f"! {src} が無い", file=sys.stderr)
            return 1
    # 他製品の文面が混ざっていないか、テキストだけ検査する
    ng = 0
    for src, dst in FILES:
        if src.suffix in (".md", ".py", ".php") or src.name == "LICENSE":
            text = src.read_text(encoding="utf-8", errors="replace")
            hit = [w for w in BAD if w in text]
            if hit:
                print(f"! {dst} に別製品の語: {hit}", file=sys.stderr)
                ng += 1
    if ng:
        return 1
    OUT.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(OUT, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as z:
        for src, dst in FILES:
            z.write(src, f"{TOP}/{dst}")
    with zipfile.ZipFile(OUT) as z:
        for i in z.infolist():
            print(f"  {i.file_size:>11,}  {i.filename}")
    print(f"→ {OUT} ({OUT.stat().st_size/1048576:.1f}MB)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
