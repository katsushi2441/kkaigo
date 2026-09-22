# kkaigo — Kurage 訪問介護・ケアマネナビ

住所を入れると、近くの**訪問介護・居宅介護支援（ケアマネ事業所）・定期巡回・随時対応型訪問介護看護・
夜間対応型訪問介護**を距離順に返す。市区町村ごとの公表件数と「消えた事業所」、法人ごとの増減を出す。

- 公開: https://kurage.exbridge.jp/kkaigo.php/
- 構成: **PHP 1ファイル + SQLite**（常駐サーバー・ポート不要）。kshuro/khoudei/kghome と同じ型

## データ

厚生労働省 [介護サービス情報公表システム オープンデータ](https://www.mhlw.go.jp/stf/kaigo-kouhyou_opendata.html)。
毎年6月末・12月末時点。**公開ページに残るのは直近2時点だけ**（2026-09-22時点: 20250106＝2024年12月末、
20260709＝2026年6月末）。落としたCSVは `/mnt/data/kkaigo/raw/<日付>/` に残す。

2026年6月末: 訪問介護 35,166／居宅介護支援 36,212／定期巡回 1,516／夜間対応型 204 ＝ 73,098事業所。
1年半で消えた: 訪問介護 3,242・居宅介護支援 3,146。訪問介護が無い市区町村 88、ケアマネ事業所が無い市区町村 7。

WAM NET（障害福祉）と列がほぼ同じ（緯度経度・法人番号・事業所番号・定員・URL）。営業時間の代わりに
「利用可能曜日」。**訪問系・ケアマネの定員は0が入る＝定員という考え方が無い**。画面に出さない。

## 作り直す

```bash
/usr/bin/python3 scripts/fetch_kaigo.py       # CSV → /mnt/data/kkaigo/raw
/usr/bin/python3 scripts/build_db.py          # → php/kkaigo_data/kkaigo.sqlite（48MB）
/usr/bin/python3 scripts/make_store_image.py  # OGP／商品画像
/usr/bin/python3 scripts/make_zip.py          # 配布ZIP（別製品の語が混ざっていないか検査）
/usr/bin/python3 scripts/deploy.py            # heteml へ（FTPは1接続）
/usr/bin/python3 scripts/shot.py <base> outputs/shots   # 320/360/390/430 のはみ出し実測
```

## 同じ型の製品

- [kshuro](../kshuro) 就労継続支援 / [khoudei](../khoudei) 放課後等デイ / [kghome](../kghome) 障害者グループホーム（WAM NET）
- [khokan](../khokan) 訪問看護（別データ）
