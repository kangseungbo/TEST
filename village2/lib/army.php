<?php
// 병종·훈련 대기열·대장간 연구.
//
// 훈련 대기열
// - 훈련 건물 "종류"(병영·궁사양성소·마굿간·공성작업장·마을회관)마다 대기열이 따로 있고 서로 동시에 진행한다.
// - 한 대기열 안에서는 주문 순서대로 한 명씩 훈련한다.
// - 속도 배수 = (1 + barracks_speed_per_level × (그 종류 건물 레벨 합 − 1)) × train_speed_mult × (1 + 훈련 교범 %)
// - 맨 앞 주문은 unit_progress(지금 훈련 중인 1명의 진행률 0~1)와 progress_at(그 값을 계산한 시각)을 저장한다.
//   정산 때 경과 시간 × 속도로 이어서 계산하므로 접속하지 않아도 진행되고, 화면 표시와 같은 식을 쓴다.

// ───────────── 정의 ─────────────

function vg_udefs(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = [];
    foreach (vg_db()->query('SELECT * FROM vg_unit_defs ORDER BY sort_order, code') as $d) {
        $d['cost'] = json_decode($d['cost_json'], true) ?: [];
        foreach (['train_time', 'upkeep', 'attack', 'defense', 'speed', 'carry'] as $k) $d[$k] = (float)$d[$k];
        foreach (['req_level', 'ranged', 'sort_order', 'enabled'] as $k) $d[$k] = (int)$d[$k];
        $d['counters'] = array_values(array_filter(array_map('trim', explode(',', $d['counters'])), 'strlen'));
        $cache[$d['code']] = $d;
    }
    return $cache;
}

function vg_udef(string $code): array
{
    $d = vg_udefs()[$code] ?? null;
    if (!$d) throw new VgError('알 수 없는 병종입니다.');
    return $d;
}

function vg_rdefs(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = [];
    foreach (vg_db()->query('SELECT * FROM vg_research_defs ORDER BY sort_order, code') as $d) {
        $d['cost'] = json_decode($d['cost_json'], true) ?: [];
        foreach (['value_per_level', 'cost_growth', 'base_time', 'time_growth'] as $k) $d[$k] = (float)$d[$k];
        foreach (['max_level', 'req_smithy', 'sort_order', 'enabled'] as $k) $d[$k] = (int)$d[$k];
        $cache[$d['code']] = $d;
    }
    return $cache;
}

function vg_rdef(string $code): array
{
    $d = vg_rdefs()[$code] ?? null;
    if (!$d) throw new VgError('알 수 없는 연구입니다.');
    return $d;
}

/** 병종 1명 비용 */
function vg_unit_cost(array $u): array
{
    $out = [];
    foreach ($u['cost'] as $r => $a) if (in_array($r, VG_RES, true) && $a > 0) $out[$r] = (int)round($a);
    return $out;
}

/** 연구 Lv 비용·시간·필요 대장간 레벨 (건물과 같은 증가 공식) */
function vg_research_cost(array $d, int $level): array
{
    return vg_level_cost($d, $level);
}

function vg_research_time(array $d, int $level): float
{
    $speed = max(0.01, (float)S('research_speed_mult'));
    return max(1.0, round($d['base_time'] * pow($d['time_growth'], max(0, $level - 1)) / $speed));
}

function vg_research_req(array $d, int $level): int
{
    return $d['req_smithy'] + max(0, $level - 1);
}

/** 연구 효과 합(%) — effect 종류, 병종 분류(target 'all' 은 모두) */
function vg_research_bonus(array $research, string $effect, ?string $category = null): float
{
    $sum = 0.0;
    foreach (vg_rdefs() as $code => $d) {
        if ($d['effect'] !== $effect || !$d['enabled']) continue;
        if ($d['target'] !== 'all' && $d['target'] !== $category) continue;
        $sum += ($research[$code]['level'] ?? 0) * $d['value_per_level'];
    }
    return $sum;
}

// ───────────── 불러오기 ─────────────

function vg_load_units(int $vid): array
{
    $st = vg_db()->prepare('SELECT unit_code, count FROM vg_village_units WHERE village_id = ?');
    $st->execute([$vid]);
    $out = [];
    foreach ($st as $r) $out[$r['unit_code']] = (int)$r['count'];
    return $out;
}

