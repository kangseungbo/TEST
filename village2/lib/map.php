<?php
// 육각 세계 맵: 축좌표 (q, r), 뾰족한 위쪽(pointy-top) 배치.
// - 시드 기반 자동 생성(값 노이즈로 높이·습도 → 지형, 산에서 내려가는 강, 자원 거점) + 관리자 수동 편집
// - 마을은 서로 village_min_distance 이상 떨어진 평원에 놓는다
// - A* 길찾기: 통행 불가 지형(강은 다리 있으면 통행), 남의 마을 타일은 지날 수 없음(5단계에서 공격)

const VG_HEX_DIRS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

function vg_hex_dist(int $q1, int $r1, int $q2, int $r2): int
{
    return intdiv(abs($q1 - $q2) + abs($q1 + $r1 - $q2 - $r2) + abs($r1 - $r2), 2);
}

function vg_tkey(int $q, int $r): string
{
    return $q . ',' . $r;
}

function vg_tdefs(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = [];
    foreach (vg_db()->query('SELECT * FROM vg_terrain_defs ORDER BY sort_order, code') as $d) {
        foreach (['move_cost', 'def_bonus_pct', 'cav_bonus_pct'] as $k) $d[$k] = (float)$d[$k];
        $d['passable'] = (int)$d['passable'];
        $cache[$d['code']] = $d;
    }
    return $cache;
}

/** 전체 타일 "q,r" => ['q','r','t','f','v'] (요청당 한 번 읽음) */
function vg_map_tiles(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = [];
    foreach (vg_db()->query('SELECT q, r, terrain, feature, village_id FROM vg_map_tiles') as $t) {
        $cache[$t['q'] . ',' . $t['r']] = ['q' => (int)$t['q'], 'r' => (int)$t['r'], 't' => $t['terrain'], 'f' => $t['feature'],
            'v' => $t['village_id'] === null ? null : (int)$t['village_id']];
    }
    return $cache;
}

function vg_map_bump(): void
{
    $pdo = vg_db();
    vg_meta_set($pdo, 'map_version', (string)((int)vg_meta_get($pdo, 'map_version') + 1));
}

// ───────────── 생성 ─────────────

