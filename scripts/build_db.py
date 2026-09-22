#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""厚労省 介護サービス情報公表システムのオープンデータ CSV から、画面が読む SQLite を作る（訪問介護・ケアマネ）。

  /usr/bin/python3 scripts/build_db.py
  → php/kkaigo_data/kkaigo.sqlite

入力は scripts/fetch_kaigo.py が /mnt/data/kkaigo/raw に落とした CSV。
扱うのは 訪問介護（110）・居宅介護支援（430＝ケアマネ事業所）・夜間対応型訪問介護（710）・
定期巡回・随時対応型訪問介護看護（760）。訪問看護は別製品 khokan の領域なので入れない。

**時点が2つしか無い**（2024年12月末＝ファイル日付20250106、2026年6月末＝20260709）。
だから「消えた」は、この1区間（1年半）の話。画面では期間を必ず書く。

**訪問系に定員は無い。**CSV の「定員」列には 0 が入っている。0人ではなく「定員という考え方が無い」。
画面には定員を出さない。

法人は **法人番号ではなく正規化した法人名** で束ねる（番号の空欄・ゆれがある）。

テーブルは3つ（同じ型の他のナビ製品と同じ形）。
  offices … 最新時点の全事業所。緯度経度つき。
  counts  … 時点 × 種別 × 市区町村 の公表件数。
  gone    … 前の時点に載っていて最新には載っていない事業所番号（法人つき）。

