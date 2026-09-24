<?php
/**
 * Kurage 訪問介護・ケアマネナビ（kkaigo）— PHP 1枚 + SQLite で動く。
 *
 * 住所を入れると、近くの訪問介護・居宅介護支援（ケアマネ事業所）・定期巡回・随時対応型訪問介護看護・
 * 夜間対応型訪問介護を距離順に返す。利用できる曜日・電話・サイト・運営法人つき。
 * 市区町村ごとに公表件数の推移と「前は載っていたのに、いまは載っていない事業所」、
 * 法人ごとに何か所持っていて何か所が消えたかを出す。
 *
 * 出どころは厚生労働省「介護サービス情報公表システム」のオープンデータ（毎年6月末・12月末時点）。
 * 営利・非営利を問わず二次利用できる（厚労省HP利用規約）。
 *
 * **この道具が言えること／言えないこと**
 *   言える … 公表データに載っている事業所と、その所在地・連絡先・運営法人・利用できる曜日
 *   言える … ある時点の公表件数と、番号が公表データから消えたこと
 *   言えない … 空き状況・受け入れの可否（公表されていない）
 *   言えない … **定員**。訪問系とケアマネに定員という考え方が無い（CSVの0は0人ではない）
 *   言えない … 「廃止した」かどうか（消えた理由は公表データに無い。休止・再編も「消えた」に入る）
 *   言えない … 事業所の質・ヘルパーの人数（公表データに無い）
 *   時点は2つだけ（2024年12月末・2026年6月末）。厚労省の公開が2時点ぶんのため。
 *
 * 法人は法人番号ではなく正規化した法人名で束ねる（番号の空欄・ゆれがあるため）。
 *
 * 置き方:
 *   kkaigo.php                   … このファイル
 *   kkaigo_data/kkaigo.sqlite    … データ（scripts/build_db.py が作る）
 *   kkaigo_data/.htaccess        … データ直読みの禁止
 *
 * heteml に置くときは、その階層の .htaccess に `AddHandler php-script .php` が要る（既定はPHP5.6）。
 */

// ── 設定 ───────────────────────────────────────────────
$SITE = 'Kurage 介護事業所ナビ';
$DATA_DIR = __DIR__ . '/kkaigo_data';
$DB_PATH = $DATA_DIR . '/kkaigo.sqlite';
$SELF = strtok($_SERVER['SCRIPT_NAME'], '?');           // 例: /kkaigo.php
$GSI = 'https://msearch.gsi.go.jp/address-search/AddressSearch';
$OGP = 'https://kurage.exbridge.jp/images/ogp/kkaigo.png';
// **画面の並びは事業所数の多い順ではなく、探されている順。** 検索需要を実測して決めた
// （横浜市の例・月間: 特別養護老人ホーム390／老人ホーム320／有料老人ホーム170／
//  サ高住170／デイサービス110／介護老人保健施設110）。2026-09-24 に4→17種別へ。
$KINDS = array(
    '訪問介護', '居宅介護支援',
    '特別養護老人ホーム', '介護老人保健施設', '地域密着型特別養護老人ホーム',
    '有料老人ホーム（特定施設）', 'サービス付き高齢者向け住宅（特定施設）', '軽費老人ホーム（特定施設）',
    '認知症対応型共同生活介護（グループホーム）',
    '通所介護（デイサービス）', '地域密着型通所介護', '通所リハビリテーション', '認知症対応型通所介護',
    '短期入所生活介護（ショートステイ）', '短期入所療養介護（老健）',
    '定期巡回・随時対応型訪問介護看護', '夜間対応型訪問介護',
);
// 画面に出す通り名。「居宅介護支援」は制度名で、探している家族は「ケアマネ」で探す
$ALIAS = array(
    '居宅介護支援' => 'ケアマネ事業所',
    '定期巡回・随時対応型訪問介護看護' => '定期巡回', '夜間対応型訪問介護' => '夜間対応型',
    '有料老人ホーム（特定施設）' => '有料老人ホーム', '軽費老人ホーム（特定施設）' => '軽費老人ホーム',
    'サービス付き高齢者向け住宅（特定施設）' => 'サービス付き高齢者向け住宅',
    '認知症対応型共同生活介護（グループホーム）' => 'グループホーム（認知症）',
    '通所介護（デイサービス）' => 'デイサービス', '地域密着型通所介護' => '地域密着型デイサービス',
    '認知症対応型通所介護' => '認知症対応型デイサービス',
    '短期入所生活介護（ショートステイ）' => 'ショートステイ', '短期入所療養介護（老健）' => 'ショートステイ（老健）',
);
// 訪問系とケアマネに定員という考え方は無い（CSVの「定員」列は0）。画面に定員を出さない。
// 入所・通所系は定員に意味があるので、0 のときだけ出さない。
$NO_CAPACITY = array('訪問介護', '居宅介護支援', '定期巡回・随時対応型訪問介護看護', '夜間対応型訪問介護');
// 都道府県は JIS の順（北海道→沖縄）で出す。文字コード順に並べると「三重県」が先頭に来る。
$PREFS = array('北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県', '茨城県', '栃木県', '群馬県',
    '埼玉県', '千葉県', '東京都', '神奈川県', '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県',
    '岐阜県', '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県', '奈良県', '和歌山県',
    '鳥取県', '島根県', '岡山県', '広島県', '山口県', '徳島県', '香川県', '愛媛県', '高知県',
    '福岡県', '佐賀県', '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県');

try {
    $db = new PDO('sqlite:' . $DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500); echo 'データを読み込めませんでした'; exit;
}
$META = array();
foreach ($db->query('SELECT k,v FROM meta') as $r) { $META[$r['k']] = $r['v']; }
$TPS = json_decode($META['timepoints'], true);
$LATEST = $META['latest_tp'];

// ── データの引き当て ────────────────────────────────────
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function n($v) { return number_format((int)$v); }

/** 202603 → 2026年3月末 */
function tp_label($tp) { global $META; static $L = null; if ($L === null) { $L = json_decode($META['tp_labels'], true); } return isset($L[$tp]) ? $L[$tp] : substr($tp, 0, 4) . '年' . (int)substr($tp, 4, 2) . '月'; }
/** 制度名を、探している人が使う言葉に置き換える（訪問介護 → 訪問介護）。 */
function kl($kind) { global $ALIAS; return isset($ALIAS[$kind]) ? $ALIAS[$kind] : $kind; }
/** 種別ごとの色分け。訪問介護が主役。 */
function kcls($kind) { return $kind === '訪問介護' ? 'a' : ($kind === '居宅介護支援' ? 'b' : ''); }

/** 住所を 都道府県 と 市区町村 に割る。政令市は区ではなく市（名古屋市瑞穂区→名古屋市）。 */
function split_address($addr) {
    if (!$addr) return array(null, null);
    if (!preg_match('/^(北海道|東京都|京都府|大阪府|.{2,3}?県)/u', $addr, $m)) return array(null, null);
    $pref = $m[1];
    $rest = mb_substr($addr, mb_strlen($pref, 'UTF-8'), null, 'UTF-8');
    if (preg_match('/^(.+?市)/u', $rest, $m2)) return array($pref, $m2[1]);
    if (preg_match('/^(?:.+?郡)?(.+?[町村])/u', $rest, $m3)) return array($pref, $m3[1]);
    if (preg_match('/^(.+?区)/u', $rest, $m4)) return array($pref, $m4[1]);
    return array($pref, null);
}

/** 住所文字列から「名古屋市瑞穂区」のような区つきの表記を拾う。無ければ空。 */
function ward_of($addr, $city_key) {
    if (!$addr || !$city_key) return '';
    if (preg_match('/' . preg_quote($city_key, '/') . '(.+?区)/u', $addr, $m)) return $city_key . $m[1];
    return '';
}

/** 国土地理院の住所検索。表記をそろえ、緯度経度も取る。落ちたら座標なしで続ける。 */
function geocode($q, $GSI) {
    $out = array('title' => $q, 'lat' => null, 'lon' => null, 'ok' => false);
    $ctx = stream_context_create(array('http' => array('timeout' => 6, 'header' => "User-Agent: kkaigo/1.0\r\n")));
    $raw = @file_get_contents($GSI . '?q=' . rawurlencode($q), false, $ctx);
    if ($raw === false) return $out;
    $items = json_decode($raw, true);
    if (!is_array($items) || !count($items)) return $out;
    $best = null; $bestScore = -1;
    foreach ($items as $it) {
        $t = isset($it['properties']['title']) ? $it['properties']['title'] : '';
        $score = (mb_strpos($t, $q) !== false ? 2 : 0) + (mb_strpos($t, $q) === 0 ? 1 : 0);
        if ($score > $bestScore) { $bestScore = $score; $best = $it; }
    }
    if (!$best) return $out;
    $out['title'] = isset($best['properties']['title']) ? $best['properties']['title'] : $q;
    if (isset($best['geometry']['coordinates'][0])) {
        $out['lon'] = (float)$best['geometry']['coordinates'][0];
        $out['lat'] = (float)$best['geometry']['coordinates'][1];
        $out['ok'] = true;
    }
    return $out;
}

function hav($lat1, $lon1, $lat2, $lon2) {
    $r = 6371.0;
    $p = M_PI / 180;
    $a = 0.5 - cos(($lat2 - $lat1) * $p) / 2
       + cos($lat1 * $p) * cos($lat2 * $p) * (1 - cos(($lon2 - $lon1) * $p)) / 2;
    return $r * 2 * asin(sqrt($a));
}

/** 近い事業所を距離順に。bbox で粗く絞ってから距離を計る（SQLite に三角関数が無いため）。 */
function nearby($db, $lat, $lon, $km, $kind, $limit = 40) {
    $dlat = $km / 111.0;
    $dlon = $km / (111.0 * max(cos($lat * M_PI / 180), 0.01));
    $sql = 'SELECT * FROM offices WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?';
    $args = array($lat - $dlat, $lat + $dlat, $lon - $dlon, $lon + $dlon);
    if ($kind) { $sql .= ' AND kind = ?'; $args[] = $kind; }
    $st = $db->prepare($sql); $st->execute($args);
    $rows = array();
    foreach ($st as $r) {
        $d = hav($lat, $lon, (float)$r['lat'], (float)$r['lon']);
        if ($d > $km) continue;
        $r['km'] = $d;
        $rows[] = $r;
    }
    usort($rows, function ($a, $b) { return $a['km'] < $b['km'] ? -1 : ($a['km'] > $b['km'] ? 1 : 0); });
    return array_slice($rows, 0, $limit);
}