function vg_load_queue(int $vid): array
{
    $st = vg_db()->prepare('SELECT * FROM vg_train_queue WHERE village_id = ? ORDER BY id');
    $st->execute([$vid]);
    $out = [];
    foreach ($st as $q) {
        foreach (['id', 'total', 'done'] as $k) $q[$k] = (int)$q[$k];
        foreach (['unit_progress', 'progress_at', 'created_at'] as $k) $q[$k] = (float)$q[$k];
        $q['cost'] = json_decode($q['cost_json'], true) ?: [];
        $out[$q['id']] = $q;
    }
    return $out;
}

function vg_load_research(int $vid): array
{
    $st = vg_db()->prepare('SELECT * FROM vg_research WHERE village_id = ?');
    $st->execute([$vid]);
    $out = [];
    foreach ($st as $r) {
        $r['level'] = (int)$r['level'];
        $r['target_level'] = (int)$r['target_level'];
        $r['start'] = $r['start'] === null ? null : (float)$r['start'];
        $r['finish'] = $r['finish'] === null ? null : (float)$r['finish'];
        $out[$r['code']] = $r;
    }
    return $out;
}

// ───────────── 훈련 속도·진행 ─────────────

/** 훈련 건물 종류별 레벨 합 (Lv1 이상, 업그레이드 중이면 현재 레벨) */
function vg_train_levels(array $blds, string $bldCode): int
{
    $sum = 0;
    foreach ($blds as $b) if ($b['code'] === $bldCode && $b['level'] > 0) $sum += $b['level'];
    return $sum;
}

function vg_train_max_level(array $blds, string $bldCode): int
{
    $m = 0;
    foreach ($blds as $b) if ($b['code'] === $bldCode) $m = max($m, $b['level']);
    return $m;
}

/** 훈련 속도 배수 (그 종류 건물이 없으면 0 = 멈춤) */
function vg_train_mult(array $blds, array $research, string $bldCode): float
{
    $sum = vg_train_levels($blds, $bldCode);
    if ($sum <= 0) return 0.0;
    return (1 + (float)S('barracks_speed_per_level') * ($sum - 1)) * max(0.01, (float)S('train_speed_mult'))
        * (1 + vg_research_bonus($research, 'train_speed_pct') / 100);
}

/** 1명 훈련 시간(초). 멈춤이면 INF */
function vg_unit_time(array $udef, float $mult): float
{
    return $mult > 0 ? max(0.1, $udef['train_time'] / $mult) : INF;
}

/** 대기열을 종류별로 묶어 순서대로 */
function vg_queue_groups(array $queue): array
{
    $g = [];
    foreach ($queue as $q) $g[$q['bld_code']][] = $q['id'];
    return $g;
}

/** 지금 속도로 계산한 다음 "주문 하나 완료" 시각 (없으면 null) */
function vg_queue_next_finish(array $X): ?float
{
    $best = null;
    $udefs = vg_udefs();
    foreach (vg_queue_groups($X['queue']) as $bld => $ids) {
        $h = $X['queue'][$ids[0]];
        $u = $udefs[$h['unit_code']] ?? null;
        if (!$u) continue;
        $ut = vg_unit_time($u, vg_train_mult($X['blds'], $X['research'], $bld));
        if (!is_finite($ut)) continue;
        $t = $h['progress_at'] + ($h['total'] - $h['done'] - $h['unit_progress']) * $ut;
        if ($best === null || $t < $best) $best = $t;
    }
    return $best;
}

