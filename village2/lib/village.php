<?php
// 마을: 생성, 자원 정산(lazy settle), 건설·업그레이드·취소·철거·이동, 화면 상태.
//
// 정산 원칙
// - 자원은 조회/행동 시점에 last_settle ~ now 구간을 계산해 한 번에 반영한다 (접속 안 해도 누적).
// - 구간 중간에 끝난 건설이 있으면 그 시각까지 먼저 정산 → 완공 반영 → 이어서 정산 (생산량 변화 정확 반영).
// - 모든 변경은 트랜잭션 + 마을 행 FOR UPDATE 로 선점한 뒤에만 한다 (중복 정산 방지).

// ───────────────────────── 정의·공식 ─────────────────────────

function vg_bdefs(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = [];
    foreach (vg_db()->query('SELECT * FROM vg_building_defs ORDER BY sort_order, code') as $d) {
        $d['cost'] = json_decode($d['cost_json'], true) ?: [];
        foreach (['base_rate', 'rate_growth', 'cost_growth', 'base_time', 'time_growth'] as $k) $d[$k] = (float)$d[$k];
        foreach (['max_level', 'multi', 'req_hall', 'enabled', 'sort_order'] as $k) $d[$k] = (int)$d[$k];
        $cache[$d['code']] = $d;
    }
    return $cache;
}

function vg_bdef(string $code): array
{
    $defs = vg_bdefs();
    if (!isset($defs[$code])) throw new VgError("알 수 없는 건물: $code");
    return $defs[$code];
}

/** $level 로 올리는(짓는) 데 드는 비용 */
function vg_level_cost(array $def, int $level): array
{
    $out = [];
    $g = pow($def['cost_growth'], max(0, $level - 1));
    foreach ($def['cost'] as $r => $amt) {
        if (!in_array($r, VG_RES, true) || $amt <= 0) continue;
        $out[$r] = (int)round($amt * $g);
    }
    return $out;
}

/** $level 로 올리는 데 걸리는 시간(초). $cutPct = 일꾼 등에 의한 단축(%) */
function vg_level_time(array $def, int $level, float $cutPct = 0.0): float
{
    $speed = max(0.01, (float)S('build_speed_mult'));
    $cut = max(0.0, min(95.0, $cutPct));
    return max(1.0, round($def['base_time'] * pow($def['time_growth'], max(0, $level - 1)) / $speed * (1 - $cut / 100)));
}

/** 레벨별 기본 수치 (생산 건물: 초당 생산량, 창고: 용량). 배수 적용 전 */
function vg_level_rate(array $def, int $level): float
{
    if ($level <= 0 || $def['base_rate'] <= 0) return 0.0;
    return $def['base_rate'] * $level * pow($def['rate_growth'], $level - 1);
}

/** 회관 레벨별 사용 가능한 칸 수 */
function vg_cells_for_hall(int $hall): int
{
    $max = max(1, min(16, (int)S('cells_max')));
    $list = trim((string)S('grid_expand_levels'));
    if ($list !== '') {
        $vals = array_values(array_filter(array_map('trim', explode(',', $list)), 'strlen'));
        if ($vals) {
            $i = max(0, min(count($vals) - 1, $hall - 1));
            return max(0, min($max, (int)$vals[$i]));
        }
    }
    return max(0, min($max, (int)S('cells_base') + max(0, $hall - 1) * (int)S('cells_per_hall_level')));
}

/**
 * 칸 번호 → 5x5 격자 좌표. 회관은 가운데(2,2), 칸은 안쪽부터 열린다.
 * 1~4: 상하좌우, 5~8: 대각선, 9~12: 바깥 축, 13~16: 바깥 모서리
 */
function vg_slot_layout(): array
{
    return [
        0 => [2, 2],
        1 => [2, 1], 2 => [3, 2], 3 => [2, 3], 4 => [1, 2],
        5 => [1, 1], 6 => [3, 1], 7 => [3, 3], 8 => [1, 3],
        9 => [2, 0], 10 => [4, 2], 11 => [2, 4], 12 => [0, 2],
        13 => [0, 0], 14 => [4, 0], 15 => [4, 4], 16 => [0, 4],
    ];
}

function vg_hall_level(array $blds): int
{
    foreach ($blds as $b) if ($b['code'] === 'hall') return (int)$b['level'];
    return 1;
}

/** 전체 생산 배수 (전역 배수 × 초반 보너스) */
function vg_prod_mult(int $hall): float
{
    $m = (float)S('prod_speed_mult');
    $until = (int)S('early_boost_until_hall');
    if ($until > 0 && $hall <= $until) $m *= 1 + (float)S('early_boost_pct') / 100;
    return $m;
}