/** 市区町村の様子。種別ごとの件数・定員合計・公表件数の推移・消えた事業所。 */
function city_stats($db, $pref, $city_key) {
    $out = array('pref' => $pref, 'city_key' => $city_key, 'kinds' => array(),
                 'series' => array(), 'gone' => array(), 'total' => 0, 'capacity' => 0);
    $st = $db->prepare('SELECT kind, count(*) n, sum(capacity) cap, sum(capacity IS NULL) nocap
                        FROM offices WHERE pref=? AND city_key=? GROUP BY kind');
    $st->execute(array($pref, $city_key));
    foreach ($st as $r) {
        $out['kinds'][$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap'], 'nocap' => (int)$r['nocap']);
        $out['total'] += (int)$r['n'];
        $out['capacity'] += (int)$r['cap'];
    }
    $st = $db->prepare('SELECT tp, kind, n FROM counts WHERE pref=? AND city_key=? ORDER BY tp');
    $st->execute(array($pref, $city_key));
    foreach ($st as $r) { $out['series'][$r['kind']][$r['tp']] = (int)$r['n']; }
    $st = $db->prepare('SELECT kind, name, city, corp, corp_key, capacity, last_tp FROM gone WHERE pref=? AND city_key=? ORDER BY last_tp DESC, kind, name');
    $st->execute(array($pref, $city_key));
    $out['gone'] = $st->fetchAll();
    // 政令市・東京23区は区ごとの内訳も持つ（区名での検索に応える）
    $st = $db->prepare('SELECT city, count(*) n FROM offices WHERE pref=? AND city_key=? AND city <> ? GROUP BY city ORDER BY city');
    $st->execute(array($pref, $city_key, $city_key));
    $out['wards'] = $st->fetchAll();
    return $out;
}

function national($db, $LATEST) {
    // 取り込み時に build_db.py が meta に埋めた集計を使う（毎回 GROUP BY すると heteml で18秒かかった）
    global $META;
    if (!empty($META['nat_json'])) { $j = json_decode($META['nat_json'], true); if ($j) { return $j; } }
    $out = array('kinds' => array(), 'total' => 0, 'capacity' => 0, 'series' => array(), 'gone' => array());
    foreach ($db->query("SELECT kind, count(*) n, sum(capacity) cap FROM offices GROUP BY kind") as $r) {
        $out['kinds'][$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap']);
        $out['total'] += (int)$r['n'];
        $out['capacity'] += (int)$r['cap'];
    }
    foreach ($db->query('SELECT tp, kind, sum(n) n FROM counts GROUP BY tp, kind ORDER BY tp') as $r) {
        $out['series'][$r['kind']][$r['tp']] = (int)$r['n'];
    }
    foreach ($db->query('SELECT last_tp, kind, count(*) n, sum(capacity) cap FROM gone GROUP BY last_tp, kind ORDER BY last_tp') as $r) {
        $out['gone'][$r['last_tp']][$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap']);
    }
    return $out;
}

// ── 画面の部品 ─────────────────────────────────────────
function head_html($title, $desc, $canon, $ld_extra = null) {
    global $SELF, $SITE, $OGP, $META, $LATEST, $NAT;
    $base = 'https://kurage.exbridge.jp' . $SELF;
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . '</title>';
    echo '<meta name="description" content="' . h($desc) . '">';
    echo '<link rel="canonical" href="' . h($base . $canon) . '">';
    echo '<meta property="og:title" content="' . h($title) . '"><meta property="og:description" content="' . h($desc) . '"><meta property="og:type" content="website">';
    echo '<meta property="og:image" content="' . h($OGP) . '">';
    echo '<meta property="og:site_name" content="' . h($SITE) . '"><meta property="og:url" content="' . h($base . $canon) . '">';
    echo '<meta property="og:locale" content="ja_JP">';
    echo '<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="' . h($OGP) . '">';
    echo '<style>'
       . ':root{--ink:#12202f;--mut:#5d6b7a;--teal:#0a9a8f;--teal-d:#087f76;--line:#dfe7ec;--bg:#f5f8fa;--red-l:#fdecea;--amber-l:#fdf6e3;--blue:#2c6fbb;--blue-l:#eaf2fb}'
       . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.8 "Noto Sans JP",system-ui,sans-serif}'
       . 'a{color:var(--teal-d)}.wrap{width:min(960px,100% - 32px);margin:0 auto}'
       . 'header{background:#fff;border-bottom:1px solid var(--line)}.brand{display:block;padding:14px 0 6px;font-weight:800;font-size:18px;text-decoration:none;color:var(--ink)}'
       . '.menu{display:flex;gap:14px;flex-wrap:wrap;padding-bottom:12px;font-size:14px}.menu a{text-decoration:none;color:var(--mut)}.menu a.on{color:var(--teal-d);font-weight:700}'
       . 'main{padding:22px 0 40px}h1{font-size:26px;line-height:1.4;margin:0 0 10px}h2{font-size:20px;margin:26px 0 10px}h3{font-size:16px;margin:18px 0 8px}.lead{color:var(--mut)}'
       . '.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;margin:14px 0}'
       . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}'
       . '.card{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;min-width:0}'
       . '.card.none{background:#fbfcfd}.card.lv2{border-color:#e6c98b;background:var(--amber-l)}.card.lv3{border-color:#e3a9a1;background:var(--red-l)}'
       . '.card .k{font-size:12px;color:var(--mut)}.card .v{font-size:22px;font-weight:800;margin-top:4px}.card .s{font-size:12px;color:var(--mut);margin-top:4px}'
       . '.form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}'
       . '.src{font-size:12.5px;color:var(--mut);line-height:1.8}'
       . '.btn{display:inline-block;background:var(--teal);color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}'
       . '.btn.ghost{background:#fff;color:var(--teal-d);border:1px solid var(--line)}'
       . 'input[type=text]{flex:1 1 260px;min-width:0;font-size:17px;padding:12px 14px;border:2px solid var(--line);border-radius:10px}'
       . 'select{font:inherit;padding:10px 12px;border:2px solid var(--line);border-radius:10px;background:#fff}'
       . '.tscroll{overflow-x:auto}table.t{width:100%;border-collapse:collapse;font-size:14px;min-width:460px}'
       . 'table.t th,table.t td{border-bottom:1px solid var(--line);padding:8px 10px;text-align:left;vertical-align:top}'
       . 'table.t th{color:var(--mut);font-size:12px}td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}'
       . '.off{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff;margin:10px 0}'
       . '.off .nm{font-weight:700;font-size:17px;line-height:1.5}.off .meta{font-size:13.5px;color:var(--mut);margin-top:4px}'
       . '.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:999px;padding:2px 10px;border:1px solid var(--line);background:var(--bg);color:var(--mut);margin-right:6px}'
       . '.tag.a{background:#eaf7f5;border-color:#a9ddd6;color:var(--teal-d)}.tag.b{background:var(--blue-l);border-color:#bcd4ef;color:var(--blue)}'
       . '.km{font-weight:800;color:var(--teal-d);font-variant-numeric:tabular-nums}'
       . '.bars{display:flex;gap:3px;align-items:flex-end;height:54px;margin:8px 0}'
       . '.bars i{flex:1;background:var(--teal);opacity:.75;border-radius:2px 2px 0 0;min-height:2px;display:block}'
       . '.bars i.last{opacity:1}'
       . 'footer{border-top:1px solid var(--line);padding:22px 0 40px;color:var(--mut);font-size:13px;background:#fff}ul.plain{margin:0;padding-left:20px}'
       . '</style>';
    echo '<script>(function(){var s=document.createElement("script");s.src="https://kurage.exbridge.jp/simpletrack.php?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);s.async=true;document.head.appendChild(s)})();</script>';
    $graph = array(
        array('@type' => 'WebApplication', 'name' => $SITE,
              'url' => $base . '/', 'applicationCategory' => 'GovernmentApplication',
              'operatingSystem' => 'Web', 'inLanguage' => 'ja',
              'description' => '住所を入れると、近くの訪問介護・ケアマネ事業所（居宅介護支援）・定期巡回・随時対応型訪問介護看護を距離順に返します。運営法人・連絡先つき。法人ごとに何か所持っているかもたどれます。',
              'offers' => array('@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'JPY'),
              'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/')),
        array('@type' => 'Dataset',
              'name' => '介護サービス情報公表システム（訪問介護とケアマネ事業所）' . tp_label($LATEST) . '時点',
              'description' => '訪問介護・ケアマネ事業所（居宅介護支援）・定期巡回・随時対応型訪問介護看護・夜間対応型訪問介護の全国' . n($NAT['total']) . '事業所を、住所・緯度経度・運営法人つきで機械が読める形にしたもの。空き状況は公表されていないため含みません。訪問介護には定員の公表がありません。',
              'url' => $base . '/data', 'inLanguage' => 'ja',
              'temporalCoverage' => substr($LATEST, 0, 4) . '-' . substr($LATEST, 4, 2),
              'creator' => array('@type' => 'Organization', 'name' => '厚生労働省'),
              'isBasedOn' => $META['source_url'],
              'license' => 'https://www.digital.go.jp/resources/open_data',
              'distribution' => array(
                  array('@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => $base . '/data/offices.csv'),
                  array('@type' => 'DataDownload', 'encodingFormat' => 'text/csv', 'contentUrl' => $base . '/data/gone.csv'))),
        array('@type' => 'FAQPage', 'mainEntity' => array(
            array('@type' => 'Question', 'name' => 'ケアマネ事業所と居宅介護支援事業所は同じものですか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '同じものです。制度の名前が「居宅介護支援」で、ケアマネジャー（介護支援専門員）がケアプランを作る事業所です。このサイトは全国の居宅介護支援' . n($NAT['kinds']['居宅介護支援']['n']) . 'か所と訪問介護' . n($NAT['kinds']['訪問介護']['n']) . 'か所を住所から探せます。')),
            array('@type' => 'Question', 'name' => '事業所の空きや受け入れの可否は分かりますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '分かりません。空き状況は公表データに含まれていません。訪問介護とケアマネ事業所には定員という考え方も無いので、定員も出しません。受けてもらえるかは事業所か地域包括支援センターへお問い合わせください。')),
            array('@type' => 'Question', 'name' => '近所の訪問介護やケアマネ事業所は減っていますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '市区町村ごとに、2024年12月末と2026年6月末の公表件数と、前は載っていて今は載っていない事業所の一覧を出しています。全国では訪問介護が差し引きで増え、居宅介護支援が差し引きで減っていますが、どちらも1年半で3,000か所を超える事業所が公表データから消え、それ以上の数が新しく載っています。消えた理由（廃止・休止・法人の再編・登録の更新漏れ）は公表されていないため「廃止した」とは書きません。')),
            array('@type' => 'Question', 'name' => '同じ法人がいくつ事業所を持っているか分かりますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '分かります。法人ごとのページで、いま公表されている事業所の数・都道府県・一覧と、公表データから消えた事業所の数を出しています。法人番号には空欄やゆれがあるため、正規化した法人名で束ねています。')),
            array('@type' => 'Question', 'name' => 'データはどこから取っていますか',
                  'acceptedAnswer' => array('@type' => 'Answer', 'text' => '厚生労働省「介護サービス情報公表システム」のオープンデータです。営利・非営利を問わず二次利用できる公開データで、毎年6月末・12月末時点のものが公開されます。')))),
    );
    // $ld_extra は1件でも、配列で複数渡してもよい（BreadcrumbList と ItemList を両方出すため）
    if ($ld_extra) {
        if (isset($ld_extra['@type'])) { $graph[] = $ld_extra; }
        else { foreach ($ld_extra as $x) { $graph[] = $x; } }
    }
    echo '<script type="application/ld+json">' . json_encode(array('@context' => 'https://schema.org', '@graph' => $graph), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    echo '</head><body><header><div class="wrap">';
    echo '<a class="brand" href="' . h($SELF) . '/">' . h($SITE) . '</a>';
    echo '<nav class="menu">';
    foreach (array('/' => '住所から探す', '/corps' => '運営法人から探す', '/gone' => '公表データから消えた事業所', '/data' => 'データ', '/about' => 'このサイトについて') as $u => $t) {
        echo '<a href="' . h($SELF . $u) . '">' . h($t) . '</a>';
    }
    echo '</nav></div></header><main><div class="wrap">';
}

function foot_html() {
    global $SELF, $META, $LATEST;
    echo '</div></main><footer><div class="wrap">';
    echo '<p class="src">' . h($META['attribution']) . '（' . h(tp_label($LATEST)) . '時点）<br>'
       . '出典: <a href="' . h($META['source_url']) . '" rel="nofollow">介護サービス情報公表システム オープンデータ</a>。'
       . '空き状況・支援の内容・職員の配置は公表データに含まれないため、このサイトでは表示しません。</p>';
    echo '<p class="src">提供: <a href="https://exbridge.jp/">株式会社エクスブリッジ</a>（名古屋市）／'
       . '<a href="https://kappstore.exbridge.jp/app.php?id=57aebd041b7bab37&amp;ref=kkaigo">オンプレミス版</a>もあります。</p>';
    echo '</div></footer></body></html>';
}

/** 住所の入力欄。どのページからでも引き直せるように置く。 */
function search_form($q = '', $kind = '', $km = 5) {
    global $SELF, $KINDS;
    echo '<form class="form" method="get" action="' . h($SELF) . '/">';
    echo '<input type="text" name="q" value="' . h($q) . '" placeholder="住所を入れる（例: 名古屋市中区三の丸3-1-1）" aria-label="住所">';
    echo '<select name="kind" aria-label="サービス種別"><option value="">すべての種別</option>';
    foreach ($KINDS as $k) { echo '<option value="' . h($k) . '"' . ($kind === $k ? ' selected' : '') . '>' . h(kl($k)) . '</option>'; }
    echo '</select>';
    echo '<select name="km" aria-label="範囲">';
    foreach (array(2, 5, 10, 20) as $v) { echo '<option value="' . $v . '"' . ((int)$km === $v ? ' selected' : '') . '>' . $v . 'km以内</option>'; }
    echo '</select>';
    echo '<button class="btn" type="submit">探す</button></form>';
}

/** 事業所1件のカード。 */
function office_card($r, $show_km = true, $show_corp = true) {
    global $SELF;
    $cls = kcls($r['kind']);
    echo '<div class="off">';
    echo '<div class="nm"><a href="' . h($SELF . '/office/' . $r['id']) . '">' . h($r['name']) . '</a></div>';
    echo '<div class="meta"><span class="tag ' . $cls . '">' . h(kl($r['kind'])) . '</span>';
    if ($show_km && isset($r['km'])) { echo '<span class="km">' . number_format($r['km'], 1) . ' km</span>'; }
    echo '</div>';
    echo '<div class="meta">' . h($r['pref'] . $r['city'] . $r['addr']) . '</div>';
    $bits = array();
    if ($r['capacity'] !== null && $r['capacity'] !== '') { $bits[] = '定員 ' . n($r['capacity']) . '人'; }
    if ($show_corp && $r['corp']) { $bits[] = '運営 <a href="' . h($SELF . '/corp/' . rawurlencode($r['corp_key'])) . '">' . h($r['corp']) . '</a>'; }
    if ($r['tel']) { $bits[] = 'TEL ' . h($r['tel']); }
    if ($r['days']) { $bits[] = h($r['days']); }
    if ($bits) { echo '<div class="meta">' . implode('／', $bits) . '</div>'; }
    if ($r['url']) { echo '<div class="meta"><a href="' . h($r['url']) . '" rel="nofollow noopener" target="_blank">事業所のサイト</a></div>'; }
    echo '</div>';
}

/** 推移の棒グラフ（画像もJSも使わない）。 */
function bars($series, $tps) {
    $max = 1;
    foreach ($tps as $t) { if (isset($series[$t])) { $max = max($max, $series[$t]); } }
    echo '<div class="bars">';
    $i = 0;
    foreach ($tps as $t) {
        $v = isset($series[$t]) ? $series[$t] : 0;
        $i++;
        echo '<i class="' . ($i === count($tps) ? 'last' : '') . '" style="height:' . max(2, (int)round($v * 100 / $max)) . '%" title="' . h(tp_label($t) . ' ' . n($v) . '件') . '"></i>';
    }
    echo '</div>';
}

// ── ルーティング ───────────────────────────────────────
$NAT = national($db, $LATEST);
$path = isset($_SERVER['PATH_INFO']) ? trim($_SERVER['PATH_INFO'], '/') : '';
$base = 'https://kurage.exbridge.jp' . $SELF;

// 配布用 CSV。加工元が公開データなので、加工後もそのまま持ち出せる形で出す。
if ($path === 'data/offices.csv' || $path === 'data/gone.csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    $out = fopen('php://output', 'w');
    if ($path === 'data/offices.csv') {
        fputcsv($out, array('種別', '事業所番号', '事業所名', '法人名', '法人番号', '都道府県', '市区町村', '番地以降',
                            '電話', 'URL', '緯度', '経度', '定員', '利用可能曜日', '特記事項', '共生型', '備考', ''));
        foreach ($db->query('SELECT * FROM offices ORDER BY pref, city_key, kind, name') as $r) {
            fputcsv($out, array($r['kind'], $r['office_no'], $r['name'], $r['corp'], $r['corp_no'], $r['pref'], $r['city'], $r['addr'],
                                $r['tel'], $r['url'], $r['lat'], $r['lon'], $r['capacity'],
                                $r['days'], $r['note'], $r['kyosei'], $r['remarks'], ''));
        }
    } else {
        fputcsv($out, array('種別', '事業所番号', '事業所名', '法人名', '法人番号', '都道府県', '市区町村', '最後の定員', '最後に公表された時点'));
        foreach ($db->query('SELECT * FROM gone ORDER BY last_tp DESC, pref, city_key, kind, name') as $r) {
            fputcsv($out, array($r['kind'], $r['office_no'], $r['name'], $r['corp'], $r['corp_no'], $r['pref'], $r['city'], $r['capacity'], tp_label($r['last_tp'])));
        }
    }
    fclose($out);
    exit;
}

// JSON API。住所を渡すと、近い事業所と市区町村の様子を返す。
if ($path === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $kind = isset($_GET['kind']) && in_array($_GET['kind'], $KINDS, true) ? $_GET['kind'] : '';
    $km = isset($_GET['km']) ? max(1, min(50, (int)$_GET['km'])) : 5;
    if ($q === '') { http_response_code(400); echo json_encode(array('error' => '住所（q）を指定してください'), JSON_UNESCAPED_UNICODE); exit; }
    $g = geocode($q, $GSI);
    list($pref, $city) = split_address($g['title']);
    $res = array('query' => $q, 'address' => $g['title'], 'pref' => $pref, 'city' => $city,
                 'as_of' => tp_label($LATEST), 'source' => $META['source_url'],
                 'geocoded' => $g['ok'], 'offices' => array(), 'city_stats' => null,
                 'note' => '空き状況は公表データに含まれないため返しません。訪問系とケアマネ事業所に定員という考え方はありません。');
    if ($g['ok']) {
        foreach (nearby($db, $g['lat'], $g['lon'], $km, $kind) as $r) {
            $res['offices'][] = array('kind' => $r['kind'], 'name' => $r['name'], 'corp' => $r['corp'],
                'address' => $r['pref'] . $r['city'] . $r['addr'], 'tel' => $r['tel'], 'url' => $r['url'],
                'capacity' => $r['capacity'] === null ? null : (int)$r['capacity'],
                'lat' => (float)$r['lat'], 'lon' => (float)$r['lon'], 'km' => round($r['km'], 2));
        }
    } else {
        $res['error'] = '住所から場所を特定できませんでした（国土地理院の住所検索）。市区町村までの表示だけ返します。';
    }
    if ($pref && $city) { $res['city_stats'] = city_stats($db, $pref, preg_replace('/(.+?市).+区$/u', '$1', $city)); }
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($path === 'robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nSitemap: $base/sitemap.xml\n";
    exit;
}

// サイトマップの lastmod。**データベースの更新日**を使う（毎回 now を入れない）。
// 2026-09-22 実測: kurage.exbridge.jp のサイトマップ索引29本・143,563URLのうち、
// Google が取得していたのは lastmod を持つ3本だけだった。残り26本は lastmod ゼロ。
$LASTMOD = gmdate('Y-m-d', @filemtime($DB_PATH) ?: time());

if ($path === 'sitemap.xml' || preg_match('#^sitemap-(\d+)\.xml$#', $path, $sm)) {
    header('Content-Type: application/xml; charset=utf-8');
    $per = 20000;
    if ($path === 'sitemap.xml') {
        $n = (int)$db->query('SELECT count(*) FROM offices')->fetchColumn();
        $pages = (int)ceil($n / $per);
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        echo '<sitemap><loc>' . h($base . '/sitemap-0.xml') . '</loc><lastmod>' . $LASTMOD . '</lastmod></sitemap>';
        for ($i = 1; $i <= $pages; $i++) { echo '<sitemap><loc>' . h($base . '/sitemap-' . $i . '.xml') . '</loc><lastmod>' . $LASTMOD . '</lastmod></sitemap>'; }
        echo '</sitemapindex>';
        exit;
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    if ((int)$sm[1] === 0) {
        foreach (array('/', '/corps', '/gone', '/data', '/about') as $u) {
            echo '<url><loc>' . h($base . $u) . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>monthly</changefreq></url>';
        }
        foreach ($PREFS as $pp) { echo '<url><loc>' . h($base . '/pref/' . rawurlencode($pp)) . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>monthly</changefreq></url>'; }
        $st = $db->query('SELECT DISTINCT pref, city_key FROM offices ORDER BY pref, city_key');
        foreach ($st as $r) {
            echo '<url><loc>' . h($base . '/city/' . rawurlencode($r['pref']) . '/' . rawurlencode($r['city_key'])) . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>monthly</changefreq></url>';
        }
        // 法人ページは2か所以上持つ法人だけ索引に入れる。1か所しかない法人のページは
        // 事業所ページと中身が同じになるので、薄いページを量産しない。
        $st = $db->query("SELECT corp_key FROM offices WHERE corp_key <> '' GROUP BY corp_key HAVING count(*) >= 2 ORDER BY count(*) DESC");
        foreach ($st as $r) {
            echo '<url><loc>' . h($base . '/corp/' . rawurlencode($r['corp_key'])) . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>monthly</changefreq></url>';
        }
        // 政令市・東京23区は区ページも索引に入れる（「名古屋市中区 訪問介護」で探す人が多い）
        $st = $db->query('SELECT DISTINCT pref, city_key, city FROM offices WHERE city <> city_key ORDER BY pref, city_key, city');
        foreach ($st as $r) {
            echo '<url><loc>' . h($base . '/city/' . rawurlencode($r['pref']) . '/' . rawurlencode($r['city_key']) . '/' . rawurlencode($r['city'])) . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>monthly</changefreq></url>';
        }
        // **市区町村 × 種別のページ。** 住民は「横浜市 特別養護老人ホーム」のように
        // 市区町村＋種別で検索する（実測 月390）。**事業所が1か所もない組み合わせは出さない**
        // （中身の無いページを何万枚も索引に送ることになる）。
        $st = $db->query('SELECT pref, city_key, kind, count(*) n FROM offices GROUP BY pref, city_key, kind HAVING n > 0 ORDER BY pref, city_key, kind');
        foreach ($st as $r) {
            echo '<url><loc>' . h($base . '/city/' . rawurlencode($r['pref']) . '/' . rawurlencode($r['city_key']) . '/k/' . rawurlencode($r['kind'])) . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>monthly</changefreq></url>';
        }
    } else {
        $off = ($sm[1] - 1) * $per;
        $st = $db->prepare('SELECT id FROM offices ORDER BY id LIMIT ? OFFSET ?');
        $st->execute(array($per, $off));
        foreach ($st as $r) { echo '<url><loc>' . h($base . '/office/' . $r['id']) . '</loc><lastmod>' . $LASTMOD . '</lastmod><changefreq>yearly</changefreq></url>'; }
    }
    echo '</urlset>';
    exit;
}

if ($path === 'llms.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "# $SITE\n\n";
    echo "住所から、訪問介護・ケアマネ事業所（居宅介護支援）・定期巡回・随時対応型訪問介護看護を距離順に探せます。法人ごとの事業所数もたどれます。\n";
    echo "出どころは 厚生労働省（独立行政法人福祉医療機構）介護サービス情報公表システムのオープンデータ（" . tp_label($LATEST) . "時点）。\n\n";
    echo "## 収録\n";
    foreach ($NAT['kinds'] as $k => $v) { echo "- $k: " . n($v['n']) . "事業所\n"; }
    echo "- 合計 " . n($NAT['total']) . "事業所 / 緯度経度は全件あり\n\n";
    echo "## 言えないこと（重要）\n";
    echo "- 空き状況は公表データに無いので出しません。訪問系とケアマネ事業所に定員という考え方はありません。\n";
    echo "- 公表データから消えた事業所について、廃止したかどうかは分かりません。消えた事実だけを書いています。\n";
    echo "- 公表件数の増加は事業所の増加とは限りません。自治体の登録が進んだぶんが混ざります。\n\n";
    echo "## 使い方\n";
    echo "- 画面: $base/?q=住所&kind=訪問介護&km=5\n";
    echo "- API : $base/api?q=住所&kind=訪問介護&km=5 （JSON）\n";
    echo "- 都道府県: $base/pref/{都道府県}\n";
    echo "- 市区町村: $base/city/{都道府県}/{市区町村}（政令市は .../{市区町村}/{区} まで）\n";
    echo "- CSV : $base/data/offices.csv , $base/data/gone.csv\n";
    exit;
}

// ── 事業所ページ ───────────────────────────────────────
if (preg_match('#^office/(\d+)$#', $path, $m)) {
    $st = $db->prepare('SELECT * FROM offices WHERE id = ?');
    $st->execute(array((int)$m[1]));
    $o = $st->fetch();
    if (!$o) { http_response_code(404); head_html('見つかりません｜' . $SITE, '指定された事業所は見つかりませんでした。', '/'); echo '<h1>見つかりません</h1><p class="lead">この番号の事業所は公表データにありません。</p>'; search_form(); foot_html(); exit; }
    $title = $o['name'] . '（' . kl($o['kind']) . '・' . $o['pref'] . $o['city'] . '）の住所・連絡先・運営法人';
    $desc = $o['pref'] . $o['city'] . $o['addr'] . 'の' . $o['kind'] . '「' . $o['name'] . '」。'
          . ($o['capacity'] !== null ? '定員' . n($o['capacity']) . '人。' : '定員は公表されていません。')
          . '運営は' . $o['corp'] . '。同じ法人の他の事業所と、近くの事業所も距離順で探せます（' . tp_label($LATEST) . '時点の公表データ）。';
    $ld = array('@type' => 'GovernmentService', 'name' => $o['name'],
                'serviceType' => $o['kind'], 'areaServed' => $o['pref'] . $o['city'],
                'provider' => array('@type' => 'Organization', 'name' => $o['corp']),
                'availableChannel' => array('@type' => 'ServiceChannel',
                    'serviceLocation' => array('@type' => 'Place', 'name' => $o['name'],
                        'address' => array('@type' => 'PostalAddress', 'addressRegion' => $o['pref'],
                                           'addressLocality' => $o['city'], 'streetAddress' => $o['addr'], 'addressCountry' => 'JP'),
                        'geo' => array('@type' => 'GeoCoordinates', 'latitude' => (float)$o['lat'], 'longitude' => (float)$o['lon'])),
                    'servicePhone' => $o['tel']));
    head_html($title . '｜' . $SITE, $desc, '/office/' . $o['id'], $ld);
    echo '<h1>' . h($o['name']) . '</h1>';
    echo '<p class="lead"><span class="tag ' . kcls($o['kind']) . '">' . h(kl($o['kind'])) . '</span>'
       . h($o['pref'] . $o['city'] . $o['addr']) . '</p>';
    echo '<div class="panel"><div class="grid">';
    echo '<div class="card"><div class="k">利用できる曜日</div><div class="v" style="font-size:16px">' . ($o['days'] ? h($o['days']) : '公表なし') . '</div>'
       . '<div class="s">訪問系・ケアマネに定員はありません</div></div>';
    echo '<div class="card"><div class="k">電話</div><div class="v" style="font-size:18px">' . ($o['tel'] ? h($o['tel']) : '—') . '</div><div class="s">空きは事業所へ直接</div></div>';
    $cn = $db->prepare('SELECT count(*) FROM offices WHERE corp_key = ?');
    $cn->execute(array($o['corp_key']));
    $corp_n = (int)$cn->fetchColumn();
    echo '<div class="card"><div class="k">運営法人</div><div class="v" style="font-size:16px">'
       . '<a href="' . h($SELF . '/corp/' . rawurlencode($o['corp_key'])) . '">' . h($o['corp']) . '</a></div>'
       . '<div class="s">' . ($corp_n > 1 ? 'この法人は全国' . n($corp_n) . 'か所' : 'この法人はここ1か所のみ')
       . ($o['corp_no'] ? '／法人番号 ' . h($o['corp_no']) : '') . '</div></div>';
    echo '<div class="card"><div class="k">事業所番号</div><div class="v" style="font-size:18px">' . h($o['office_no']) . '</div><div class="s">自治体コード ' . h($o['area_code']) . '</div></div>';
    echo '</div>';
    if ($o['kyosei']) { echo '<h3>共生型サービス</h3><p>' . h($o['kyosei']) . '</p>'; }
    if ($o['note']) { echo '<h3>利用可能曜日の特記事項</h3><p>' . h($o['note']) . '</p>'; }
    if ($o['remarks']) { echo '<h3>備考</h3><p>' . h($o['remarks']) . '</p>'; }
    if ($o['url']) { echo '<p><a class="btn ghost" href="' . h($o['url']) . '" rel="nofollow noopener" target="_blank">事業所のサイトを開く</a></p>'; }
    echo '</div>';
    $near = nearby($db, (float)$o['lat'], (float)$o['lon'], 5, '', 12);
    echo '<h2>この事業所の近くにある事業所</h2>';
    $shown = 0;
    foreach ($near as $r) { if ((int)$r['id'] === (int)$o['id']) { continue; } office_card($r); $shown++; if ($shown >= 8) { break; } }
    if (!$shown) { echo '<p class="lead">半径5km以内に、ほかの事業所は公表データにありません。</p>'; }
    echo '<p><a class="btn ghost" href="' . h($SELF . '/city/' . rawurlencode($o['pref']) . '/' . rawurlencode($o['city_key'])) . '">'
       . h($o['pref'] . $o['city_key']) . 'の訪問介護をまとめて見る</a></p>';
    foot_html();
    exit;
}

// ── 運営法人の一覧 ─────────────────────────────────────
// 「どの法人が、どこに、いくつ持っているか」を主語にした画面。
// 探している家族にとっては「同じ法人が近くに他にもある」が実用になり、
// 記者や議員にとっては「まとめて消えた法人がいる」が手がかりになる。
if ($path === 'corps') {
    $kw = isset($_GET['q']) ? trim($_GET['q']) : '';
    $title = '訪問介護・ケアマネ事業所を運営している法人の一覧（事業所数の多い順）';
    $desc = '訪問介護を運営する法人を、いま公表されている事業所の数の多い順に並べました。'
          . '都道府県の数と、公表データから消えた事業所の数つき。' . tp_label($LATEST) . '時点。';
    head_html($title . '｜' . $SITE, $desc, '/corps');
    echo '<h1>運営法人から探す</h1>';
    $ncorp = (int)$db->query("SELECT count(DISTINCT corp_key) FROM offices WHERE kind='訪問介護' AND corp_key <> ''")->fetchColumn();
    $ngh = (int)$db->query("SELECT count(*) FROM offices WHERE kind='訪問介護'")->fetchColumn();
    echo '<p class="lead">全国' . n($ngh) . 'か所の訪問介護を<strong>' . n($ncorp) . '法人</strong>が運営しています'
       . '（1法人あたり平均' . number_format($ngh / max(1, $ncorp), 2) . 'か所）。'
       . 'ほとんどは1〜2か所ですが、上位には全国に数百か所を持つ法人がいます。</p>';
    echo '<div class="panel"><form class="form" method="get" action="' . h($SELF) . '/corps">';
    echo '<input type="text" name="q" value="' . h($kw) . '" placeholder="法人名で絞る（例: 社会福祉法人）" aria-label="法人名">';
    echo '<button class="btn" type="submit">絞る</button></form></div>';

    $sql = "SELECT corp_key, max(corp) corp, count(*) n, count(DISTINCT pref) np,
                   sum(CASE WHEN kind='訪問介護' THEN 1 ELSE 0 END) gh
            FROM offices";
    // 法人名が空欄の事業所（訪問介護175か所）は1法人として束ねない
    $sql .= " WHERE corp_key <> ''"; $args = array();
    if ($kw !== '') { $sql .= ' AND corp LIKE ?'; $args[] = '%' . $kw . '%'; }
    $sql .= ' GROUP BY corp_key ORDER BY gh DESC, n DESC LIMIT 200';
    $st = $db->prepare($sql); $st->execute($args);
    $rows = $st->fetchAll();
    if (!count($rows)) {
        echo '<div class="panel"><p>その名前を含む法人は、いまの公表データにありません。</p></div>';
    } else {
        if ($kw !== '') { echo '<p class="lead">「' . h($kw) . '」を含む法人：<strong>' . n(count($rows)) . '件</strong>' . (count($rows) >= 200 ? '（多い順に200件まで）' : '') . '</p>'; }
        $goneq = $db->prepare("SELECT count(*) FROM gone WHERE corp_key=?");
        // 「消えた」は事業所全体の数。左の「訪問介護」列と基準が違うので見出しに書く
        echo '<div class="tscroll"><table class="t"><thead><tr><th>運営法人</th><th class="n">訪問介護</th><th class="n">事業所全体</th><th class="n">都道府県</th><th class="n">消えた<br><span style="font-size:11px;font-weight:400;color:var(--mut)">事業所全体</span></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $goneq->execute(array($r['corp_key']));
            $gn = (int)$goneq->fetchColumn();
            echo '<tr><td><a href="' . h($SELF . '/corp/' . rawurlencode($r['corp_key'])) . '">' . h($r['corp']) . '</a></td>'
               . '<td class="n">' . n($r['gh']) . '</td><td class="n">' . n($r['n']) . '</td>'
               . '<td class="n">' . n($r['np']) . '</td><td class="n">' . ($gn ? n($gn) : '—') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '<p class="src">「消えた」は事業所全体（訪問介護・居宅介護支援・定期巡回・随時対応型訪問介護看護・夜間対応型訪問介護の4種別）の数です。'
       . '訪問介護だけの内訳は法人ページでご覧ください。'
       . '<strong>消えた数だけを見ないでください</strong>——左の「訪問介護」「事業所全体」と並べて読みます。'
       . 'いまの数がそれ以上に多い法人は、法人の再編で事業所番号が付け替わった可能性が高く、事業所が無くなったという話ではありません。</p>';
    echo '<p class="src">法人は<strong>法人番号ではなく正規化した法人名</strong>で束ねています。法人番号には入力ゆれがあり'
       . '（空欄が1,853件、同じ法人名に別の番号がついている例も多数）、番号で束ねると同じ法人がばらけて数え落とすためです。'
       . 'そのぶん同名の別法人が混ざることがあるので、法人ページで法人番号と所在地をご確認ください。</p>';
    foot_html();
    exit;
}

// ── 法人ページ ─────────────────────────────────────────
if (preg_match('#^corp/(.+)$#', $path, $m)) {
    $ckey = rawurldecode($m[1]);
    $st = $db->prepare('SELECT * FROM offices WHERE corp_key=? ORDER BY kind, pref, city, name');
    $st->execute(array($ckey));
    $offs = $st->fetchAll();
    $st = $db->prepare('SELECT * FROM gone WHERE corp_key=? ORDER BY last_tp DESC, pref, city, name');
    $st->execute(array($ckey));
    $gones = $st->fetchAll();
    if (!count($offs) && !count($gones)) {
        http_response_code(404);
        head_html('見つかりません｜' . $SITE, 'その法人は公表データにありません。', '/corps');
        echo '<h1>見つかりません</h1><p class="lead">その法人は、いまの公表データにも、過去に公表されていた分にもありません。</p>';
        echo '<p><a class="btn ghost" href="' . h($SELF . '/corps') . '">運営法人の一覧へ</a></p>';
        foot_html(); exit;
    }
    $corp = count($offs) ? $offs[0]['corp'] : $gones[0]['corp'];
    // 法人番号は入力ゆれがあるので、**見つかったものを全部並べる**（1つに決めない）
    $nos = array();
    foreach (array_merge($offs, $gones) as $r) { if ($r['corp_no'] !== '') { $nos[$r['corp_no']] = true; } }
    $nos = array_keys($nos);

    $kc = array(); $prefs = array();
    foreach ($offs as $r) {
        $kc[$r['kind']] = (isset($kc[$r['kind']]) ? $kc[$r['kind']] : 0) + 1;
        $prefs[$r['pref']] = (isset($prefs[$r['pref']]) ? $prefs[$r['pref']] : 0) + 1;
    }
    $gh = isset($kc['訪問介護']) ? $kc['訪問介護'] : 0;
    $gone_after = 0;
    foreach ($gones as $r) { if ($r['last_tp'] >= '20250101') { $gone_after++; } }

    $title = $corp . 'が運営する訪問介護・ケアマネ事業所' . n($gh) . 'か所（都道府県別・一覧）';
    $desc = $corp . 'は' . tp_label($LATEST) . '時点で、訪問介護・ケアマネ事業所' . n($gh) . 'か所を含む'
          . n(count($offs)) . 'か所を' . n(count($prefs)) . '都道府県で公表しています。'
          . (count($gones) ? '公表データから消えた事業所は' . n(count($gones)) . 'か所（2024年12月末から2026年6月末までの1年半が' . n($gone_after) . 'か所）。' : '')
          . '消えた理由は公表されていません。';
    $ld = array('@type' => 'Organization', 'name' => $corp,
                'identifier' => count($nos) ? $nos[0] : null,
                'numberOfEmployees' => null);
    $ld = array_filter($ld, function ($v) { return $v !== null; });
    head_html($title . '｜' . $SITE, $desc, '/corp/' . rawurlencode($ckey), $ld);

    echo '<h1>' . h($corp) . '</h1>';
    echo '<p class="lead">' . h(tp_label($LATEST)) . '時点で公表されている、この法人の事業所です。</p>';
    echo '<div class="panel"><div class="grid">';
    echo '<div class="card"><div class="k">訪問介護</div><div class="v">' . n($gh) . '<span style="font-size:14px">か所</span></div>'
       . '<div class="s">訪問介護</div></div>';
    echo '<div class="card"><div class="k">事業所全体</div><div class="v">' . n(count($offs)) . '<span style="font-size:14px">か所</span></div>'
       . '<div class="s">' . h(implode('／', array_map(function ($k) use ($kc) { return kl($k) . n($kc[$k]); }, array_keys($kc)))) . '</div></div>';
    echo '<div class="card"><div class="k">広がり</div><div class="v">' . n(count($prefs)) . '<span style="font-size:14px">都道府県</span></div>'
       . '<div class="s">' . h(implode('・', array_slice(array_keys($prefs), 0, 6))) . (count($prefs) > 6 ? ' ほか' : '') . '</div></div>';
    echo '<div class="card' . (count($gones) ? ' lv2' : '') . '"><div class="k">公表データから消えた</div><div class="v">' . n(count($gones)) . '<span style="font-size:14px">か所</span></div>'
       . '<div class="s">' . (count($gones) ? '2024年12月末から2026年6月末までの1年半が ' . n($gone_after) . 'か所' : 'ありません') . '</div></div>';
    echo '</div>';
    if (count($nos)) {
        echo '<p class="src">法人番号: ' . h(implode('、', $nos))
           . (count($nos) > 1 ? '　<strong>この法人には公表データ上で複数の法人番号が記録されています</strong>（入力ゆれです）。' : '') . '</p>';
    } else {
        echo '<p class="src">法人番号は公表データに入っていません。</p>';
    }
    echo '</div>';

    if (count($prefs) > 1) {
        echo '<h2>都道府県別の内訳</h2><div class="panel"><div class="tscroll"><table class="t"><thead><tr><th>都道府県</th><th class="n">か所</th></tr></thead><tbody>';
        arsort($prefs);
        foreach ($prefs as $pp => $nn) {
            echo '<tr><td><a href="' . h($SELF . '/pref/' . rawurlencode($pp)) . '">' . h($pp) . '</a></td><td class="n">' . n($nn) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    if (count($gones)) {
        echo '<h2>公表データから消えた事業所</h2>';
        echo '<p class="lead">前の時点には公表されていて、' . h(tp_label($LATEST)) . '時点には載っていない事業所です。'
           . '<strong>消えた理由は公表されていません。</strong>'
           . '廃止したのか、指定が取り消されたのか、別の法人に引き継がれて番号が変わったのか、'
           . '登録が更新されなかっただけなのかは、この数字からは分かりません。</p>';
        if (count($offs) >= count($gones)) {
            echo '<p class="lead">この法人は、消えた' . n(count($gones)) . 'か所より多い<strong>' . n(count($offs)) . 'か所</strong>を'
               . 'いま公表しています。法人の再編などで事業所番号が付け替わると、古い番号は「消えた」に数えられます。'
               . '事業所が無くなったとは限りません。</p>';
        } else {
            echo '<p class="lead">この法人がいま公表しているのは<strong>' . n(count($offs)) . 'か所</strong>で、消えた'
               . n(count($gones)) . 'か所を下回ります。それでも、事業を畳んだのか、別の法人へ渡したのかは'
               . '公表データからは分かりません。</p>';
        }
        echo '<div class="tscroll"><table class="t"><thead><tr><th>最後に公表された時点</th><th>種別</th><th>事業所名</th><th>所在</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($gones as $g) {
            echo '<tr><td>' . h(tp_label($g['last_tp'])) . '</td><td>' . h(kl($g['kind'])) . '</td><td>' . h($g['name']) . '</td>'
               . '<td><a href="' . h($SELF . '/city/' . rawurlencode($g['pref']) . '/' . rawurlencode($g['city_key'])) . '">' . h($g['pref'] . $g['city']) . '</a></td></tr>';
            if (++$shown >= 300) { break; }
        }
        echo '</tbody></table></div>';
        if (count($gones) > 300) { echo '<p class="src">新しいものから300件まで表示しています。全件は <a href="' . h($SELF . '/data/gone.csv') . '">CSV</a> で取れます。</p>'; }
    }

    if (count($offs)) {
        echo '<h2>いま公表されている事業所</h2>';
        $cur = ''; $shown = 0;
        foreach ($offs as $r) {
            if ($r['kind'] !== $cur) { $cur = $r['kind']; echo '<h3>' . h(kl($cur)) . '（' . n($kc[$cur]) . 'か所）</h3>'; }
            office_card($r, false, false);
            if (++$shown >= 200) { break; }
        }
        if (count($offs) > 200) { echo '<p class="src">200件まで表示しています。全件は <a href="' . h($SELF . '/data/offices.csv') . '">CSV</a> で取れます。</p>'; }
    }
    echo '<p class="src">法人は法人番号ではなく正規化した法人名で束ねています。そのため<strong>同じ名前の別法人が混ざっていることがあります</strong>。'
       . '上の法人番号と所在地でご確認ください。</p>';
    echo '<p><a class="btn ghost" href="' . h($SELF . '/corps') . '">ほかの法人を見る</a></p>';
    foot_html();
    exit;
}

// ── 都道府県ページ ─────────────────────────────────────
if (preg_match('#^pref/([^/]+)$#', $path, $m)) {
    $pref = rawurldecode($m[1]);
    if (!in_array($pref, $PREFS, true)) {
        http_response_code(404);
        head_html('見つかりません｜' . $SITE, '指定された都道府県はありません。', '/');
        echo '<h1>見つかりません</h1>'; search_form(); foot_html(); exit;
    }
    $kc = array(); $total = 0;
    $st = $db->prepare('SELECT kind, count(*) n, sum(capacity) cap FROM offices WHERE pref=? GROUP BY kind');
    $st->execute(array($pref));
    foreach ($st as $r) { $kc[$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap']); $total += (int)$r['n']; }
    if (!$total) {
        http_response_code(404);
        head_html('見つかりません｜' . $SITE, '公表データにありません。', '/');
        echo '<h1>公表データにありません</h1>'; search_form(); foot_html(); exit;
    }
    $a = isset($kc['訪問介護']) ? $kc['訪問介護']['n'] : 0;
    $b = isset($kc['居宅介護支援']) ? $kc['居宅介護支援']['n'] : 0;
    $gone_n = 0; $gone_after = 0;
    $st = $db->prepare('SELECT last_tp, count(*) n FROM gone WHERE pref=? GROUP BY last_tp');
    $st->execute(array($pref));
    foreach ($st as $r) { $gone_n += (int)$r['n']; if ($r['last_tp'] >= '20250101') { $gone_after += (int)$r['n']; } }

    $title = $pref . 'の訪問介護・ケアマネ事業所一覧（市区町村別・' . n($a) . 'か所）';
    $desc = $pref . 'の訪問介護・ケアマネ事業所' . n($a) . 'か所、居宅介護支援' . n($b) . 'か所など事業所計' . n($total) . 'か所を市区町村別にまとめました。'
          . '住所・電話・運営法人つき。2024年12月末から2026年6月末までの1年半に公表データから消えた事業所は' . n($gone_after) . '件。' . tp_label($LATEST) . '時点。';
    head_html($title . '｜' . $SITE, $desc, '/pref/' . rawurlencode($pref));
    echo '<h1>' . h($pref) . 'の訪問介護・ケアマネ事業所</h1>';
    echo '<p class="lead">' . h(tp_label($LATEST)) . '時点で公表されている訪問介護と、居宅介護支援・定期巡回・随時対応型訪問介護看護などの事業所を市区町村別にまとめました。</p>';
    echo '<div class="panel"><div class="grid">';
    foreach ($KINDS as $k) {
        $v = isset($kc[$k]) ? $kc[$k] : null;
        echo '<div class="card' . ($v ? '' : ' none') . '"><div class="k">' . h(kl($k)) . '</div>';
        echo '<div class="v">' . ($v ? n($v['n']) . '<span style="font-size:14px">か所</span>' : '0') . '</div>';
        echo '<div class="s">' . ($v && $v['cap'] ? '定員 計' . n($v['cap']) . '人' : '定員という考え方がありません') . '</div></div>';
    }
    echo '</div></div>';

    echo '<h2>公表件数の推移</h2>';
    echo '<p class="lead">時点ごとの公表件数です。<strong>事業所数そのものではありません</strong>——自治体の登録が進んだぶんも混ざります。</p>';
    $ser = array();
    $st = $db->prepare('SELECT tp, kind, sum(n) n FROM counts WHERE pref=? GROUP BY tp, kind');
    $st->execute(array($pref));
    foreach ($st as $r) { $ser[$r['kind']][$r['tp']] = (int)$r['n']; }
    echo '<div class="panel"><div class="grid">';
    foreach ($KINDS as $k) {
        if (!isset($ser[$k])) { continue; }
        $first = null; $last = null;
        foreach ($TPS as $t) { if (isset($ser[$k][$t])) { if ($first === null) { $first = $ser[$k][$t]; } $last = $ser[$k][$t]; } }
        echo '<div class="card"><div class="k">' . h(kl($k)) . '</div>';
        bars($ser[$k], $TPS);
        echo '<div class="s">' . h(tp_label($TPS[0])) . ' ' . n($first) . ' → ' . h(tp_label($LATEST)) . ' ' . n($last) . '</div></div>';
    }
    echo '</div></div>';

    if ($gone_n) {
        echo '<h2>公表データから消えた事業所</h2>';
        echo '<p class="lead">' . h($pref) . 'では、これまでに<strong>' . n($gone_n) . '件</strong>が公表データから消えています'
           . '（うち2024年12月末から2026年6月末までの1年半が' . n($gone_after) . '件）。<strong>消えた理由は公表されていません。</strong></p>';
        echo '<div class="tscroll"><table class="t"><thead><tr><th>最後に公表された時点</th><th>種別</th><th>事業所名</th><th>運営法人</th><th>所在</th></tr></thead><tbody>';
        $st = $db->prepare('SELECT kind, name, city, corp, corp_key, last_tp FROM gone WHERE pref=? ORDER BY last_tp DESC, city, kind, name LIMIT 300');
        $st->execute(array($pref));
        foreach ($st as $g) {
            echo '<tr><td>' . h(tp_label($g['last_tp'])) . '</td><td>' . h(kl($g['kind'])) . '</td><td>' . h($g['name']) . '</td>'
               . '<td><a href="' . h($SELF . '/corp/' . rawurlencode($g['corp_key'])) . '">' . h($g['corp']) . '</a></td>'
               . '<td>' . h($g['city']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($gone_n > 300) { echo '<p class="src">新しいものから300件まで表示しています。全件は <a href="' . h($SELF . '/data/gone.csv') . '">CSV</a> で取れます。</p>'; }
    }

    echo '<h2>市区町村から選ぶ</h2><div class="panel"><div class="tscroll"><table class="t"><thead><tr><th>市区町村</th>';
    foreach ($KINDS as $k) { echo '<th class="n">' . h(kl($k)) . '</th>'; }
    echo '<th class="n">計</th></tr></thead><tbody>';
    $rows = array();
    $st = $db->prepare('SELECT city_key, kind, count(*) n FROM offices WHERE pref=? GROUP BY city_key, kind');
    $st->execute(array($pref));
    foreach ($st as $r) { $rows[$r['city_key']][$r['kind']] = (int)$r['n']; }
    uasort($rows, function ($x, $y) { return array_sum($y) - array_sum($x); });
    foreach ($rows as $ckey => $kk) {
        echo '<tr><td><a href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ckey)) . '">' . h($ckey) . '</a></td>';
        foreach ($KINDS as $k) { echo '<td class="n">' . (isset($kk[$k]) ? n($kk[$k]) : '—') . '</td>'; }
        echo '<td class="n">' . n(array_sum($kk)) . '</td></tr>';
    }
    echo '</tbody></table></div><p class="src">ケアマネ事業所＝居宅介護支援、定期巡回＝定期巡回・随時対応型訪問介護看護、夜間対応型＝夜間対応型訪問介護です。</p></div>';
    echo '<h2>住所から、近くの事業所を探す</h2>';
    search_form($pref);
    foot_html();
    exit;
}

// ── 市区町村ページ ─────────────────────────────────────
// /city/<県>/<市>[/<区>][/k/<種別>]
// **種別ごとのページを分ける。** 住民は「横浜市 特別養護老人ホーム」のように
// 市区町村＋種別で検索する（実測 月390）。1枚にまとめると、その語で戦うページが無くなる。
if (preg_match('#^city/([^/]+)/([^/]+?)(?:/(?!k/)([^/]+))?(?:/k/([^/]+))?$#', $path, $m)) {
    $pref = rawurldecode($m[1]); $ck = rawurldecode($m[2]);
    $ward = isset($m[3]) && $m[3] !== '' ? rawurldecode($m[3]) : '';
    $kind1 = isset($m[4]) && $m[4] !== '' ? rawurldecode($m[4]) : '';
    if ($kind1 !== '' && !in_array($kind1, $KINDS, true)) { $kind1 = ''; }
    $s = city_stats($db, $pref, $ck);
    $here = '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . ($ward ? '/' . rawurlencode($ward) : '')
          . ($kind1 ? '/k/' . rawurlencode($kind1) : '');
    if ($ward) {
        $chk = $db->prepare('SELECT count(*) FROM offices WHERE pref=? AND city_key=? AND city=?');
        $chk->execute(array($pref, $ck, $ward));
        if (!(int)$chk->fetchColumn()) { $ward = ''; $here = '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . ($kind1 ? '/k/' . rawurlencode($kind1) : ''); }
    }
    if (!$s['total'] && !count($s['gone'])) {
        http_response_code(404);
        head_html('見つかりません｜' . $SITE, '指定された市区町村は公表データにありません。', '/');
        echo '<h1>公表データにありません</h1><p class="lead">' . h($pref . $ck) . 'の訪問介護などの事業所は、いまの公表データに載っていません。</p>';
        search_form(); foot_html(); exit;
    }
    $where = 'pref=? AND city_key=?'; $args = array($pref, $ck);
    if ($ward) { $where .= ' AND city=?'; $args[] = $ward; }
    $where_all = $where; $args_all = $args;          // 種別の内訳は絞り込み前の数で出す
    if ($kind1) { $where .= ' AND kind=?'; $args[] = $kind1; }
    $place = $pref . ($ward ? $ward : $ck);

    // 種別ごとの件数。区が指定されていればその区だけ数える。
    $kc = array(); $total = 0; $capsum = 0;
    $st = $db->prepare("SELECT kind, count(*) n, sum(capacity) cap, sum(capacity IS NULL) nocap FROM offices WHERE $where_all GROUP BY kind");
    $st->execute($args_all);
    foreach ($st as $r) {
        $kc[$r['kind']] = array('n' => (int)$r['n'], 'cap' => (int)$r['cap'], 'nocap' => (int)$r['nocap']);
        $total += (int)$r['n']; $capsum += (int)$r['cap'];
    }

    $a = isset($kc['訪問介護']) ? $kc['訪問介護']['n'] : 0;
    $b = isset($kc['居宅介護支援']) ? $kc['居宅介護支援']['n'] : 0;
    if ($kind1) {
        $kn = kl($kind1);
        $n1 = isset($kc[$kind1]) ? $kc[$kind1]['n'] : 0;
        $cap1 = isset($kc[$kind1]) ? $kc[$kind1]['cap'] : 0;
        $total = $n1;
        $title = $place . 'の' . $kn . '一覧（' . n($n1) . 'か所・住所と運営法人つき）';
        $desc = $place . 'の' . $kn . '' . n($n1) . 'か所を、住所・電話・運営法人つきで一覧にしました。'
              . ($cap1 ? '定員は計' . n($cap1) . '人。' : '')
              . tp_label($LATEST) . '時点の厚生労働省の公表データ。空き状況は各事業所へ。';
    } else {
        $title = $place . 'の介護事業所一覧（訪問介護' . n($a) . 'か所ほか計' . n($total) . 'か所）';
        $desc = $place . 'の訪問介護' . n($a) . 'か所、ケアマネ事業所' . n($b) . 'か所、特別養護老人ホーム・デイサービスなど計' . n($total) . 'か所を、住所・電話・運営法人つきで一覧にしました。'
              . (!$ward && count($s['gone']) ? '公表データから消えた事業所' . n(count($s['gone'])) . '件も掲載。' : '')
              . tp_label($LATEST) . '時点の公表データ。空き状況は各事業所へ。';
    }
    // **下層ページに BreadcrumbList と ItemList を出す。** 検索から着地するのはここ。
    // 2026-09-24 まで、流入を狙う市区町村ページに構造化データが1つも無かった。
    $bc = array(array('@type' => 'ListItem', 'position' => 1, 'name' => $SITE, 'item' => 'https://kurage.exbridge.jp' . $SELF . '/'),
                array('@type' => 'ListItem', 'position' => 2, 'name' => $pref, 'item' => 'https://kurage.exbridge.jp' . $SELF . '/pref/' . rawurlencode($pref)),
                array('@type' => 'ListItem', 'position' => 3, 'name' => $ck, 'item' => 'https://kurage.exbridge.jp' . $SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck)));
    $pos = 4;
    if ($ward) { $bc[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => $ward, 'item' => 'https://kurage.exbridge.jp' . $SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . '/' . rawurlencode($ward)); }
    if ($kind1) { $bc[] = array('@type' => 'ListItem', 'position' => $pos++, 'name' => kl($kind1), 'item' => 'https://kurage.exbridge.jp' . $SELF . $here); }
    $ld = array(array('@type' => 'BreadcrumbList', 'itemListElement' => $bc));
    // 一覧の中身（先頭20件）。件数は total で正しく伝える
    $lst = $db->prepare("SELECT id, name, kind, addr FROM offices WHERE $where ORDER BY kind, name LIMIT 20");
    $lst->execute($args);
    $items = array(); $i = 1;
    foreach ($lst as $r) {
        $items[] = array('@type' => 'ListItem', 'position' => $i++, 'name' => $r['name'],
                         'url' => 'https://kurage.exbridge.jp' . $SELF . '/office/' . $r['id']);
    }
    if ($items) {
        $ld[] = array('@type' => 'ItemList',
                      'name' => $place . 'の' . ($kind1 ? kl($kind1) : '介護事業所'),
                      'numberOfItems' => $total, 'itemListOrder' => 'https://schema.org/ItemListUnordered',
                      'itemListElement' => $items);
    }
    head_html($title . '｜' . $SITE, $desc, $here, $ld);

    echo '<h1>' . h($place) . 'の' . ($kind1 ? h(kl($kind1)) : '介護事業所') . '</h1>';
    echo '<p class="lead">' . h(tp_label($LATEST)) . '時点で公表されている事業所です。<strong>空き状況は公表されていません</strong>——受けてもらえるかは事業所か地域包括支援センターに聞くしかありません。'
       . ($ward ? ' <a href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck)) . '">' . h($ck) . '全体を見る</a>' : '') . '</p>';
    echo '<div class="panel"><div class="grid">';
    $cbase = '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . ($ward ? '/' . rawurlencode($ward) : '');
    foreach ($KINDS as $k) {
        $v = isset($kc[$k]) ? $kc[$k] : null;
        $on = ($k === $kind1);
        $href = $SELF . $cbase . ($on ? '' : '/k/' . rawurlencode($k));
        $tag = $v ? 'a' : 'div';
        echo '<' . $tag . ' class="card' . ($v ? '' : ' none') . '"' . ($v ? ' href="' . h($href) . '" style="text-decoration:none;color:inherit' . ($on ? ';outline:2px solid var(--teal)' : '') . '"' : '') . '>';
        echo '<div class="k">' . h(kl($k)) . '</div>';
        echo '<div class="v">' . ($v ? n($v['n']) . '<span style="font-size:14px">か所</span>' : '0') . '</div>';
        echo '<div class="s">' . ($v && $v['cap'] ? '定員 計' . n($v['cap']) . '人' . ($v['nocap'] ? '（' . n($v['nocap']) . 'か所は定員の公表なし）' : '') : '定員という考え方がありません') . '</div></' . $tag . '>';
    }
    if ($kind1) { echo '<div class="card"><div class="k">すべての種別</div><div class="v" style="font-size:16px"><a href="' . h($SELF . $cbase) . '">' . h($place) . 'の全事業所</a></div></div>'; }
    echo '</div></div>';

    if (count($s['wards'])) {
        echo '<h2>区から選ぶ</h2><div class="panel"><p class="src" style="line-height:2.4">';
        foreach ($s['wards'] as $w) {
            $on = ($w['city'] === $ward);
            echo '<a href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . '/' . rawurlencode($w['city'])) . '"'
               . ($on ? ' style="font-weight:800"' : '') . '>' . h(str_replace($ck, '', $w['city'])) . '</a>（' . n($w['n']) . '）　';
        }
        echo '</p></div>';
    }

    if (!$ward) {
        echo '<h2>公表件数の推移</h2>';
        echo '<p class="lead">時点ごとの公表件数です。<strong>事業所数そのものではありません</strong>——自治体の登録が進んだぶんも混ざります。</p>';
        echo '<div class="panel"><div class="grid">';
        foreach ($KINDS as $k) {
            if (!isset($s['series'][$k])) { continue; }
            $ser = $s['series'][$k];
            $first = null; $last = null;
            foreach ($TPS as $t) { if (isset($ser[$t])) { if ($first === null) { $first = $ser[$t]; } $last = $ser[$t]; } }
            echo '<div class="card"><div class="k">' . h(kl($k)) . '</div>';
            bars($ser, $TPS);
            echo '<div class="s">' . h(tp_label($TPS[0])) . ' ' . n($first) . ' → ' . h(tp_label($LATEST)) . ' ' . n($last) . '</div></div>';
        }
        echo '</div></div>';

        echo '<h2>公表データから消えた事業所</h2>';
        if (count($s['gone'])) {
            $recent = 0;
            foreach ($s['gone'] as $g) { if ($g['last_tp'] >= '20250101') { $recent++; } }
            echo '<p class="lead">前の時点には載っていて、' . h(tp_label($LATEST)) . '時点には載っていない事業所です。'
               . '2024年12月末から2026年6月末までの1年半に消えたのは<strong>' . n($recent) . '件</strong>です。'
               . '<strong>消えた理由は公表されていません</strong>（廃止・指定の取消・登録の更新漏れなど、どれかは分かりません）。</p>';
            echo '<div class="tscroll"><table class="t"><thead><tr><th>最後に公表された時点</th><th>種別</th><th>事業所名</th><th>運営法人</th><th>所在</th></tr></thead><tbody>';
            foreach ($s['gone'] as $g) {
                echo '<tr><td>' . h(tp_label($g['last_tp'])) . '</td><td>' . h(kl($g['kind'])) . '</td><td>' . h($g['name']) . '</td>'
                   . '<td><a href="' . h($SELF . '/corp/' . rawurlencode($g['corp_key'])) . '">' . h($g['corp']) . '</a></td>'
                   . '<td>' . h($g['city']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '<p class="lead">' . h($pref . $ck) . 'では、公表データから消えた事業所はありません。</p>';
        }
    }

    // 一覧。1ページ50件で切る（政令市は700件を超えるので、1枚に出すとスマホで開けない）
    $per = 50;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pages = max(1, (int)ceil($total / $per));
    if ($page > $pages) { $page = $pages; }
    echo '<h2>事業所一覧</h2>';
    if ($pages > 1) { echo '<p class="lead">' . n($total) . 'か所のうち ' . n(($page - 1) * $per + 1) . '〜' . n(min($total, $page * $per)) . '件目（' . $page . '/' . $pages . 'ページ）</p>'; }
    $st = $db->prepare("SELECT * FROM offices WHERE $where ORDER BY kind, city, name LIMIT ? OFFSET ?");
    $st->execute(array_merge($args, array($per, ($page - 1) * $per)));
    $cur = '';
    foreach ($st as $r) {
        if ($r['kind'] !== $cur) { $cur = $r['kind']; echo '<h3>' . h(kl($cur)) . '</h3>'; }
        office_card($r, false);
    }
    if ($pages > 1) {
        echo '<p class="src" style="line-height:2.4">';
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === $page) { echo '<strong>' . $i . '</strong>　'; }
            else { echo '<a href="' . h($SELF . $here . '?page=' . $i) . '">' . $i . '</a>　'; }
        }
        echo '</p>';
    }
    echo '<h2>住所から、近くの事業所を探す</h2>';
    search_form($place);
    foot_html();
    exit;
}

// ── 公表データから消えた事業所（全国） ───────────────────
if ($path === 'gone') {
    $title = '訪問介護・ケアマネ事業所が公表データから消えた数の推移（全国・都道府県別・法人別）';
    $desc = '訪問介護・居宅介護支援・定期巡回・随時対応型訪問介護看護などについて、前の時点には公表されていて次の時点には載っていない事業所の数を、時点ごとに数えました。都道府県別と運営法人別の内訳、CSVつき。';
    head_html($title . '｜' . $SITE, $desc, '/gone');
    echo '<h1>公表データから消えた事業所</h1>';
    echo '<p class="lead">厚生労働省の公表データは時点ごとに出ます。ある時点に載っていた事業所番号が、次から載らなくなることがあります。'
       . 'ここではその数を数えています。<strong>消えた理由は公表されていません。</strong>廃止したのか、指定が取り消されたのか、'
       . '登録が更新されなかっただけなのかは、この数字からは分かりません。</p>';
    echo '<div class="tscroll"><table class="t"><thead><tr><th>最後に公表された時点</th>';
    foreach ($KINDS as $k) { echo '<th class="n">' . h(kl($k)) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($TPS as $t) {
        if ($t === $LATEST || !isset($NAT['gone'][$t])) { continue; }
        echo '<tr><td>' . h(tp_label($t)) . '</td>';
        foreach ($KINDS as $k) {
            $v = isset($NAT['gone'][$t][$k]) ? $NAT['gone'][$t][$k] : array('n' => 0, 'cap' => 0);
            echo '<td class="n">' . n($v['n']) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="src">「2024年12月末」の行は、2024年12月末時点を最後に公表データから消えた事業所の数です（2024年12月末から2026年6月末までの1年半に消えたもの）。時点が増えれば行も増えます。</p>';

    echo '<h2>都道府県別（2024年12月末から2026年6月末までの1年半に消えたもの）</h2>';
    $before = array(); $after = array(); $cap_after = array();
    $st = $db->query("SELECT pref, kind, last_tp, count(*) n, sum(capacity) cap FROM gone GROUP BY pref, kind, last_tp");
    foreach ($st as $r) {
        $p0 = $r['pref']; $k0 = $r['kind'];
        if ($r['last_tp'] >= '20250101') {
            $after[$p0][$k0] = (isset($after[$p0][$k0]) ? $after[$p0][$k0] : 0) + (int)$r['n'];
        } else {
            $before[$p0][$k0] = (isset($before[$p0][$k0]) ? $before[$p0][$k0] : 0) + (int)$r['n'];
        }
    }
    $rows = array();
    foreach ($after as $p => $kk) {
        $a = isset($kk['訪問介護']) ? $kk['訪問介護'] : 0;
        $b0 = isset($kk['居宅介護支援']) ? $kk['居宅介護支援'] : 0;
        $rows[] = array('pref' => $p, 'a_after' => $a, 'a_before' => $b0, 'all' => array_sum($kk));
    }
    usort($rows, function ($x, $y) { return $y['a_after'] - $x['a_after']; });
    echo '<div class="tscroll"><table class="t"><thead><tr><th>都道府県</th><th class="n">訪問介護</th><th class="n">ケアマネ事業所</th><th class="n">4種別の合計</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr><td><a href="' . h($SELF . '/pref/' . rawurlencode($r['pref'])) . '">' . h($r['pref']) . '</a></td><td class="n">' . n($r['a_after'])
           . '</td><td class="n">' . n($r['a_before']) . '</td><td class="n">' . n($r['all']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="src">4種別＝訪問介護・ケアマネ事業所（居宅介護支援）・定期巡回・随時対応型訪問介護看護・夜間対応型訪問介護。訪問介護の多い順。</p>';

    // ── ここがこの製品の眼目。**消えた事業所は、法人に偏る。** ────────────
    echo '<h2>運営法人ごとに見る（訪問介護が消えた数の多い順）</h2>';
    echo '<p class="lead">消えた事業所は、全国にまんべんなく散っているわけではありません。'
       . '同じ法人の訪問介護が、同じ時点にまとめて消えていることがあります。</p>';
    echo '<p class="lead"><strong>消えた数だけを見ないでください。</strong>右端の「いま公表されている訪問介護」と並べて読みます。'
       . '消えた数が多くても<strong>いまの数がそれ以上に多い法人</strong>は、法人の再編などで事業所番号が付け替わった可能性が高く、'
       . 'サービスが無くなったという話ではありません。逆に<strong>消えた数が多くていまの数が少ない法人</strong>は、実際に事業を畳んだか、'
       . '指定を取り消されたか、別の法人へ渡したかのどれかです。'
       . '<strong>どちらなのかは公表データには書かれていません。</strong>'
       . 'ここで分かるのは「この法人の名前で公表されていた事業所番号が、公表データから消えた」ということだけです。</p>';
    echo '<div class="tscroll"><table class="t"><thead><tr><th>運営法人</th><th class="n">消えた訪問介護</th><th class="n">いま公表されている訪問介護</th></tr></thead><tbody>';
    $q = $db->query("SELECT g.corp_key, max(g.corp) corp, count(*) n,
                            sum(CASE WHEN g.last_tp >= '20250101' THEN 1 ELSE 0 END) after_n
                     FROM gone g WHERE g.kind='訪問介護' AND g.corp_key <> ''
                     GROUP BY g.corp_key ORDER BY n DESC LIMIT 40");
    $now = $db->prepare("SELECT count(*) FROM offices WHERE kind='訪問介護' AND corp_key=?");
    foreach ($q as $r) {
        $now->execute(array($r['corp_key']));
        $live = (int)$now->fetchColumn();
        echo '<tr><td><a href="' . h($SELF . '/corp/' . rawurlencode($r['corp_key'])) . '">' . h($r['corp']) . '</a></td>'
           . '<td class="n">' . n($r['n']) . '</td>'
           . '<td class="n">' . ($live ? n($live) : '0') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="src">法人は<strong>法人番号ではなく正規化した法人名</strong>で束ねています。法人番号には入力ゆれがあり'
       . '（空欄が1,853件、同じ法人名に別の番号がついている例も多数）、番号で束ねると同じ法人がばらけて数え落とすためです。'
       . 'そのぶん同名の別法人が混ざることがあります。</p>';
    echo '<p><a class="btn ghost" href="' . h($SELF . '/data/gone.csv') . '">消えた事業所の一覧をCSVで取る</a></p>';
    echo '<h2>市区町村ごとに見る</h2>';
    search_form();
    foot_html();
    exit;
}

// ── データ ─────────────────────────────────────────────
if ($path === 'data') {
    head_html('訪問介護・ケアマネ事業所のデータをダウンロード（CSV・API）｜' . $SITE,
        '全国' . n($NAT['total']) . '事業所の一覧CSVと、住所から引けるJSON APIです。出典表示のうえ自由に使えます。', '/data');
    echo '<h1>データ</h1>';
    echo '<p class="lead">画面で見せているものと同じデータです。加工元が公開データなので、そのまま持ち出せる形で置いています。</p>';
    echo '<div class="panel"><h3>CSV</h3><ul class="plain">';
    echo '<li><a href="' . h($SELF . '/data/offices.csv') . '">offices.csv</a> — ' . n($NAT['total']) . '事業所（種別・住所・緯度経度・運営法人・利用できる曜日・連絡先）</li>';
    echo '<li><a href="' . h($SELF . '/data/gone.csv') . '">gone.csv</a> — 公表データから消えた事業所と、最後に公表された時点</li>';
    echo '</ul><h3>JSON API</h3>';
    echo '<p class="src">' . h($base) . '/api?q=<em>住所</em>&amp;kind=<em>種別</em>&amp;km=<em>範囲</em></p>';
    echo '<p><a class="btn ghost" href="' . h($SELF . '/api?q=' . rawurlencode('名古屋市中区三の丸3-1-1') . '&km=3') . '">試しに叩いてみる</a></p>';
    echo '<h3>出典表示</h3><p class="src">' . h($META['attribution']) . '</p></div>';
    echo '<div class="panel"><h3>収録している数</h3><div class="tscroll"><table class="t"><thead><tr><th>サービス種別</th><th class="n">事業所</th></tr></thead><tbody>';
    foreach ($KINDS as $k) {
        $v = isset($NAT['kinds'][$k]) ? $NAT['kinds'][$k] : array('n' => 0, 'cap' => 0);
        echo '<tr><td>' . h(kl($k)) . ($k !== kl($k) ? '<br><span style="font-size:11px;color:var(--mut)">' . h($k) . '</span>' : '')
           . '</td><td class="n">' . n($v['n']) . '</td></tr>';
    }
    echo '</tbody></table></div><p class="src"><strong>訪問介護とケアマネ事業所に定員はありません。</strong>'
       . 'CSVの定員列には0が入っていますが、0人という意味ではありません。</p></div>';
    foot_html();
    exit;
}

// ── このサイトについて ──────────────────────────────────
if ($path === 'about') {
    head_html('このサイトについて｜' . $SITE,
        'データの出どころ、分かること、分からないことを書いています。空き状況は公表されていないため扱いません。', '/about');
    echo '<h1>このサイトについて</h1>';
    echo '<div class="panel"><h3>何が分かるか</h3><ul class="plain">'
       . '<li>住所から、近くにある訪問介護・ケアマネ事業所（居宅介護支援）・定期巡回・随時対応型訪問介護看護・夜間対応型訪問介護（距離順）</li>'
       . '<li>それぞれの所在地・電話・利用できる時間・事業所のサイト・運営法人</li>'
       . '<li>市区町村ごとの公表件数の推移と、公表データから消えた事業所</li>'
       . '<li><strong>運営法人ごとに、いくつ持っていて、いくつ消えたか</strong>（同じ法人が全国に何か所持っているかをたどれます）</li>'
       . '</ul></div>';
    echo '<div class="panel"><h3>何が分からないか（ここが大事です）</h3><ul class="plain">'
       . '<li><strong>空き状況は分かりません。</strong>公表データに入っていないからです。受けてもらえるかは事業所か地域包括支援センターへお問い合わせください。</li>'
       . '<li><strong>定員はありません。</strong>訪問系とケアマネ事業所に定員という考え方が無いからです。CSVの「定員」列には0以外の値も入っていますが、定義書に説明が無く定員とは別の何かなので、読んでいません。</li>'
       . '<li><strong>利用料の実額は分かりません。</strong>公表データに無いからです。訪問介護で実際に払う額は事業所ごとに違います。</li>'
       . '<li><strong>消えた事業所が廃止したかどうかは分かりません。</strong>公表データから消えた、という事実だけを書いています。事業が別の法人に引き継がれて、番号だけが変わった場合も「消えた」に数えられます。</li>'
       . '<li><strong>公表件数が増えた＝事業所が増えた、ではありません。</strong>自治体の登録が進んだぶんが混ざります。だから全国の増減は「公表された件数」と書いています。</li>'
       . '<li><strong>ヘルパーやケアマネの人数・サービスの質は分かりません。</strong>公表データに無いからです。事業所にご確認ください。</li>'
       . '<li>利用には<strong>要介護・要支援の認定</strong>が要ります。まず市区町村の介護保険の窓口か地域包括支援センターにご相談ください。</li>'
       . '<li>高齢者の<strong>訪問看護は含みません（別の道具 khokan にあります）</strong>。あれは別の制度で、データも別です。</li>'
       . '</ul></div>';
    echo '<div class="panel"><h3>法人をどう束ねているか</h3>'
       . '<p>法人ごとのページは、<strong>法人番号ではなく正規化した法人名</strong>で束ねています。'
       . '公表データの法人番号には空欄が1,853件あり、同じ法人名に別の番号がついている例も多数あります。'
       . '番号で束ねると同じ法人がばらけて、消えた事業所を数え落とします。</p>'
       . '<p class="src">そのぶん、同じ名前の別法人（社会福祉法人◯◯会が複数の県に実在する場合など）が混ざることがあります。'
       . '法人ページには法人番号と所在地を併記しているので、そこで見分けてください。</p></div>';
    echo '<div class="panel"><h3>データの出どころ</h3>'
       . '<p>厚生労働省（独立行政法人福祉医療機構）が公開している<a href="' . h($META['source_url']) . '" rel="nofollow">介護サービス情報公表システムのオープンデータ</a>です。'
       . '営利・非営利を問わず二次利用できると明記された公開データで、毎年6月末・12月末時点で公開されます。'
       . 'このサイトは' . h(tp_label($LATEST)) . '時点のものを使っています（取り込み ' . h($META['built_at']) . '）。</p>'
       . '<p class="src">' . h($META['attribution']) . '</p></div>';
    echo '<div class="panel"><h3>同じ仕組みを自分のところで動かす</h3>'
       . '<p>事務所・自治体・会社の名前で公開できるオンプレミス版をソースコード同梱で出しています。'
       . 'PHPが動くレンタルサーバーにファイルを置くだけで動き、判定は置いた場所で完結します（外部のAIやAPIには出しません）。</p>'
       . '<p><a class="btn" href="https://kappstore.exbridge.jp/app.php?id=57aebd041b7bab37&amp;ref=kkaigo-about">商品ページを見る</a></p></div>';
    foot_html();
    exit;
}

// ── トップ（住所から探す） ──────────────────────────────
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$kind = isset($_GET['kind']) && in_array($_GET['kind'], $KINDS, true) ? $_GET['kind'] : '';
$km = isset($_GET['km']) ? max(1, min(50, (int)$_GET['km'])) : 5;

$g = null; $pref = null; $city = null; $ck = null; $list = array(); $cs = null;
if ($q !== '') {
    $g = geocode($q, $GSI);
    list($pref, $city) = split_address($g['title']);
    if ($city) { $ck = preg_replace('/(.+?市).+区$/u', '$1', $city); }
    if ($g['ok']) { $list = nearby($db, $g['lat'], $g['lon'], $km, $kind); }
    if ($pref && $ck) { $cs = city_stats($db, $pref, $ck); }
}

$na = $NAT['kinds']['訪問介護']['n'];
$nb = $NAT['kinds']['居宅介護支援']['n'];
$ward_here = ($q !== '' && $g) ? ward_of($g['title'], $ck) : '';
$place_here = $pref . ($ward_here ? $ward_here : $ck);
if ($q !== '' && $pref && $ck) {
    $title = $place_here . 'の訪問介護・ケアマネ事業所を住所から探す（近い順）';
    $desc = $place_here . 'の近くにある訪問介護・ケアマネ事業所（居宅介護支援）・定期巡回・随時対応型訪問介護看護を、距離順に運営法人つきで表示しました。'
          . tp_label($LATEST) . '時点の公表データ。空き状況は各事業所へお問い合わせください。';
} else {
    // **題名は扱っている範囲に合わせる。** 2026-09-24 に17種別へ広げたのに
    // 「訪問介護・ケアマネ」のままだと、特養・デイサービスを探す人には無関係に見える。
    $title = '特養・デイサービス・訪問介護を住所から探す｜全国' . n($NAT['total'] ?? 0) . 'か所';
    $desc = '住所を入れると、近くの訪問介護' . n($na) . 'か所・ケアマネ事業所（居宅介護支援）' . n($nb) . 'か所・定期巡回・随時対応型訪問介護看護を距離順に表示します。'
          . '市区町村ごとの公表件数の推移、公表データから消えた事業所、運営法人ごとの事業所数も見られます。国のオープンデータのみ使用。';
}
head_html($title . '｜' . $SITE, $desc, '/');

echo '<h1>' . ($q !== '' ? h(($pref && $ck) ? $place_here . 'の介護事業所' : '検索結果') : '介護事業所を住所から探す（特養・デイサービス・訪問介護ほか17種別）') . '</h1>';
if ($q === '') {
    echo '<p class="lead">住所を入れると、近くにある訪問介護・ケアマネ事業所（居宅介護支援）・定期巡回・随時対応型訪問介護看護を近い順に出します。'
       . '国が公開しているデータだけを使っています。<strong>空き状況は公表されていないので扱いません。</strong>'
       . '受けてもらえるかは事業所か地域包括支援センターに聞くしかありません。利用には要介護・要支援の認定が要ります。</p>';
}
echo '<div class="panel">';
search_form($q, $kind, $km);
echo '</div>';

if ($q !== '') {
    if (!$g['ok']) {
        echo '<div class="panel"><p><strong>住所から場所を特定できませんでした。</strong>'
           . '国土地理院の住所検索が応答しなかったか、住所の表記が見つかりませんでした。'
           . '「発表されていない」という意味ではありません。市区町村名だけ（例: 名古屋市中区）でもう一度お試しください。</p></div>';
    } else {
        echo '<p class="lead">' . h($g['title']) . ' から ' . (int)$km . 'km 以内'
           . ($kind ? '／' . h($kind) : '') . '：<strong>' . n(count($list)) . '件</strong>'
           . (count($list) >= 40 ? '（近い順に40件まで）' : '') . '</p>';
        if (!count($list)) {
            echo '<div class="panel"><p>この範囲には、公表データに載っている事業所がありません。範囲を広げるか、種別の指定を外してお試しください。</p></div>';
        }
        foreach ($list as $r) { office_card($r); }
    }
    if ($cs) {
        echo '<h2>' . h($pref . $ck) . 'の状況</h2>';
        echo '<div class="panel"><div class="grid">';
        foreach ($KINDS as $k) {
            $v = isset($cs['kinds'][$k]) ? $cs['kinds'][$k] : null;
            echo '<div class="card' . ($v ? '' : ' none') . '"><div class="k">' . h(kl($k)) . '</div>';
            echo '<div class="v">' . ($v ? n($v['n']) . '<span style="font-size:14px">か所</span>' : '0') . '</div>';
            echo '<div class="s">' . ($v && $v['cap'] ? '定員 計' . n($v['cap']) . '人' : '定員という考え方がありません') . '</div></div>';
        }
        echo '</div>';
        if (count($cs['gone'])) {
            $recent = 0;
            foreach ($cs['gone'] as $gg) { if ($gg['last_tp'] >= '20250101') { $recent++; } }
            echo '<p style="margin:14px 0 0">' . h($pref . $ck) . 'では、2024年12月末から2026年6月末までの1年半に<strong>' . n($recent) . '件</strong>が公表データから消えています'
               . '（全期間で' . n(count($cs['gone'])) . '件）。理由は公表されていません。</p>';
        }
        echo '<p style="margin:10px 0 0">';
        if ($ward_here) {
            echo '<a class="btn ghost" href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck) . '/' . rawurlencode($ward_here)) . '">'
               . h($ward_here) . 'の事業所一覧</a> ';
        }
        echo '<a class="btn ghost" href="' . h($SELF . '/city/' . rawurlencode($pref) . '/' . rawurlencode($ck)) . '">'
           . h($pref . $ck) . 'の一覧・推移・消えた事業所を見る</a></p>';
        echo '</div>';
    } elseif ($pref) {
        echo '<p class="lead">' . h($pref) . 'までは分かりましたが、市区町村を特定できませんでした。市区町村名を入れてお試しください。</p>';
    }
} else {
    echo '<h2>全国でいま公表されている数</h2>';
    echo '<div class="panel"><div class="grid">';
    foreach ($KINDS as $k) {
        $v = $NAT['kinds'][$k];
        echo '<div class="card"><div class="k">' . h(kl($k)) . '</div><div class="v">' . n($v['n']) . '<span style="font-size:14px">か所</span></div>';
        echo '<div class="s">' . ($v['cap'] ? '定員 計' . n($v['cap']) . '人' : '定員という考え方がありません') . '</div></div>';
    }
    echo '</div><p class="src">' . h(tp_label($LATEST)) . '時点。<strong>訪問介護とケアマネ事業所に定員という考え方はありません</strong>。'
       . '</p></div>';

    $sum_after = 0; $sum_before = 0; $cap_after = 0;
    foreach ($TPS as $t) {
        if ($t === $LATEST || !isset($NAT['gone'][$t]['訪問介護'])) { continue; }
        $v = $NAT['gone'][$t]['訪問介護'];
        if ($t >= '20250101') { $sum_after += $v['n']; $cap_after += $v['cap']; } else { $sum_before += $v['n']; }
    }
    // この分野の眼目は「訪問介護もケアマネ事業所も、入れ替わりが激しい」こと。
    // 差し引きの増減だけ見ると小さく見えるが、消えた数は両方とも3,000を超える。**必ず並べて出す。**
    $first_tp = $TPS[0];
    $h1 = isset($NAT['series']['訪問介護'][$first_tp]) ? $NAT['series']['訪問介護'][$first_tp] : 0;
    $h2 = isset($NAT['series']['訪問介護'][$LATEST]) ? $NAT['series']['訪問介護'][$LATEST] : 0;
    $c1 = isset($NAT['series']['居宅介護支援'][$first_tp]) ? $NAT['series']['居宅介護支援'][$first_tp] : 0;
    $c2 = isset($NAT['series']['居宅介護支援'][$LATEST]) ? $NAT['series']['居宅介護支援'][$LATEST] : 0;
    $gone_h = 0; $gone_c = 0;
    foreach ($TPS as $t) {
        if ($t === $LATEST) { continue; }
        if (isset($NAT['gone'][$t]['訪問介護'])) { $gone_h += $NAT['gone'][$t]['訪問介護']['n']; }
        if (isset($NAT['gone'][$t]['居宅介護支援'])) { $gone_c += $NAT['gone'][$t]['居宅介護支援']['n']; }
    }
    $zero = !empty($META['zero_json']) ? json_decode($META['zero_json'], true) : array();
    $zero_c = isset($zero['居宅介護支援']) ? (int)$zero['居宅介護支援'] : 0;
    $zero_h = isset($zero['訪問介護']) ? (int)$zero['訪問介護'] : 0;
    echo '<h2>' . h(tp_label($first_tp)) . 'から' . h(tp_label($LATEST)) . 'の1年半で、どう動いたか</h2>';
    echo '<div class="panel">';
    if ($h1 && $h2 && $c1 && $c2) {
        echo '<div class="grid">';
        echo '<div class="card"><div class="k">訪問介護</div><div class="v">' . ($h2 - $h1 >= 0 ? '+' : '') . n($h2 - $h1) . '<span style="font-size:14px">か所</span></div>'
           . '<div class="s">' . n($h1) . ' → ' . n($h2) . '。ただし消えたのは' . n($gone_h) . 'か所</div></div>';
        echo '<div class="card"><div class="k">ケアマネ事業所（居宅介護支援）</div><div class="v">' . ($c2 - $c1 >= 0 ? '+' : '') . n($c2 - $c1) . '<span style="font-size:14px">か所</span></div>'
           . '<div class="s">' . n($c1) . ' → ' . n($c2) . '。ただし消えたのは' . n($gone_c) . 'か所</div></div>';
        echo '<div class="card lv2"><div class="k">訪問介護が1か所も無い市区町村</div><div class="v">' . n($zero_h) . '<span style="font-size:14px">市区町村</span></div>'
           . '<div class="s">ケアマネ事業所が無いのは ' . n($zero_c) . ' 市区町村</div></div>';
        echo '</div>';
        echo '<p style="margin:12px 0 0"><strong>差し引きだけを見ると、この分野の動きは分かりません。</strong>'
           . '訪問介護は差し引き' . ($h2 - $h1 >= 0 ? '＋' : '') . n($h2 - $h1) . 'か所ですが、同じ1年半に<strong>' . n($gone_h) . 'か所</strong>が公表データから消え、それ以上の数が新しく載りました。'
           . 'ケアマネ事業所は差し引き' . ($c2 - $c1 >= 0 ? '＋' : '') . n($c2 - $c1) . 'か所で、消えたのは<strong>' . n($gone_c) . 'か所</strong>です。'
           . '入れ替わりの激しさは、市区町村ごとに見ないと分かりません。</p>';
    }
    echo '<p class="src"><strong>公表件数が増えた＝事業所が増えた、ではありません。</strong>登録の更新が混ざります。'
       . 'また、消えた理由は公表されていないため「廃止した」とは書きません。休止・法人の再編での番号の付け替えも「消えた」に入ります。'
       . '時点は2つしかありません（' . h(tp_label($first_tp)) . '・' . h(tp_label($LATEST)) . '）。厚労省の公開が2時点ぶんのためです。</p>';
    echo '<p><a class="btn ghost" href="' . h($SELF . '/gone') . '">都道府県別・法人別に見る</a></p>';
    echo '</div>';

    // 法人の集中。1法人あたり平均1.5か所の世界に、300か所超の法人がいる。
    echo '<h2>訪問介護を運営している法人</h2>';
    $ncorp = !empty($META['ncorp_main']) ? (int)$META['ncorp_main'] : (int)$db->query("SELECT count(DISTINCT corp_key) FROM offices WHERE kind='訪問介護' AND corp_key <> ''")->fetchColumn();
    echo '<div class="panel">';
    echo '<p class="lead">全国' . n($na) . 'か所の訪問介護を、<strong>' . n($ncorp) . '法人</strong>が運営しています'
       . '（1法人あたり平均' . number_format($na / max(1, $ncorp), 2) . 'か所）。'
       . 'ほとんどは1〜2か所の小さな法人ですが、上位には全国に数百か所を持つ法人がいます。</p>';
    echo '<div class="tscroll"><table class="t"><thead><tr><th>運営法人</th><th class="n">訪問介護</th><th class="n">都道府県</th></tr></thead><tbody>';
    $top = !empty($META['top_corps_json']) ? json_decode($META['top_corps_json'], true) : null;
    if ($top === null) { $top = $db->query("SELECT corp_key, max(corp) corp, count(*) n, count(DISTINCT pref) np FROM offices WHERE kind='訪問介護' AND corp_key <> '' GROUP BY corp_key ORDER BY n DESC LIMIT 20")->fetchAll(); }
    foreach ($top as $r) {
        echo '<tr><td><a href="' . h($SELF . '/corp/' . rawurlencode($r['corp_key'])) . '">' . h($r['corp']) . '</a></td>'
           . '<td class="n">' . n($r['n']) . '</td><td class="n">' . n($r['np']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="src">法人は法人番号ではなく正規化した法人名で束ねています（番号に入力ゆれがあるため。詳しくは'
       . '<a href="' . h($SELF . '/about') . '">このサイトについて</a>）。</p></div>';

    echo '<h2>都道府県から探す</h2><div class="panel"><p class="src" style="line-height:2.4">';
    $cnt = array();
    if (!empty($META['pref_counts_json'])) { foreach (json_decode($META['pref_counts_json'], true) as $pp => $nn) { $cnt[$pp] = (int)$nn; } }
    else { foreach ($db->query('SELECT pref, count(*) n FROM offices GROUP BY pref') as $r) { $cnt[$r['pref']] = (int)$r['n']; } }
    foreach ($PREFS as $pp) {
        if (!isset($cnt[$pp])) { continue; }
        echo '<a href="' . h($SELF . '/pref/' . rawurlencode($pp)) . '">' . h($pp) . '</a>（' . n($cnt[$pp]) . '）　';
    }
    echo '</p></div>';
}
foot_html();