/** 대기열을 T 시각까지 진행. 완성된 병력은 $X['units'] 에 더한다 */
function vg_queue_advance(array &$X, float $T): void
{
    $udefs = vg_udefs();
    foreach (vg_queue_groups($X['queue']) as $bld => $ids) {
        $mult = vg_train_mult($X['blds'], $X['research'], $bld);
        while ($ids) {
            $id = $ids[0];
            $h = &$X['queue'][$id];
            $u = $udefs[$h['unit_code']] ?? null;
            $ut = $u ? vg_unit_time($u, $mult) : INF;
            if (!is_finite($ut)) { // 건물이 없거나 병종 정의가 사라짐 → 멈춤
                $h['progress_at'] = $T;
                $X['qdirty'][$id] = true;
                unset($h);
                break;
            }
            $remaining = $h['total'] - $h['done'];
            $avail = $h['unit_progress'] + ($T - $h['progress_at']) / $ut;
            if ($avail >= $remaining - 1e-9) {
                $end = $h['progress_at'] + ($remaining - $h['unit_progress']) * $ut;
                $X['units'][$h['unit_code']] = ($X['units'][$h['unit_code']] ?? 0) + $remaining;
                $X['udirty'][$h['unit_code']] = true;
                vg_log($X['village']['id'], 'train', "{$u['name']} {$h['total']}명 훈련 완료", $end);
                $X['qdeleted'][$id] = true;
                unset($h, $X['queue'][$id], $X['qdirty'][$id]);
                array_shift($ids);
                if ($ids) {
                    $X['queue'][$ids[0]]['progress_at'] = $end;
                    $X['queue'][$ids[0]]['unit_progress'] = 0.0;
                    $X['qdirty'][$ids[0]] = true;
                }
                continue;
            }
            $whole = (int)floor($avail + 1e-9);
            if ($whole > 0) {
                $X['units'][$h['unit_code']] = ($X['units'][$h['unit_code']] ?? 0) + $whole;
                $X['udirty'][$h['unit_code']] = true;
                $h['done'] += $whole;
            }
            $h['unit_progress'] = max(0.0, $avail - $whole);
            $h['progress_at'] = $T;
            $X['qdirty'][$id] = true;
            unset($h);
            break;
        }
    }
}

/** 식량 유지비(초당): 주민 + 마을 병력 */
function vg_upkeep_per_sec(array $X): float
{
    return vg_upkeep_parts($X)['total'] / 3600;
}

function vg_upkeep_parts(array $X): array
{
    $vil = count($X['vils']) * (float)S('villager_food_each');
    $units = 0.0;
    $udefs = vg_udefs();
    foreach ($X['units'] as $code => $n) $units += $n * ($udefs[$code]['upkeep'] ?? 0);
    return ['villagers' => $vil, 'units' => $units, 'total' => $vil + $units];
}

/** 식량이 바닥난 시간(초)만큼 마을 병력 이탈 */
function vg_starve(array &$X, float $starveSec, float $t): void
{
    $p = (float)S('starve_desert_pct_per_hour') / 100;
    if ($starveSec <= 0 || $p <= 0 || !$X['units']) return;
    $frac = 1 - pow(1 - min(1, $p), $starveSec / 3600);
    $lost = 0;
    foreach ($X['units'] as $code => $n) {
        if ($n <= 0) continue;
        $x = $n * $frac;
        $k = (int)floor($x) + ((mt_rand() / mt_getrandmax()) < ($x - floor($x)) ? 1 : 0);
        $k = min($n, $k);
        if ($k <= 0) continue;
        $X['units'][$code] = $n - $k;
        $X['udirty'][$code] = true;
        $lost += $k;
    }
    if ($lost > 0) vg_log($X['village']['id'], 'starve', "식량이 바닥나 병사 {$lost}명이 마을을 떠났습니다.", $t);
}

/** 일꾼에 의한 건설 시간 단축(%) */
function vg_worker_build_pct(array $units): float
{
    $n = $units['worker'] ?? 0;
    return min((float)S('worker_build_speed_max_pct'), $n * (float)S('worker_build_speed_pct'));
}

/** 정산 상태를 DB 에 반영 (바뀐 것만) */
function vg_army_save(array &$X): void
{
    $pdo = vg_db();
    $vid = (int)$X['village']['id'];
    if (!empty($X['udirty'])) {
        $up = $pdo->prepare('INSERT INTO vg_village_units (village_id, unit_code, count) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE count = VALUES(count)');
        foreach (array_keys($X['udirty']) as $code) $up->execute([$vid, $code, max(0, (int)($X['units'][$code] ?? 0))]);
    }
    if (!empty($X['qdeleted'])) {
        $del = $pdo->prepare('DELETE FROM vg_train_queue WHERE id = ?');
        foreach (array_keys($X['qdeleted']) as $id) $del->execute([$id]);
    }
    if (!empty($X['qdirty'])) {
        $up = $pdo->prepare('UPDATE vg_train_queue SET done = ?, unit_progress = ?, progress_at = ? WHERE id = ?');
        foreach (array_keys($X['qdirty']) as $id) {
            if (!isset($X['queue'][$id])) continue;
            $q = $X['queue'][$id];
            $up->execute([$q['done'], $q['unit_progress'], $q['progress_at'], $id]);
        }
    }
    $X['udirty'] = $X['qdirty'] = $X['qdeleted'] = [];
}