function vg_storage_cap(array $blds): float
{
    $cap = (float)S('storage_base');
    $defs = vg_bdefs();
    foreach ($blds as $b) {
        $d = $defs[$b['code']] ?? null;
        if ($d && $d['category'] === 'storage') $cap += vg_level_rate($d, (int)$b['level']);
    }
    return $cap;
}

/**
 * 현재 건물 기준 생산 정보.
 * per: bid => [res, rate]  (제련소 제외)
 * smelt: [gold => 초당 최대 금괴, iron => 초당 최대 철광석 소모, bids => [bid => gold rate]]
 */
function vg_rates(array $blds, array $bonusPct = []): array
{
    $defs = vg_bdefs();
    $mult = vg_prod_mult(vg_hall_level($blds));
    $ratio = max(0.0, (float)S('smelt_iron_per_gold'));
    $per = [];
    $smelt = ['gold' => 0.0, 'iron' => 0.0, 'bids' => []];
    foreach ($blds as $b) {
        $d = $defs[$b['code']] ?? null;
        if (!$d || !$d['enabled'] || !in_array($d['produces'], VG_RES, true)) continue;
        $rate = vg_level_rate($d, (int)$b['level']) * $mult * (1 + ($bonusPct[$b['id']] ?? 0) / 100);
        if ($rate <= 0) continue;
        if ($b['code'] === 'smelter') {
            $smelt['gold'] += $rate;
            $smelt['iron'] += $rate * $ratio;
            $smelt['bids'][$b['id']] = $rate;
        } else {
            $per[$b['id']] = ['res' => $d['produces'], 'rate' => $rate, 'code' => $b['code']];
        }
    }
    return ['per' => $per, 'smelt' => $smelt, 'mult' => $mult, 'cap' => vg_storage_cap($blds)];
}

/** 이항분포 표본 (n 이 크면 정규근사) */
function vg_binom(int $n, float $p): int
{
    if ($n <= 0 || $p <= 0) return 0;
    if ($p >= 1) return $n;
    if ($n <= 60) {
        $k = 0;
        for ($i = 0; $i < $n; $i++) if (mt_rand() / mt_getrandmax() < $p) $k++;
        return $k;
    }
    $u1 = max(1e-12, mt_rand() / mt_getrandmax());
    $u2 = mt_rand() / mt_getrandmax();
    $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    return max(0, min($n, (int)round($n * $p + $z * sqrt($n * $p * (1 - $p)))));
}

/** 구간 [t0, t1] 동안 크리티컬이 터진 생산 주기 수 */
function vg_crit_count(float $dt): int
{
    $tick = max(1.0, (float)S('crit_tick_sec'));
    $n = $dt / $tick;
    $whole = (int)floor($n);
    if (mt_rand() / mt_getrandmax() < $n - $whole) $whole++;
    return vg_binom($whole, (float)S('crit_chance_pct') / 100);
}

/** 자원 증감을 창고 한도에 맞춰 적용. 이미 한도를 넘긴 자원은 깎지 않고 증가만 막는다 */
function vg_apply_delta(float $cur, float $delta, float $cap): float
{
    if ($delta >= 0) return max($cur, min($cap, $cur + $delta));
    return max(0.0, $cur + $delta);
}

function vg_res_cap(string $r, float $cap): float
{
    return ($r === 'money' && !S('storage_cap_money')) ? INF : $cap;
}

/**
 * 구간 생산 반영. $res 는 참조로 갱신, $crit 에 크리티컬 누적 [code => [n, res, amount]]
 * $upkeep = 초당 식량 소비. 식량이 바닥난 시간(초)을 반환 (굶주림 처리용)
 */
