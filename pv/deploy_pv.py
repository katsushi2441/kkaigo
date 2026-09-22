#!/usr/bin/env python3
"""Kurage 訪問介護・ケアマネナビ PV を公開し、kappstore 商品ページに設置する（kpvgen ビルド後に実行。再実行可）。
  /usr/bin/python3 deploy_pv.py <pv.mp4> <poster.jpg>
1) https://kurage.exbridge.jp/pv/ に mp4 と poster を置く（他の PV と同じ場所）
2) kpv 台帳（kpv_data/videos.json）に id=kkaigo を登録（題名・タグは毎回上書きする）
3) kappstore 台帳（kapp_data/apps.json）の該当商品に video_url / video_poster を入れる
4) 商品ページに <video> が出ることを確認

**FTPは1接続にまとめる**（heteml はうちのIPを遮断することがある）。
"""
import datetime
import ftplib
import io
import json
import os
import subprocess
import sys
import time
import urllib.request

PV, POSTER = sys.argv[1], sys.argv[2]
BASE = "https://kurage.exbridge.jp/pv/"
POS = BASE + "kkaigo-pv-poster.jpg"
PRODUCT_ID = "57aebd041b7bab37"
PRODUCT_URL = "https://kappstore.exbridge.jp/app.php?id=" + PRODUCT_ID
HERE = os.path.dirname(os.path.abspath(__file__))
SECS = int(round(float(subprocess.check_output(
    ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", PV]).decode().strip())))
VID = BASE + "kkaigo-pv-%ds.mp4" % SECS

TITLE = ("訪問介護・ケアマネ事業所を住所から探す — Kurage 訪問介護・ケアマネナビ。"
         "1年半で訪問介護3,242か所・ケアマネ3,146か所が公表データから消えた。訪問介護が無い市区町村は88"
         "（オンプレミス55,000円・PHP1ファイル）")
TAGS = ["kkaigo", "houmonkaigo", "caremane", "kyotakukaigoshien", "kaigo", "opendata", "kappstore"]


def env():
    for l in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if "=" in l and not l.startswith("#"):
            k, v = l.rstrip("\n").split("=", 1)
            os.environ.setdefault(k, v.strip().strip('"').strip("'"))


def main():
    env()
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=600)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    f.cwd("/web/kurage_exbridge_jp/pv")
    f.storbinary("STOR kkaigo-pv-%ds.mp4" % SECS, open(PV, "rb"), blocksize=1 << 18)
    f.storbinary("STOR kkaigo-pv-poster.jpg", open(POSTER, "rb"), blocksize=1 << 18)
    print("  pv/ に配置（%d秒）" % SECS)

    f.cwd("/web/kurage_exbridge_jp/kpv_data")
    b = io.BytesIO()
    f.retrbinary("RETR videos.json", b.write)
    k = json.loads(b.getvalue())
    vs = k["videos"] if isinstance(k, dict) else k
    want = {"title": TITLE, "tags": TAGS, "seconds": SECS, "video": VID, "poster": POS, "page": PRODUCT_URL}
    hit = [v for v in vs if v.get("id") == "kkaigo"]
    if not hit:
        rec = {"id": "kkaigo", "hidden": 0, "created_at": datetime.datetime.now().isoformat()}
        rec.update(want)
        vs.append(rec)
        f.storbinary("STOR videos.json", io.BytesIO(json.dumps(k, ensure_ascii=False, indent=1).encode("utf-8")))
        print("  kpv 台帳: kkaigo 追加")
    else:
        v = hit[0]
        diff = [x for x, y in want.items() if v.get(x) != y]
        if diff:
            v.update(want)
            f.storbinary("STOR videos.json", io.BytesIO(json.dumps(k, ensure_ascii=False, indent=1).encode("utf-8")))
            print("  kpv 台帳: 更新 → " + "・".join(diff))
        else:
            print("  kpv 台帳: 登録済み")

    f.cwd("/web/kappstore_exbridge_jp/kapp_data")
    b = io.BytesIO()
    f.retrbinary("RETR apps.json", b.write)
    raw = b.getvalue()
    open(os.path.join(HERE, "apps_backup_%s.json" % datetime.datetime.now().strftime("%Y%m%d_%H%M%S")), "wb").write(raw)
    d = json.loads(raw)
    n_before = len(d["apps"])
    hit = [a for a in d["apps"] if a.get("id") == PRODUCT_ID]
    if not hit:
        raise SystemExit("kappstore 台帳に商品が見つかりません: " + PRODUCT_ID)
    a = hit[0]
    if a.get("video_url") != VID or a.get("video_poster") != POS:
        a["video_url"], a["video_poster"], a["updated_at"] = VID, POS, int(time.time())
        assert len(d["apps"]) == n_before
        f.storbinary("STOR apps.json", io.BytesIO(json.dumps(d, ensure_ascii=False).encode("utf-8")))
        print("  kappstore 台帳: video_url 設定（%d件のまま）" % n_before)
    else:
        print("  kappstore 台帳: 設定済み")
    f.quit()

    for u in (VID, POS):
        r = urllib.request.urlopen(urllib.request.Request(u, method="HEAD"), timeout=120)
        print("  %s %s %sB %s" % (r.status, r.headers.get("Content-Type"), r.headers.get("Content-Length"), u))
    html = urllib.request.urlopen(PRODUCT_URL, timeout=90).read().decode("utf-8", "replace")
    print("  商品ページ <video>:", "<video" in html and ("kkaigo-pv-%ds.mp4" % SECS) in html)


if __name__ == "__main__":
    main()