// ───────────── 행동 ─────────────

function vg_act_train(int $vid, string $unitCode, int $count): void
{
    vg_tx(function (PDO $pdo) use ($vid, $unitCode, $count) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $u = vg_udef($unitCode);
        if (!$u['enabled'] || $u['train_bld'] === '') throw new VgError('지금은 훈련할 수 없는 병종입니다.');
        $max = max(1, (int)S('train_max_batch'));
        if ($count < 1 || $count > $max) throw new VgError("한 번에 1~{$max}명까지 훈련할 수 있습니다.");
        $bname = vg_bdefs()[$u['train_bld']]['name'] ?? $u['train_bld'];
        $lv = vg_train_max_level($X['blds'], $u['train_bld']);
        if ($lv <= 0) throw new VgError("{$bname}이(가) 있어야 훈련할 수 있습니다.");
        if ($lv < $u['req_level']) throw new VgError("{$bname} Lv{$u['req_level']} 이상 필요합니다.");
        $inQueue = count(array_filter($X['queue'], fn($q) => $q['bld_code'] === $u['train_bld']));
        $qmax = max(1, (int)S('train_queue_max'));
        if ($inQueue >= $qmax) throw new VgError("{$bname} 대기열은 {$qmax}개까지입니다.");
        $each = vg_unit_cost($u);
        $cost = [];
        foreach ($each as $r => $a) $cost[$r] = $a * $count;
        $res = $X['res'];
        vg_pay($vid, $res, $cost);
        $pdo->prepare('INSERT INTO vg_train_queue (village_id, bld_code, unit_code, total, done, unit_progress, progress_at, cost_json, created_at)
            VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?)')
            ->execute([$vid, $u['train_bld'], $unitCode, $count, $inQueue ? 0 : $now, json_encode($each), $now]);
        vg_log($vid, 'train', "{$u['name']} {$count}명 훈련 시작 (" . vg_fmt_cost($cost) . ')', $now);
    });
}

function vg_act_train_cancel(int $vid, int $qid): void
{
    vg_tx(function (PDO $pdo) use ($vid, $qid) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $q = $X['queue'][$qid] ?? null;
        if (!$q) throw new VgError('훈련 주문을 찾을 수 없습니다.');
        $left = $q['total'] - $q['done'];
        $refund = [];
        foreach ($q['cost'] as $r => $a) $refund[$r] = $a * $left;
        $refund = vg_scale_cost($refund, (float)S('train_cancel_refund_pct'));
        $ids = vg_queue_groups($X['queue'])[$q['bld_code']];
        $pdo->prepare('DELETE FROM vg_train_queue WHERE id = ?')->execute([$qid]);
        // 맨 앞 주문을 취소하면 다음 주문이 지금부터 시작
        if ($ids[0] === $qid && isset($ids[1])) {
            $pdo->prepare('UPDATE vg_train_queue SET progress_at = ?, unit_progress = 0 WHERE id = ?')->execute([$now, $ids[1]]);
        }
        $res = $X['res'];
        $got = vg_refund($vid, $res, $X['blds'], $refund);
        $u = vg_udefs()[$q['unit_code']] ?? ['name' => $q['unit_code']];
        vg_log($vid, 'train', "{$u['name']} 훈련 취소 {$left}명 (환급: " . vg_fmt_cost(array_map('floor', $got)) . ')', $now);
    });
}

function vg_act_disband(int $vid, string $unitCode, int $count): void
{
    vg_tx(function (PDO $pdo) use ($vid, $unitCode, $count) {
        $X = vg_settle($vid);
        $have = $X['units'][$unitCode] ?? 0;
        if ($count < 1) throw new VgError('해산할 수를 입력하세요.');
        if ($count > $have) throw new VgError("보유 병력({$have}명)보다 많이 해산할 수 없습니다.");
        $X['units'][$unitCode] = $have - $count;
        $X['udirty'][$unitCode] = true;
        vg_army_save($X);
        $u = vg_udefs()[$unitCode] ?? ['name' => $unitCode];
        vg_log($vid, 'train', "{$u['name']} {$count}명 해산");
    });
}