function vg_produce(array &$res, array $blds, float $t0, float $t1, array &$crit, array $bonusPct = [], float $upkeep = 0.0): float
{
    $dt = $t1 - $t0;
    if ($dt <= 0) return 0.0;
    $R = vg_rates($blds, $bonusPct);
    $tick = max(1.0, (float)S('crit_tick_sec'));
    $cm = max(1.0, (float)S('crit_mult'));
    $gain = array_fill_keys(VG_RES, 0.0);

    foreach ($R['per'] as $bid => $p) {
        $g = $p['rate'] * $dt;
        $k = $cm > 1 ? vg_crit_count($dt) : 0;
        if ($k > 0) {
            $extra = $p['rate'] * $tick * $k * ($cm - 1);
            $g += $extra;
            $c = &$crit[$p['code']];
            $c = ['n' => ($c['n'] ?? 0) + $k, 'res' => $p['res'], 'amount' => ($c['amount'] ?? 0) + $extra];
            unset($c);
        }
        $gain[$p['res']] += $g;
    }

    // 제련: 철광석 재고(+구간 중 생산분)만큼만 소모. 고갈되면 그 뒤로는 들어오는 만큼만 제련
    $S = $R['smelt'];
    if ($S['iron'] > 0 || $S['gold'] > 0) {
        $need = $S['iron'] * $dt;
        $avail = max(0.0, $res['iron'] + $gain['iron']);
        if ($S['iron'] <= 0) {
            $used = 0.0;
            $gold = $S['gold'] * $dt;
        } elseif ($avail >= $need) {
            $used = $need;
            $gold = $S['gold'] * $dt;
        } else {
            $used = $avail;
            $gold = $S['gold'] * $dt * ($avail / $need);
        }
        $gain['iron'] -= $used;
        if ($gold > 0) {
            $k = $cm > 1 ? vg_crit_count($dt) : 0;
            if ($k > 0) {
                $extra = ($gold / $dt) * $tick * $k * ($cm - 1);
                $gold += $extra;
                $c = &$crit['smelter'];
                $c = ['n' => ($c['n'] ?? 0) + $k, 'res' => 'gold', 'amount' => ($c['amount'] ?? 0) + $extra];
                unset($c);
            }
        }
        $gain['gold'] += $gold;
    }

    // 식량 유지비. 생산이 모자라 재고가 바닥나면 그 뒤 시간은 굶주림
    $starve = 0.0;
    if ($upkeep > 0) {
        $prodRate = $gain['food'] / $dt;
        $end = $res['food'] + $gain['food'] - $upkeep * $dt;
        if ($end < 0 && $upkeep > $prodRate) {
            $starve = max(0.0, $dt - max(0.0, $res['food']) / ($upkeep - $prodRate));
        }
        $gain['food'] -= $upkeep * $dt;
    }

    foreach (VG_RES as $r) {
        $res[$r] = vg_apply_delta((float)$res[$r], $gain[$r], vg_res_cap($r, $R['cap']));
    }
    return $starve;
}

// ───────────────────────── 조회·생성 ─────────────────────────

function vg_load_buildings(int $vid): array
{
    $st = vg_db()->prepare('SELECT * FROM vg_buildings WHERE village_id = ? ORDER BY slot, id');
    $st->execute([$vid]);
    $out = [];
    foreach ($st as $b) {
        foreach (['id', 'village_id', 'slot', 'level', 'target_level'] as $k) $b[$k] = (int)$b[$k];
        $b['build_start'] = $b['build_start'] === null ? null : (float)$b['build_start'];
        $b['build_finish'] = $b['build_finish'] === null ? null : (float)$b['build_finish'];
        $out[$b['id']] = $b;
    }
    return $out;
}

function vg_log(int $vid, string $kind, string $msg, ?float $t = null): void
{
    vg_db()->prepare('INSERT INTO vg_logs (village_id, t, kind, msg) VALUES (?, ?, ?, ?)')
        ->execute([$vid, $t ?? vg_now(), $kind, mb_substr($msg, 0, 500)]);
}

/** 사용자 마을을 가져오고 없으면 만든다 */
function vg_village_for_user(array $u): array
{
    $pdo = vg_db();
    $st = $pdo->prepare('SELECT * FROM vg_villages WHERE user_id = ?');
    $st->execute([$u['id']]);
    $v = $st->fetch();
    if (!$v) $v = vg_create_village($u['id'], $u['name']);
    if ($v['q'] === null) {
        vg_map_ensure();
        $st->execute([$u['id']]);
        $v = $st->fetch();
    }
    return $v;
}

function vg_create_village(string $userId, string $userName): array
{
    return vg_tx(function (PDO $pdo) use ($userId, $userName) {
        $now = vg_now();
        $name = mb_substr($userName, 0, 40) . '의 마을';
        $st = $pdo->prepare('INSERT IGNORE INTO vg_villages (user_id, user_name, name, created_at, last_settle, last_seen)
            VALUES (?, ?, ?, ?, ?, ?)');
        $st->execute([$userId, $userName, $name, $now, $now, $now]);
        if ($st->rowCount() === 0) { // 동시 요청이 먼저 만들었음
            $st = $pdo->prepare('SELECT * FROM vg_villages WHERE user_id = ?');
            $st->execute([$userId]);
            return $st->fetch();
        }
        $vid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO vg_resources (village_id, money, food, wood, iron, gold) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$vid, S('start_money'), S('start_food'), S('start_wood'), S('start_iron'), S('start_gold')]);
        $ins = $pdo->prepare('INSERT INTO vg_buildings (village_id, code, slot, level, target_level) VALUES (?, ?, ?, 1, 1)');
        $ins->execute([$vid, 'hall', VG_SLOT_HALL]);
        $defs = vg_bdefs();
        $slot = 1;
        foreach (explode(',', (string)S('start_buildings')) as $code) {
            $code = trim($code);
            if ($code === '' || $code === 'hall' || !isset($defs[$code])) continue;
            if ($defs[$code]['category'] === 'wall') {
                $ins->execute([$vid, $code, VG_SLOT_WALL]);
                continue;
            }
            if ($slot > vg_cells_for_hall(1)) break;
            $ins->execute([$vid, $code, $slot++]);
        }
        vg_log($vid, 'info', "마을 '$name' 이(가) 세워졌습니다.", $now);
        $st = $pdo->prepare('SELECT * FROM vg_villages WHERE id = ?');
        $st->execute([$vid]);
        return $st->fetch();
    });
}