/** 시드 고정 난수 (mulberry32) */
function vg_rng(int $seed): Closure
{
    $a = $seed & 0xFFFFFFFF;
    return function () use (&$a): float {
        $a = ($a + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $a;
        $t = (($t ^ ($t >> 15)) * (1 | $t)) & 0xFFFFFFFF;
        $t = ($t ^ ($t + ((($t ^ ($t >> 7)) * (61 | $t)) & 0xFFFFFFFF))) & 0xFFFFFFFF;
        return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
    };
}

/** 격자점 해시 → 0~1 */
function vg_hash2(int $x, int $y, int $seed): float
{
    $h = ($x * 374761393 + $y * 668265263 + $seed * 144665) & 0xFFFFFFFF;
    $h = (($h ^ ($h >> 13)) * 1274126177) & 0xFFFFFFFF;
    return (($h ^ ($h >> 16)) & 0xFFFFFF) / 16777216;
}

function vg_value_noise(float $x, float $y, int $seed): float
{
    $x0 = (int)floor($x); $y0 = (int)floor($y);
    $fx = $x - $x0; $fy = $y - $y0;
    $sx = $fx * $fx * (3 - 2 * $fx); $sy = $fy * $fy * (3 - 2 * $fy);
    $a = vg_hash2($x0, $y0, $seed); $b = vg_hash2($x0 + 1, $y0, $seed);
    $c = vg_hash2($x0, $y0 + 1, $seed); $d = vg_hash2($x0 + 1, $y0 + 1, $seed);
    return ($a + ($b - $a) * $sx) + (($c + ($d - $c) * $sx) - ($a + ($b - $a) * $sx)) * $sy;
}

function vg_fbm(float $x, float $y, int $seed): float
{
    return (vg_value_noise($x, $y, $seed) * 0.65 + vg_value_noise($x * 2.1, $y * 2.1, $seed + 7) * 0.35);
}

/** 타일 배열 생성 (DB 쓰기 없음) */
function vg_map_build(int $seed, int $R): array
{
    $rng = vg_rng($seed);
    $tiles = [];
    $elev = [];
    for ($q = -$R; $q <= $R; $q++) {
        for ($r = max(-$R, -$q - $R); $r <= min($R, -$q + $R); $r++) {
            $x = $q + $r / 2; $y = $r * 0.866;
            $e = vg_fbm($x / 6.5 + 50, $y / 6.5 + 50, $seed);
            $m = vg_fbm($x / 4.5 + 200, $y / 4.5 + 200, $seed + 99);
            // 가운데로 갈수록 중간 높이로 모아 평지를 늘림 (마을 자리)
            $cd = vg_hex_dist(0, 0, $q, $r) / max(1, $R);
            $e = 0.45 + ($e - 0.45) * (0.55 + 0.45 * $cd);
            $t = $e > 0.68 ? 'mountain' : ($e < 0.24 ? 'lake' : ($m > 0.60 ? 'forest' : 'plain'));
            $k = vg_tkey($q, $r);
            $tiles[$k] = ['q' => $q, 'r' => $r, 't' => $t, 'f' => '', 'v' => null];
            $elev[$k] = $e;
        }
    }
    // 강: 산에서 시작해 낮은 쪽으로 흐름
    $mountains = array_keys(array_filter($tiles, fn($t) => $t['t'] === 'mountain'));
    $rivers = min((int)S('map_rivers'), count($mountains));
    for ($i = 0; $i < $rivers; $i++) {
        $k = $mountains[(int)floor($rng() * count($mountains))];
        $seen = [];
        for ($step = 0; $step < 3 * $R; $step++) {
            $seen[$k] = true;
            $t = $tiles[$k];
            if ($t['t'] === 'lake') break;
            if ($t['t'] !== 'mountain') $tiles[$k]['t'] = 'river';
            $best = null; $bestE = INF;
            foreach (VG_HEX_DIRS as [$dq, $dr]) {
                $nk = vg_tkey($t['q'] + $dq, $t['r'] + $dr);
                if (!isset($tiles[$nk]) || isset($seen[$nk])) continue;
                $ne = $elev[$nk] + $rng() * 0.08;
                if ($ne < $bestE) { $bestE = $ne; $best = $nk; }
            }
            if ($best === null) break;
            $k = $best;
        }
    }
    // 자원 거점
    $place = function (string $feature, int $n, callable $ok) use (&$tiles, $rng) {
        $cands = array_keys(array_filter($tiles, fn($t) => $t['f'] === '' && $ok($t)));
        for ($i = 0; $i < $n && $cands; $i++) {
            $idx = (int)floor($rng() * count($cands));
            $k = $cands[$idx];
            $t = $tiles[$k];
            $tiles[$k]['f'] = $feature;
            // 거점끼리 3칸 이상 떨어뜨림
            $cands = array_values(array_filter($cands, function ($c) use ($tiles, $t) {
                return vg_hex_dist($tiles[$c]['q'], $tiles[$c]['r'], $t['q'], $t['r']) >= 3;
            }));
        }
    };
    $near = function (array $t, string $terrain) use (&$tiles): bool {
        foreach (VG_HEX_DIRS as [$dq, $dr]) {
            $n = $tiles[vg_tkey($t['q'] + $dq, $t['r'] + $dr)] ?? null;
            if ($n && $n['t'] === $terrain) return true;
        }
        return false;
    };
    $place('mine', (int)S('map_mines'), fn($t) => in_array($t['t'], ['plain', 'forest'], true) && $near($t, 'mountain'));
    $place('port', (int)S('map_ports'), fn($t) => $t['t'] === 'plain' && $near($t, 'lake'));
    $place('farm', (int)S('map_farms'), fn($t) => $t['t'] === 'plain' && !$near($t, 'river'));
    return $tiles;
}

/** 맵을 새로 만들어 저장. 모든 부대는 즉시 귀환, 마을은 다시 배치 */
function vg_map_generate(int $seed, int $R): int
{
    if ($seed <= 0) $seed = random_int(1, 2000000000);
    $R = max(6, min(40, $R));
    $tiles = vg_map_build($seed, $R);
    $pdo = vg_db();
    $pdo->query("SELECT GET_LOCK('village2_map', 30)")->fetchColumn();
    try {
        vg_armies_all_home();
        $pdo->exec('DELETE FROM vg_map_tiles');
        $rows = array_values($tiles);
        foreach (array_chunk($rows, 400) as $chunk) {
            $sql = 'INSERT INTO vg_map_tiles (q, r, terrain, feature) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $args = [];
            foreach ($chunk as $t) array_push($args, $t['q'], $t['r'], $t['t'], $t['f']);
            $pdo->prepare($sql)->execute($args);
        }
        $pdo->exec('UPDATE vg_villages SET q = NULL, r = NULL');
        vg_meta_set($pdo, 'map_seed', (string)$seed);
        vg_meta_set($pdo, 'map_radius', (string)$R);
        vg_map_tiles(true);
        foreach ($pdo->query('SELECT id FROM vg_villages ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $vid) vg_map_place_village_nolock((int)$vid);
        vg_map_bump();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('village2_map')")->fetchColumn();
    }
    return $seed;
}

/** 맵이 없으면 만들고, 자리 없는 마을을 배치 */
function vg_map_ensure(): void
{
    $pdo = vg_db();
    if (!(int)$pdo->query('SELECT COUNT(*) FROM vg_map_tiles')->fetchColumn()) {
        vg_map_generate((int)S('map_seed'), (int)S('map_radius'));
        return;
    }
    $ids = $pdo->query('SELECT id FROM vg_villages WHERE q IS NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return;
    $pdo->query("SELECT GET_LOCK('village2_map', 30)")->fetchColumn();
    try {
        vg_map_tiles(true);
        foreach ($ids as $vid) vg_map_place_village_nolock((int)$vid);
        vg_map_bump();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('village2_map')")->fetchColumn();
    }
}

/** 마을 자리 고르기 (village2_map 잠금 안에서) */
function vg_map_place_village_nolock(int $vid, ?array $at = null): void
{
    $pdo = vg_db();
    $st = $pdo->prepare('SELECT q, r FROM vg_villages WHERE id = ?');
    $st->execute([$vid]);
    $cur = $st->fetch();
    if (!$cur) return;
    if ($at === null && $cur['q'] !== null) return;
    $tiles = vg_map_tiles(true);
    $others = [];
    foreach ($tiles as $t) if ($t['v'] !== null && $t['v'] !== $vid) $others[] = $t;
    $choice = null;
    if ($at !== null) {
        $choice = $tiles[vg_tkey($at[0], $at[1])] ?? null;
        if (!$choice) throw new VgError('맵 밖입니다.');
        if ($choice['v'] !== null && $choice['v'] !== $vid) throw new VgError('다른 마을이 있는 자리입니다.');
    } else {
        $feats = array_filter($tiles, fn($t) => $t['f'] !== '' && $t['f'] !== 'village');
        $cands = array_filter($tiles, fn($t) => $t['t'] === 'plain' && $t['f'] === '' && $t['v'] === null);
        for ($min = max(1, (int)S('village_min_distance')); $min >= 1 && !$choice; $min--) {
            $ok = array_values(array_filter($cands, function ($t) use ($others, $feats, $min) {
                foreach ($others as $o) if (vg_hex_dist($t['q'], $t['r'], $o['q'], $o['r']) < $min) return false;
                foreach ($feats as $f) if (vg_hex_dist($t['q'], $t['r'], $f['q'], $f['r']) < 2) return false;
                return true;
            }));
            if ($ok) {
                // 가운데에 가까운 자리를 조금 더 선호
                usort($ok, fn($a, $b) => vg_hex_dist(0, 0, $a['q'], $a['r']) <=> vg_hex_dist(0, 0, $b['q'], $b['r']));
                $pick = array_slice($ok, 0, max(1, (int)ceil(count($ok) * 0.5)));
                $choice = $pick[random_int(0, count($pick) - 1)];
            }
        }
        if (!$choice) throw new VgError('마을을 놓을 자리가 없습니다. 관리자가 맵을 넓혀야 합니다.');
    }
    $pdo->prepare("UPDATE vg_map_tiles SET village_id = NULL, feature = '' WHERE village_id = ?")->execute([$vid]);
    $pdo->prepare("UPDATE vg_map_tiles SET village_id = ?, feature = 'village', terrain = 'plain' WHERE q = ? AND r = ?")
        ->execute([$vid, $choice['q'], $choice['r']]);
    $pdo->prepare('UPDATE vg_villages SET q = ?, r = ? WHERE id = ?')->execute([$choice['q'], $choice['r'], $vid]);
    vg_map_tiles(true);
}

/** 관리자: 마을 위치 옮기기 */
function vg_map_move_village(int $vid, int $q, int $r): void
{
    $pdo = vg_db();
    $pdo->query("SELECT GET_LOCK('village2_map', 30)")->fetchColumn();
    try {
        vg_map_place_village_nolock($vid, [$q, $r]);
        vg_map_bump();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('village2_map')")->fetchColumn();
    }
}

// ───────────── 길찾기 ─────────────

function vg_tile_passable(?array $t, int $ownVid): bool
{
    if (!$t) return false;
    if ($t['v'] !== null && $t['v'] !== $ownVid) return false;
    $d = vg_tdefs()[$t['t']] ?? null;
    if (!$d) return false;
    return $d['passable'] || $t['f'] === 'bridge';
}

/** 그 타일에 들어갈 때 이동 비용 */
function vg_tile_cost(array $t): float
{
    if ($t['f'] === 'bridge') return max(0.1, (float)S('bridge_move_cost'));
    return max(0.1, (float)(vg_tdefs()[$t['t']]['move_cost'] ?? 1));
}

/** A* — 반환: [[q, r], ...] (출발 타일 포함) 또는 null */
function vg_astar(int $q0, int $r0, int $q1, int $r1, int $ownVid): ?array
{
    $tiles = vg_map_tiles();
    $start = vg_tkey($q0, $r0);
    $goal = vg_tkey($q1, $r1);
    if (!isset($tiles[$start]) || !vg_tile_passable($tiles[$goal] ?? null, $ownVid)) return null;
    if ($start === $goal) return [[$q0, $r0]];
    // 휴리스틱: 남은 거리 × 가장 싼 이동 비용 (과대평가하지 않게)
    $minCost = max(0.1, (float)S('bridge_move_cost'));
    foreach (vg_tdefs() as $d) if ($d['passable']) $minCost = min($minCost, max(0.1, $d['move_cost']));
    $open = new SplPriorityQueue();
    $open->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    $g = [$start => 0.0];
    $came = [];
    $open->insert($start, 0);
    $closed = [];
    while (!$open->isEmpty()) {
        $k = $open->extract();
        if (isset($closed[$k])) continue;
        if ($k === $goal) break;
        $closed[$k] = true;
        $t = $tiles[$k];
        foreach (VG_HEX_DIRS as [$dq, $dr]) {
            $nk = vg_tkey($t['q'] + $dq, $t['r'] + $dr);
            $n = $tiles[$nk] ?? null;
            if (!$n || isset($closed[$nk]) || !vg_tile_passable($n, $ownVid)) continue;
            $ng = $g[$k] + vg_tile_cost($n);
            if ($ng < ($g[$nk] ?? INF) - 1e-9) {
                $g[$nk] = $ng;
                $came[$nk] = $k;
                $h = vg_hex_dist($n['q'], $n['r'], $q1, $r1) * $minCost;
                $open->insert($nk, -($ng + $h));
            }
        }
    }
    if (!isset($g[$goal])) return null;
    $path = [];
    for ($k = $goal; $k !== null; $k = $came[$k] ?? null) {
        $t = $tiles[$k];
        array_unshift($path, [$t['q'], $t['r']]);
        if ($k === $start) break;
    }
    return $path;
}

/** 클라이언트용 맵 데이터 */
function vg_state_map(): array
{
    vg_map_ensure();
    $pdo = vg_db();
    $tiles = [];
    foreach (vg_map_tiles() as $t) $tiles[] = [$t['q'], $t['r'], $t['t'], $t['f']];
    $vs = [];
    foreach ($pdo->query('SELECT id, name, user_name, q, r FROM vg_villages WHERE q IS NOT NULL') as $v) {
        $vs[] = ['id' => (int)$v['id'], 'name' => $v['name'], 'owner' => $v['user_name'], 'q' => (int)$v['q'], 'r' => (int)$v['r']];
    }
    $terr = [];
    foreach (vg_tdefs() as $c => $d) {
        $terr[$c] = ['name' => $d['name'], 'color' => $d['color'], 'move_cost' => $d['move_cost'], 'def' => $d['def_bonus_pct'],
            'cav' => $d['cav_bonus_pct'], 'passable' => $d['passable'], 'descr' => $d['descr']];
    }
    $feats = [];
    foreach (vg_map_features() as $c => [$n, $res]) $feats[$c] = ['name' => $n, 'res' => $res];
    return [
        'version' => (int)vg_meta_get($pdo, 'map_version'),
        'radius' => (int)vg_meta_get($pdo, 'map_radius'),
        'tiles' => $tiles,
        'villages' => $vs,
        'terrains' => $terr,
        'features' => $feats,
        'bridge_move_cost' => (float)S('bridge_move_cost'),
    ];
}