**増減の言い方に気をつける。**公表件数は「公表された件数」であって事業所数ではない。
消えた理由は公表されていないので「廃止」とは書かない（休止・法人再編での番号付け替えも「消えた」に入る）。
"""
from __future__ import annotations

import csv
import datetime as dt
import io
import json
import os
import re
import sqlite3
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RAW = Path(os.environ.get("KKAIGO_RAW_DIR", "/mnt/data/kkaigo/raw"))
OUT_DIR = ROOT / "php" / "kkaigo_data"
OUT = OUT_DIR / "kkaigo.sqlite"
KINDS = {"110": "訪問介護", "430": "居宅介護支援", "760": "定期巡回・随時対応型訪問介護看護", "710": "夜間対応型訪問介護"}
SRC_URL = "https://www.mhlw.go.jp/stf/kaigo-kouhyou_opendata.html"
ATTRIBUTION = "出典: 介護サービス情報公表システム（厚生労働省）のオープンデータを加工して作成"

STRIP = re.compile(
    r"訪問介護事業所|訪問介護ステーション|訪問介護|居宅介護支援事業所|居宅介護支援センター|居宅介護支援"
    r"|ケアプランセンター|ケアマネジメントセンター|ケアマネセンター|ケアセンター|ヘルパーステーション|ヘルパー"
    r"|指定居宅介護支援|介護サービス|事業所|ステーション|センター|株式会社|合同会社|有限会社|合資会社"
    r"|社会福祉法人|医療法人社団|医療法人財団|医療法人|一般社団法人|一般財団法人|公益社団法人|公益財団法人"
    r"|特定非営利活動法人|ＮＰＯ法人|NPO法人|生活協同組合|農業協同組合|社会福祉協議会")
PUNCT = re.compile(r"[\s　・（）()「」『』\[\]【】〔〕\-－‐–—~〜～/／,，.．]")

PREFS = ("北海道 青森県 岩手県 宮城県 秋田県 山形県 福島県 茨城県 栃木県 群馬県 埼玉県 千葉県 東京都 "
         "神奈川県 新潟県 富山県 石川県 福井県 山梨県 長野県 岐阜県 静岡県 愛知県 三重県 滋賀県 京都府 "
         "大阪府 兵庫県 奈良県 和歌山県 鳥取県 島根県 岡山県 広島県 山口県 徳島県 香川県 愛媛県 高知県 "
         "福岡県 佐賀県 長崎県 熊本県 大分県 宮崎県 鹿児島県 沖縄県").split()


def read_csv(path: Path) -> list[dict]:
    raw = path.read_bytes()
    text = raw.decode("utf-8-sig") if raw[:3] == b"\xef\xbb\xbf" else raw.decode("utf-8", "replace")
    return list(csv.DictReader(io.StringIO(text)))


def name_core(name: str) -> str:
    return PUNCT.sub("", STRIP.sub("", name)).strip()


def corp_key(corp: str) -> str:
    return re.sub(r"\s+", "", unicodedata.normalize("NFKC", corp or ""))


def city_key(city: str) -> str:
    """政令市・東京23区をまたいで数えるためのキー。札幌市中央区 → 札幌市。"""
    m = re.match(r"^(.+?市)(.+区)$", city)
    return m.group(1) if m else city


def timepoints() -> list[str]:
    return sorted(d.name for d in RAW.iterdir() if d.is_dir() and re.fullmatch(r"\d{8}", d.name))


def tp_label(d: str) -> str:
    """ファイル日付 → 時点の呼び方。20250106→2024年12月末、20260709→2026年6月末（提供時期は毎年6月末・12月末）。"""
    y, m = int(d[:4]), int(d[4:6])
    if m <= 3:
        return f"{y - 1}年12月末"
    if m <= 9:
        return f"{y}年6月末"
    return f"{y}年12月末"


def schema(conn: sqlite3.Connection) -> None:
    conn.executescript("""
    DROP TABLE IF EXISTS offices;
    CREATE TABLE offices (
      id INTEGER PRIMARY KEY,
      kind TEXT NOT NULL,
      office_no TEXT, name TEXT NOT NULL, name_kana TEXT, name_core TEXT,
      corp TEXT, corp_no TEXT, corp_key TEXT,
      pref TEXT, city TEXT, city_key TEXT, addr TEXT, addr2 TEXT,
      tel TEXT, fax TEXT, url TEXT,
      lat REAL, lon REAL,
      capacity INTEGER,
      days TEXT, note TEXT, kyosei TEXT, remarks TEXT, area_code TEXT);
    CREATE INDEX offices_city ON offices(pref, city_key);
    CREATE INDEX offices_kind ON offices(kind);
    CREATE INDEX offices_core ON offices(name_core);
    CREATE INDEX offices_geo ON offices(lat, lon);
    CREATE INDEX offices_corp ON offices(corp_key);

    DROP TABLE IF EXISTS counts;
    CREATE TABLE counts (tp TEXT NOT NULL, kind TEXT NOT NULL, pref TEXT, city_key TEXT, n INTEGER NOT NULL);
    CREATE INDEX counts_city ON counts(pref, city_key, kind, tp);
    CREATE INDEX counts_tp ON counts(tp, kind);

    DROP TABLE IF EXISTS gone;
    CREATE TABLE gone (
      kind TEXT NOT NULL, office_no TEXT, name TEXT,
      pref TEXT, city TEXT, city_key TEXT,
      corp TEXT, corp_no TEXT, corp_key TEXT,
      capacity INTEGER, last_tp TEXT NOT NULL);
    CREATE INDEX gone_city ON gone(pref, city_key);
    CREATE INDEX gone_tp ON gone(last_tp);
    CREATE INDEX gone_corp ON gone(corp_key);

    DROP TABLE IF EXISTS meta;
    CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT);
    """)


def to_int(s: str):
    s = (s or "").strip()
    return int(s) if s.isdigit() else None


def main() -> int:
    tps = timepoints()
    if not tps:
        print(f"生データが無い。先に fetch_kaigo.py を実行する（{RAW}）", file=sys.stderr)
        return 2
    latest = tps[-1]
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    if OUT.exists():
        OUT.unlink()
    conn = sqlite3.connect(OUT)
    schema(conn)

    n_off = 0
    for code, kind in KINDS.items():
        p = RAW / latest / f"{code}.csv"
        if not p.exists():
            print(f"  ! {kind}: {p} が無い", file=sys.stderr)
            continue
        for r in read_csv(p):
            pref, city = r["都道府県名"].strip(), r["市区町村名"].strip()
            conn.execute("""INSERT INTO offices
                (kind,office_no,name,name_kana,name_core,corp,corp_no,corp_key,
                 pref,city,city_key,addr,addr2,tel,fax,url,lat,lon,capacity,days,note,kyosei,remarks,area_code)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""", (
                kind, r["事業所番号"].strip(), r["事業所名"].strip(), r.get("事業所名カナ", "").strip(),
                name_core(r["事業所名"]), r["法人の名称"].strip(), r["法人番号"].strip(), corp_key(r["法人の名称"]),
                pref, city, city_key(city), r["住所"].strip(), r.get("方書（ビル名等）", "").strip(),
                r["電話番号"].strip(), r.get("FAX番号", "").strip(), r.get("URL", "").strip(),
                float(r["緯度"]) if r["緯度"].strip() else None,
                float(r["経度"]) if r["経度"].strip() else None,
                None,   # 訪問系・ケアマネに定員は無い。CSVの定員列は0以外も入り合計4千万人分になる＝定員ではない。読まない
                r.get("利用可能曜日", "").strip(), r.get("利用可能曜日特記事項", "").strip(),
                r.get("高齢者の方と障害者の方が同時一体的に利用できるサービス", "").strip(),
                r.get("備考", "").strip(), r["都道府県コード又は市町村コード"].strip()))
            n_off += 1

    n_counts = 0
    last_seen: dict = {}
    for tp in tps:
        for code, kind in KINDS.items():
            p = RAW / tp / f"{code}.csv"
            if not p.exists():
                continue
            tally: dict = {}
            for r in read_csv(p):
                pref, city = r["都道府県名"].strip(), r["市区町村名"].strip()
                ck = city_key(city)
                tally[(pref, ck)] = tally.get((pref, ck), 0) + 1
                no = r["事業所番号"].strip()
                if no:
                    last_seen[(kind, no)] = (tp, r["事業所名"].strip(), pref, city, ck,
                                             r["法人の名称"].strip(), r["法人番号"].strip(),
                                             corp_key(r["法人の名称"]), None)
            for (pref, ck), n in tally.items():
                conn.execute("INSERT INTO counts (tp,kind,pref,city_key,n) VALUES (?,?,?,?,?)", (tp, kind, pref, ck, n))
                n_counts += 1

    latest_nos = {(k, no) for k, no in conn.execute("SELECT kind, office_no FROM offices WHERE office_no <> ''")}
    n_gone = 0
    for (kind, no), (tp, name, pref, city, ck, corp, corp_no, ckey, cap) in last_seen.items():
        if (kind, no) in latest_nos:
            continue
        conn.execute("INSERT INTO gone (kind,office_no,name,pref,city,city_key,corp,corp_no,corp_key,capacity,last_tp)"
                     " VALUES (?,?,?,?,?,?,?,?,?,?,?)", (kind, no, name, pref, city, ck, corp, corp_no, ckey, cap, tp))
        n_gone += 1

    meta = {
        "latest_tp": latest,
        "latest_label": tp_label(latest) + "時点",
        "timepoints": json.dumps(tps),
        "tp_labels": json.dumps({t: tp_label(t) for t in tps}, ensure_ascii=False),
        "kinds": json.dumps(list(KINDS.values()), ensure_ascii=False),
        "offices": str(n_off), "counts_rows": str(n_counts), "gone_rows": str(n_gone),
        "corps": str(conn.execute("SELECT count(DISTINCT corp_key) FROM offices").fetchone()[0]),
        "source_url": SRC_URL, "attribution": ATTRIBUTION,
        "built_at": dt.datetime.now().strftime("%Y-%m-%d %H:%M"),
    }
    conn.executemany("INSERT INTO meta (k,v) VALUES (?,?)", list(meta.items()))
    conn.commit()

    print(f"最新時点 {latest}（{tp_label(latest)}） / 事業所 {n_off:,}件 / 件数表 {n_counts:,}行 / 消えた番号 {n_gone:,}件")
    for kind, n, ncity, cap in conn.execute(
            "SELECT kind, count(*), count(DISTINCT pref||city_key), sum(capacity IS NOT NULL) FROM offices GROUP BY kind"):
        print(f"  {kind}: {n:,}件 / {ncity:,}市区町村 / 定員あり {cap:,}（0のはず）")
    miss = conn.execute("SELECT count(*) FROM offices WHERE lat IS NULL OR lon IS NULL").fetchone()[0]
    print(f"  緯度経度が無い: {miss}件")
    for t in tps:
        for kind, n in conn.execute("SELECT kind, sum(n) FROM counts WHERE tp=? GROUP BY kind", (t,)):
            print(f"  {tp_label(t)} {kind}: {n:,}")
    for kind, n in conn.execute("SELECT kind, count(*) FROM gone GROUP BY kind"):
        print(f"  消えた {kind}: {n:,}")
    conn.execute("VACUUM")
    conn.close()
    print(f"→ {OUT} ({OUT.stat().st_size/1048576:.1f}MB)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