// ───────────────────────── 정산 ─────────────────────────

/**
 * 마을을 잠그고 now 까지 정산한다. 반드시 트랜잭션 안에서 호출.
 * 시간을 사건(건설 완공·연구 완료·훈련 주문 완료) 단위로 잘라, 구간마다
 * 생산·식량 유지비·굶주림 → 주민 근무 → 훈련 진행 순으로 계산한다.
 * 반환: ['village', 'res', 'blds', 'vils', 'units', 'queue', 'research', 'crit']
 */
function vg_settle(int $vid, ?float $now = null): array
{
    $pdo = vg_db();
    if (!$pdo->inTransaction()) throw new LogicException('vg_settle 은 트랜잭션 안에서 호출해야 합니다');
    $now = $now ?? vg_now();

    $st = $pdo->prepare('SELECT * FROM vg_villages WHERE id = ? FOR UPDATE');
    $st->execute([$vid]);
    $v = $st->fetch();
    if (!$v) throw new VgError('마을을 찾을 수 없습니다.');
    $st = $pdo->prepare('SELECT money, food, wood, iron, gold FROM vg_resources WHERE village_id = ? FOR UPDATE');
    $st->execute([$vid]);
    $X = [
        'village' => $v,
        'res' => array_map('floatval', $st->fetch() ?: array_fill_keys(VG_RES, 0)),
        'blds' => vg_load_buildings($vid),
        'vils' => vg_load_villagers($vid),
        'units' => vg_load_units($vid),
        'queue' => vg_load_queue($vid),
        'research' => vg_load_research($vid),
        'armies' => vg_load_armies($vid),
        'away' => vg_load_away_units($vid),
        'crit' => [],
        'udirty' => [], 'qdirty' => [], 'qdeleted' => [], 'vdirty' => [],
    ];

    $t = (float)$v['last_settle'];
    if ($now < $t) $now = $t; // 서버 시계가 뒤로 갔을 때 방어
    $defs = vg_bdefs();
    $bDone = $pdo->prepare('UPDATE vg_buildings SET level = target_level, build_start = NULL, build_finish = NULL WHERE id = ?');
    $rDone = $pdo->prepare('UPDATE vg_research SET level = target_level, start = NULL, finish = NULL WHERE village_id = ? AND code = ?');

    for ($guard = 0; $guard < 10000; $guard++) {
        // 다음 사건 시각
        $next = null;
        foreach ($X['blds'] as $b) if ($b['build_finish'] !== null && $b['build_finish'] <= $now) $next = min($next ?? INF, $b['build_finish']);
        foreach ($X['research'] as $r) if ($r['finish'] !== null && $r['finish'] <= $now) $next = min($next ?? INF, $r['finish']);
        $qf = vg_queue_next_finish($X);
        if ($qf !== null && $qf <= $now) $next = min($next ?? INF, $qf);
        if ($next === null) break;
        $next = max($t, $next);
        vg_advance($X, $t, $next);
        $t = $next;
        foreach ($X['blds'] as $id => $b) {
            if ($b['build_finish'] === null || $b['build_finish'] > $t) continue;
            $X['blds'][$id]['level'] = $b['target_level'];
            $X['blds'][$id]['build_start'] = $X['blds'][$id]['build_finish'] = null;
            $bDone->execute([$id]);
            $nm = $defs[$b['code']]['name'] ?? $b['code'];
            vg_log($vid, 'build', $b['level'] == 0 ? "{$nm} 건설 완료" : "{$nm} Lv{$b['target_level']} 업그레이드 완료", $b['build_finish']);
        }
        foreach ($X['research'] as $code => $r) {
            if ($r['finish'] === null || $r['finish'] > $t) continue;
            $X['research'][$code]['level'] = $r['target_level'];
            $X['research'][$code]['start'] = $X['research'][$code]['finish'] = null;
            $rDone->execute([$vid, $code]);
            $nm = vg_rdefs()[$code]['name'] ?? $code;
            vg_log($vid, 'research', "{$nm} Lv{$r['target_level']} 연구 완료", $r['finish']);
        }
    }
    vg_advance($X, $t, $now);
    vg_armies_resolve($X, $now);

    vg_save_res($vid, $X['res']);
    vg_army_save($X);
    vg_villagers_save($X);
    $pdo->prepare('UPDATE vg_villages SET last_settle = ? WHERE id = ?')->execute([$now, $vid]);
    $X['village']['last_settle'] = $now;

    if ($X['crit']) {
        $names = vg_res_names();
        $parts = [];
        foreach ($X['crit'] as $code => $c) {
            $parts[] = ($defs[$code]['name'] ?? $code) . " ×{$c['n']} ({$names[$c['res']]} +" . number_format($c['amount']) . ')';
        }
        vg_log($vid, 'crit', '크리티컬 생산! ' . implode(', ', $parts), $now);
    }
    return $X;
}