function vg_act_research(int $vid, string $code): void
{
    vg_tx(function (PDO $pdo) use ($vid, $code) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $d = vg_rdef($code);
        if (!$d['enabled']) throw new VgError('지금은 할 수 없는 연구입니다.');
        $busy = count(array_filter($X['research'], fn($r) => $r['finish'] !== null));
        $max = max(1, (int)S('research_max_concurrent'));
        $cur = $X['research'][$code] ?? ['level' => 0, 'finish' => null];
        if ($cur['finish'] !== null) throw new VgError('이미 연구 중입니다.');
        if ($busy >= $max) throw new VgError("동시에 {$max}개까지만 연구할 수 있습니다.");
        $to = $cur['level'] + 1;
        if ($to > $d['max_level']) throw new VgError('최대 레벨입니다.');
        $need = vg_research_req($d, $to);
        if (vg_train_max_level($X['blds'], 'smithy') < $need) throw new VgError("대장간 Lv{$need} 이상 필요합니다.");
        $cost = vg_research_cost($d, $to);
        $res = $X['res'];
        vg_pay($vid, $res, $cost);
        $finish = $now + vg_research_time($d, $to);
        $pdo->prepare('INSERT INTO vg_research (village_id, code, level, target_level, start, finish) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE target_level = VALUES(target_level), start = VALUES(start), finish = VALUES(finish)')
            ->execute([$vid, $code, $cur['level'], $to, $now, $finish]);
        vg_log($vid, 'research', "{$d['name']} Lv{$to} 연구 시작 (" . vg_fmt_cost($cost) . ')', $now);
    });
}

function vg_act_research_cancel(int $vid, string $code): void
{
    vg_tx(function (PDO $pdo) use ($vid, $code) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $r = $X['research'][$code] ?? null;
        if (!$r || $r['finish'] === null) throw new VgError('진행 중인 연구가 없습니다.');
        $d = vg_rdef($code);
        $refund = vg_scale_cost(vg_research_cost($d, $r['target_level']), (float)S('build_cancel_refund_pct'));
        $pdo->prepare('UPDATE vg_research SET target_level = level, start = NULL, finish = NULL WHERE village_id = ? AND code = ?')
            ->execute([$vid, $code]);
        $res = $X['res'];
        $got = vg_refund($vid, $res, $X['blds'], $refund);
        vg_log($vid, 'research', "{$d['name']} 연구 취소 (환급: " . vg_fmt_cost(array_map('floor', $got)) . ')', $now);
    });
}

// ───────────── 화면 상태 ─────────────