/** 사건 없는 한 구간 [t0, t1] 진행 */
function vg_advance(array &$X, float $t0, float $t1): void
{
    if ($t1 <= $t0) return;
    $starve = vg_produce($X['res'], $X['blds'], $t0, $t1, $X['crit'], vg_villager_bonus_map($X['vils']), vg_upkeep_per_sec($X));
    if ($starve > 0) vg_starve($X, $starve, $t1);
    vg_villagers_advance($X, $t0, $t1);
    vg_queue_advance($X, $t1);
}

// ───────────────────────── 행동 ─────────────────────────

function vg_pay(int $vid, array &$res, array $cost): void
{
    $names = vg_res_names();
    $short = [];
    foreach ($cost as $r => $amt) {
        if ($res[$r] + 1e-9 < $amt) $short[] = $names[$r] . ' ' . number_format(ceil($amt - $res[$r])) . ' 부족';
    }
    if ($short) throw new VgError('자원이 부족합니다: ' . implode(', ', $short));
    foreach ($cost as $r => $amt) $res[$r] -= $amt;
    vg_save_res($vid, $res);
}

/** 환급 등 자원 추가. 창고 한도를 넘는 부분은 버린다 */
function vg_refund(int $vid, array &$res, array $blds, array $amounts): array
{
    $cap = vg_storage_cap($blds);
    $got = [];
    foreach ($amounts as $r => $amt) {
        $before = $res[$r];
        $res[$r] = vg_apply_delta($before, $amt, vg_res_cap($r, $cap));
        $got[$r] = $res[$r] - $before;
    }
    vg_save_res($vid, $res);
    return $got;
}

function vg_save_res(int $vid, array $res): void
{
    vg_db()->prepare('UPDATE vg_resources SET money = ?, food = ?, wood = ?, iron = ?, gold = ? WHERE village_id = ?')
        ->execute([$res['money'], $res['food'], $res['wood'], $res['iron'], $res['gold'], $vid]);
}

function vg_scale_cost(array $cost, float $pct): array
{
    $out = [];
    foreach ($cost as $r => $a) {
        $v = floor($a * $pct / 100);
        if ($v > 0) $out[$r] = $v;
    }
    return $out;
}

function vg_fmt_cost(array $cost): string
{
    $n = vg_res_names();
    $p = [];
    foreach ($cost as $r => $a) if ($a > 0) $p[] = $n[$r] . ' ' . number_format($a);
    return $p ? implode(', ', $p) : '없음';
}

function vg_building_owned(array $blds, int $bid): array
{
    if (!isset($blds[$bid])) throw new VgError('건물을 찾을 수 없습니다.');
    return $blds[$bid];
}

function vg_check_build_slots(array $blds): void
{
    $busy = count(array_filter($blds, fn($b) => $b['build_finish'] !== null));
    $max = max(1, (int)S('max_concurrent_builds'));
    if ($busy >= $max) throw new VgError("동시에 {$max}곳까지만 공사할 수 있습니다.");
}

/** 해당 칸이 지금 사용 가능한지 (열린 칸이거나, 이미 건물이 있어 보호되는 칸) */
function vg_slot_open(int $slot, int $hall): bool
{
    return $slot >= 1 && $slot <= 16 && $slot <= vg_cells_for_hall($hall);
}

function vg_act_build(int $vid, string $code, int $slot): void
{
    vg_tx(function () use ($vid, $code, $slot) {
        $now = vg_now();
        ['res' => $res, 'blds' => $blds, 'units' => $units] = vg_settle($vid, $now);
        $def = vg_bdef($code);
        if (!$def['enabled']) throw new VgError('지금은 지을 수 없는 건물입니다.');
        if ($def['category'] === 'hall') throw new VgError('마을회관은 하나만 있습니다.');
        $hall = vg_hall_level($blds);
        if ($hall < $def['req_hall']) throw new VgError("회관 Lv{$def['req_hall']} 이상 필요합니다.");
        if (!$def['multi'] && array_filter($blds, fn($b) => $b['code'] === $code)) {
            throw new VgError("{$def['name']}은(는) 하나만 지을 수 있습니다.");
        }
        if ($def['category'] === 'wall') {
            $slot = VG_SLOT_WALL;
            if (array_filter($blds, fn($b) => $b['slot'] === VG_SLOT_WALL)) throw new VgError('성벽 자리가 이미 사용 중입니다.');
        } else {
            if (!vg_slot_open($slot, $hall)) throw new VgError('아직 열리지 않은 칸입니다.');
            if (array_filter($blds, fn($b) => $b['slot'] === $slot)) throw new VgError('이미 건물이 있는 칸입니다.');
        }
        vg_check_build_slots($blds);
        $cost = vg_level_cost($def, 1);
        vg_pay($vid, $res, $cost);
        $time = vg_level_time($def, 1, vg_worker_build_pct($units));
        vg_db()->prepare('INSERT INTO vg_buildings (village_id, code, slot, level, target_level, build_start, build_finish)
            VALUES (?, ?, ?, 0, 1, ?, ?)')->execute([$vid, $code, $slot, $now, $now + $time]);
        vg_log($vid, 'build', "{$def['name']} 건설 시작 (" . vg_fmt_cost($cost) . ')', $now);
    });
}

function vg_act_upgrade(int $vid, int $bid): void
{
    vg_tx(function () use ($vid, $bid) {
        $now = vg_now();
        ['res' => $res, 'blds' => $blds, 'units' => $units] = vg_settle($vid, $now);
        $b = vg_building_owned($blds, $bid);
        if ($b['build_finish'] !== null) throw new VgError('이미 공사 중입니다.');
        $def = vg_bdef($b['code']);
        $to = $b['level'] + 1;
        if ($to > $def['max_level']) throw new VgError('최대 레벨입니다.');
        if ($def['category'] !== 'hall') {
            $lim = vg_hall_level($blds) + (int)S('hall_headroom');
            if ($to > $lim) throw new VgError("회관 레벨 + " . (int)S('hall_headroom') . " (Lv{$lim}) 까지만 올릴 수 있습니다. 회관을 먼저 올리세요.");
        }
        vg_check_build_slots($blds);
        $cost = vg_level_cost($def, $to);
        vg_pay($vid, $res, $cost);
        $time = vg_level_time($def, $to, vg_worker_build_pct($units));
        vg_db()->prepare('UPDATE vg_buildings SET target_level = ?, build_start = ?, build_finish = ? WHERE id = ?')
            ->execute([$to, $now, $now + $time, $bid]);
        vg_log($vid, 'build', "{$def['name']} Lv{$to} 업그레이드 시작 (" . vg_fmt_cost($cost) . ')', $now);
    });
}

function vg_act_cancel(int $vid, int $bid): void
{
    vg_tx(function () use ($vid, $bid) {
        $now = vg_now();
        ['res' => $res, 'blds' => $blds] = vg_settle($vid, $now);
        $b = vg_building_owned($blds, $bid);
        if ($b['build_finish'] === null) throw new VgError('진행 중인 공사가 없습니다.');
        $def = vg_bdef($b['code']);
        $refund = vg_scale_cost(vg_level_cost($def, $b['target_level']), (float)S('build_cancel_refund_pct'));
        if ($b['level'] <= 0) {
            vg_db()->prepare('DELETE FROM vg_buildings WHERE id = ?')->execute([$bid]);
            unset($blds[$bid]);
        } else {
            vg_db()->prepare('UPDATE vg_buildings SET target_level = level, build_start = NULL, build_finish = NULL WHERE id = ?')
                ->execute([$bid]);
        }
        $got = vg_refund($vid, $res, $blds, $refund);
        vg_log($vid, 'build', "{$def['name']} 공사 취소 (환급: " . vg_fmt_cost(array_map('floor', $got)) . ')', $now);
    });
}

function vg_act_demolish(int $vid, int $bid): void
{
    vg_tx(function () use ($vid, $bid) {
        $now = vg_now();
        ['res' => $res, 'blds' => $blds] = vg_settle($vid, $now);
        $b = vg_building_owned($blds, $bid);
        $def = vg_bdef($b['code']);
        if ($def['category'] === 'hall') throw new VgError('마을회관은 철거할 수 없습니다.');
        if ($b['build_finish'] !== null) throw new VgError('공사 중인 건물은 먼저 공사를 취소하세요.');
        $spent = [];
        for ($l = 1; $l <= $b['level']; $l++) {
            foreach (vg_level_cost($def, $l) as $r => $a) $spent[$r] = ($spent[$r] ?? 0) + $a;
        }
        $refund = vg_scale_cost($spent, (float)S('demolish_refund_pct'));
        vg_db()->prepare('DELETE FROM vg_buildings WHERE id = ?')->execute([$bid]);
        vg_villagers_release_building($bid);
        unset($blds[$bid]);
        $got = vg_refund($vid, $res, $blds, $refund);
        vg_log($vid, 'build', "{$def['name']} Lv{$b['level']} 철거 (환급: " . vg_fmt_cost(array_map('floor', $got)) . ')', $now);
    });
}

/** 건물을 다른 칸으로 옮긴다. 대상 칸에 건물이 있으면 서로 맞바꾼다 */
function vg_act_move(int $vid, int $bid, int $slot): void
{
    vg_tx(function () use ($vid, $bid, $slot) {
        ['blds' => $blds] = vg_settle($vid);
        $b = vg_building_owned($blds, $bid);
        if ($b['slot'] < 1 || $b['slot'] > 16) throw new VgError('이 건물은 옮길 수 없습니다.');
        if ($b['slot'] === $slot) return;
        $other = null;
        foreach ($blds as $o) if ($o['slot'] === $slot) $other = $o;
        // 이미 건물이 있는 칸은 (칸 수가 줄었더라도) 보호되므로 맞바꾸기 허용
        if (!$other && !vg_slot_open($slot, vg_hall_level($blds))) throw new VgError('아직 열리지 않은 칸입니다.');
        if ($other && ($other['slot'] < 1 || $other['slot'] > 16)) throw new VgError('그 자리와는 바꿀 수 없습니다.');
        $up = vg_db()->prepare('UPDATE vg_buildings SET slot = ? WHERE id = ?');
        if ($other) $up->execute([$b['slot'], $other['id']]);
        $up->execute([$slot, $bid]);
    });
}

function vg_act_rename(int $vid, string $name): void
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_strlen($name) > 30) throw new VgError('마을 이름은 1~30자로 정하세요.');
    vg_db()->prepare('UPDATE vg_villages SET name = ? WHERE id = ?')->execute([$name, $vid]);
}