function vg_state_army(array $X): array
{
    $udefs = vg_udefs();
    $bdefs = vg_bdefs();
    $cats = vg_unit_categories();
    $names = [];
    foreach ($udefs as $c => $u) $names[$c] = $u['name'];
    $counterOn = (bool)S('counters_on');

    // 훈련 건물 종류별 정보 + 대기열 (각 주문의 예상 완료 시각 포함)
    $groups = [];
    foreach ($udefs as $code => $u) {
        if (!$u['enabled'] || $u['train_bld'] === '') continue;
        $bld = $u['train_bld'];
        if (!isset($groups[$bld])) {
            $mult = vg_train_mult($X['blds'], $X['research'], $bld);
            $groups[$bld] = [
                'bld' => $bld, 'name' => $bdefs[$bld]['name'] ?? $bld,
                'levels' => vg_train_levels($X['blds'], $bld), 'max_level' => vg_train_max_level($X['blds'], $bld),
                'mult' => $mult, 'units' => [], 'queue' => [],
            ];
        }
        $weak = [];
        foreach ($udefs as $oc => $o) if (in_array($code, $o['counters'], true)) $weak[] = $o['name'];
        $groups[$bld]['units'][] = [
            'code' => $code, 'name' => $u['name'], 'category' => $u['category'], 'category_name' => $cats[$u['category']] ?? $u['category'],
            'req_level' => $u['req_level'], 'cost' => vg_unit_cost($u),
            'time' => vg_unit_time($u, $groups[$bld]['mult']), 'base_time' => $u['train_time'],
            'upkeep' => $u['upkeep'], 'ranged' => $u['ranged'], 'speed' => $u['speed'], 'carry' => $u['carry'],
            'attack' => $u['attack'] * (1 + vg_research_bonus($X['research'], 'attack_pct', $u['category']) / 100),
            'defense' => $u['defense'] * (1 + vg_research_bonus($X['research'], 'defense_pct', $u['category']) / 100),
            'strong' => $counterOn ? array_map(fn($c) => $names[$c] ?? $c, $u['counters']) : [],
            'weak' => $counterOn ? $weak : [],
            'descr' => $u['descr'],
        ];
    }
    foreach ($groups as $bld => &$g) {
        foreach ($g['units'] as &$uu) if (!is_finite($uu['time'])) $uu['time'] = null;
        unset($uu);
        $ids = vg_queue_groups($X['queue'])[$bld] ?? [];
        $t = null;
        foreach ($ids as $i => $id) {
            $q = $X['queue'][$id];
            $u = $udefs[$q['unit_code']] ?? null;
            $ut = $u ? vg_unit_time($u, $g['mult']) : INF;
            if ($i === 0) $t = $q['progress_at'];
            $finish = is_finite($ut) && $t !== null ? $t + ($q['total'] - $q['done'] - ($i === 0 ? $q['unit_progress'] : 0)) * $ut : null;
            $g['queue'][] = [
                'id' => $id, 'unit' => $q['unit_code'], 'name' => $u['name'] ?? $q['unit_code'], 'total' => $q['total'], 'done' => $q['done'],
                'head' => $i === 0, 'unit_progress' => $q['unit_progress'], 'progress_at' => $q['progress_at'],
                'unit_time' => is_finite($ut) ? $ut : null, 'finish' => $finish,
            ];
            $t = $finish;
        }
    }
    unset($g);

    $home = [];
    foreach ($X['units'] as $code => $n) if ($n > 0) $home[] = ['code' => $code, 'name' => $names[$code] ?? $code, 'count' => $n,
        'upkeep' => $udefs[$code]['upkeep'] ?? 0, 'category' => $udefs[$code]['category'] ?? ''];

    $effects = vg_research_effects();
    $rs = [];
    $smithy = vg_train_max_level($X['blds'], 'smithy');
    foreach (vg_rdefs() as $code => $d) {
        if (!$d['enabled']) continue;
        $r = $X['research'][$code] ?? ['level' => 0, 'target_level' => 0, 'start' => null, 'finish' => null];
        $to = ($r['finish'] !== null ? $r['target_level'] : $r['level']) + 1;
        $rs[] = [
            'code' => $code, 'name' => $d['name'], 'descr' => $d['descr'], 'level' => $r['level'], 'max_level' => $d['max_level'],
            'effect' => $effects[$d['effect']][0] ?? $d['effect'], 'effect_when' => $effects[$d['effect']][1] ?? '',
            'target' => $d['target'] === 'all' ? '전체' : (vg_unit_categories()[$d['target']] ?? $d['target']),
            'value' => $d['value_per_level'], 'start' => $r['start'], 'finish' => $r['finish'], 'target_level' => $r['target_level'],
            'next' => $to <= $d['max_level'] ? ['level' => $to, 'cost' => vg_research_cost($d, $to), 'time' => vg_research_time($d, $to),
                'req' => vg_research_req($d, $to)] : null,
        ];
    }

    return [
        'train' => array_values($groups),
        'home_units' => $home,
        'research' => $rs,
        'smithy_level' => $smithy,
        'research_max' => (int)S('research_max_concurrent'),
        'upkeep' => vg_upkeep_parts($X),
        'worker_build_pct' => vg_worker_build_pct($X['units']),
        'unit_names' => $names,
        'train_ui' => [
            'queue_max' => (int)S('train_queue_max'), 'max_batch' => (int)S('train_max_batch'),
            'cancel_refund_pct' => (float)S('train_cancel_refund_pct'), 'counters_on' => (bool)S('counters_on'),
            'counter_bonus_pct' => (float)S('counter_bonus_pct'),
        ],
    ];
}