// ───────────────────────── 화면 상태 ─────────────────────────

/** 현재 순생산량(초당). 제련소 철광석 부족 시 실제 가동률 반영 */
function vg_net_rates(array $res, array $blds, array $bonusPct = [], float $upkeepSec = 0.0): array
{
    $R = vg_rates($blds, $bonusPct);
    $net = array_fill_keys(VG_RES, 0.0);
    foreach ($R['per'] as $p) $net[$p['res']] += $p['rate'];
    $S = $R['smelt'];
    $util = 1.0;
    if ($S['iron'] > 0 && $res['iron'] < 1 && $net['iron'] < $S['iron']) $util = $net['iron'] / $S['iron'];
    $net['iron'] -= $S['iron'] * $util;
    $net['gold'] += $S['gold'] * $util;
    $net['food'] -= $upkeepSec;
    return ['net' => $net, 'smelt_util' => $util, 'rates' => $R];
}

function vg_state(int $vid, array $settled): array
{
    ['village' => $v, 'res' => $res, 'blds' => $blds] = $settled;
    $defs = vg_bdefs();
    $hall = vg_hall_level($blds);
    $bonus = vg_villager_bonus_map($settled['vils']);
    $NR = vg_net_rates($res, $blds, $bonus, vg_upkeep_per_sec($settled));
    $cut = vg_worker_build_pct($settled['units']);
    $R = $NR['rates'];
    $headroom = (int)S('hall_headroom');

    $bl = [];
    foreach ($blds as $b) {
        $d = $defs[$b['code']] ?? null;
        if (!$d) continue;
        $rate = 0.0;
        $pres = $d['produces'];
        if (isset($R['per'][$b['id']])) $rate = $R['per'][$b['id']]['rate'];
        if (isset($R['smelt']['bids'][$b['id']])) $rate = $R['smelt']['bids'][$b['id']] * $NR['smelt_util'];
        $next = null;
        $to = ($b['build_finish'] !== null ? $b['target_level'] : $b['level']) + 1;
        if ($to <= $d['max_level']) {
            $lim = $d['category'] === 'hall' ? $d['max_level'] : $hall + $headroom;
            $next = [
                'level' => $to,
                'cost' => vg_level_cost($d, $to),
                'time' => vg_level_time($d, $to, $cut),
                'blocked' => $to > $lim ? "회관 Lv" . ($to - $headroom) . " 필요" : null,
                'value' => vg_level_rate($d, $to) * ($d['category'] === 'storage' ? 1 : $R['mult']),
            ];
        }
        $bl[] = [
            'id' => $b['id'], 'code' => $b['code'], 'slot' => $b['slot'], 'level' => $b['level'],
            'target_level' => $b['target_level'], 'build_start' => $b['build_start'], 'build_finish' => $b['build_finish'],
            'produces' => $pres, 'rate' => $rate, 'bonus_pct' => $bonus[$b['id']] ?? 0,
            'villagers' => array_values(array_map(fn($x) => $x['name'], array_filter($settled['vils'], fn($x) => $x['building_id'] === $b['id']))),
            'value' => $d['category'] === 'storage' ? vg_level_rate($d, $b['level']) : null,
            'next' => $next,
        ];
    }

    $dl = [];
    foreach ($defs as $code => $d) {
        if (!$d['enabled']) continue;
        $count = count(array_filter($blds, fn($b) => $b['code'] === $code));
        $dl[$code] = [
            'name' => $d['name'], 'category' => $d['category'], 'produces' => $d['produces'], 'multi' => $d['multi'],
            'max_level' => $d['max_level'], 'req_hall' => $d['req_hall'], 'descr' => $d['descr'], 'count' => $count,
            'cost' => vg_level_cost($d, 1), 'time' => vg_level_time($d, 1, $cut),
            'value' => vg_level_rate($d, 1) * ($d['category'] === 'storage' ? 1 : $R['mult']),
        ];
    }

    $st = vg_db()->prepare('SELECT t, kind, msg FROM vg_logs WHERE village_id = ? ORDER BY id DESC LIMIT 30');
    $st->execute([$vid]);
    $logs = $st->fetchAll();
    foreach ($logs as &$l) $l['t'] = (float)$l['t'];
    unset($l);

    $cap = $R['cap'];
    $caps = [];
    foreach (VG_RES as $r) $caps[$r] = is_finite(vg_res_cap($r, $cap)) ? $cap : null;

    $mine = [];
    foreach ($settled['armies'] as $a) $mine[] = ['id' => $a['id'], 'name' => $a['name'], 'state' => $a['state'], 'returning' => $a['returning'],
        'arrive_at' => $a['arrive_at'], 'task' => $a['task'], 'q' => $a['q'], 'r' => $a['r'],
        'units' => vg_army_counts($a), 'total' => array_sum(vg_army_counts($a))];
    $udefs = vg_udefs();
    return vg_state_army($settled) + vg_state_villagers($settled) + [
        'home' => $v['q'] === null ? null : ['q' => (int)$v['q'], 'r' => (int)$v['r']],
        'my_armies' => $mine,
        'away_units' => $settled['away'],
        'march' => [
            'army_max' => (int)S('army_max'), 'return_pct' => (float)S('march_return_speed_pct'),
            'speed_mult' => (float)S('march_speed_mult'), 'bonus_pct' => vg_research_bonus($settled['research'], 'march_speed_pct'),
            'unit_speed' => array_map(fn($u) => $u['speed'], $udefs),
            'bridge_workers_min' => (int)S('bridge_workers_min'), 'bridge_wood' => (float)S('bridge_wood'),
            'bridge_time_sec' => (float)S('bridge_time_sec'), 'gather_per_worker_hour' => (float)S('gather_per_worker_hour'),
            'map_poll_sec' => max(2, (int)S('map_poll_sec')),
        ],
        'map_version' => (int)vg_meta_get(vg_db(), 'map_version'),
        'now' => vg_now(),
        'village' => ['id' => (int)$v['id'], 'name' => $v['name']],
        'res' => $res,
        'caps' => $caps,
        'net' => $NR['net'],
        'smelt_util' => $NR['smelt_util'],
        'prod_mult' => $R['mult'],
        'hall_level' => $hall,
        'hall_headroom' => $headroom,
        'cells' => vg_cells_for_hall($hall),
        'cells_next' => vg_cells_for_hall($hall + 1),
        'max_builds' => (int)S('max_concurrent_builds'),
        'build_cut_pct' => $cut,
        'layout' => vg_slot_layout(),
        'buildings' => $bl,
        'defs' => $dl,
        'categories' => vg_building_categories(),
        'res_names' => vg_res_names(),
        'logs' => $logs,
        'crit' => $settled['crit'] ?? [],
        'ui' => [
            'poll_sec' => max(5, (int)S('poll_sec')),
            'demolish_refund_pct' => (float)S('demolish_refund_pct'),
            'cancel_refund_pct' => (float)S('build_cancel_refund_pct'),
        ],
    ];
}

/** 조회용: 정산 후 상태 반환 */
function vg_settle_and_state(int $vid): array
{
    return vg_tx(function (PDO $pdo) use ($vid) {
        $s = vg_settle($vid);
        $pdo->prepare('UPDATE vg_villages SET last_seen = ? WHERE id = ?')->execute([vg_now(), $vid]);
        return vg_state($vid, $s);
    });
}
