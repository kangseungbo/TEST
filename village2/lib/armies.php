<?php
// 부대: 마을 병력 일부로 편성해 출진 (분할 발송 없음), 이동·주둔·회군, 일꾼 채집·다리 건설.
//
// 저장은 경로+시각만: path_json = [[q, r, 출발 기준 도착 초], ...], depart_at.
// 현재 위치는 (지금 − depart_at) 으로 계산한다. 첫 칸의 초가 음수이면 이동 중에 경로를 바꾼 것
// (이전 타일에서 이미 그만큼 걸어온 상태). 도착 처리·다리 완성은 마을 정산 때 한꺼번에 한다.

function vg_load_armies(int $vid): array
{
    $pdo = vg_db();
    $st = $pdo->prepare('SELECT * FROM vg_armies WHERE village_id = ? ORDER BY id');
    $st->execute([$vid]);
    $out = [];
    foreach ($st as $a) $out[(int)$a['id']] = vg_army_norm($a);
    if ($out) {
        $in = implode(',', array_keys($out));
        foreach ($pdo->query("SELECT * FROM vg_army_units WHERE army_id IN ($in)") as $u) {
            $out[(int)$u['army_id']]['units'][] = ['owner' => (int)$u['owner_village_id'], 'code' => $u['unit_code'], 'count' => (int)$u['count']];
        }
    }
    return $out;
}

function vg_army_norm(array $a): array
{
    foreach (['id', 'village_id', 'q', 'r'] as $k) $a[$k] = (int)$a[$k];
    $a['returning'] = (int)($a['is_return'] ?? 0);
    foreach (['depart_at', 'arrive_at', 'task_start', 'task_end'] as $k) $a[$k] = $a[$k] === null ? null : (float)$a[$k];
    foreach (['task_q', 'task_r'] as $k) $a[$k] = $a[$k] === null ? null : (int)$a[$k];
    $a['path'] = $a['path_json'] ? json_decode($a['path_json'], true) : null;
    $a['cargo'] = json_decode($a['cargo_json'], true) ?: [];
    $a['units'] = $a['units'] ?? [];
    return $a;
}

/** 마을 소유 병력 중 부대에 나가 있는 수 (유지비 계산용) */
function vg_load_away_units(int $vid): array
{
    $st = vg_db()->prepare('SELECT unit_code, SUM(count) n FROM vg_army_units WHERE owner_village_id = ? GROUP BY unit_code');
    $st->execute([$vid]);
    $out = [];
    foreach ($st as $r) $out[$r['unit_code']] = (int)$r['n'];
    return $out;
}

/** 병종 코드 => 수 */
function vg_army_counts(array $a): array
{
    $c = [];
    foreach ($a['units'] as $u) $c[$u['code']] = ($c[$u['code']] ?? 0) + $u['count'];
    return $c;
}

/** 평원 1타일 초 (가장 느린 병종 기준, 행군 연구·배수 반영) */
function vg_army_sec_per_tile(array $counts, array $research): float
{
    $slow = 0.0;
    $udefs = vg_udefs();
    foreach ($counts as $code => $n) if ($n > 0) $slow = max($slow, $udefs[$code]['speed'] ?? 60);
    if ($slow <= 0) $slow = 60;
    return $slow / (1 + vg_research_bonus($research, 'march_speed_pct') / 100) / max(0.01, (float)S('march_speed_mult'));
}

function vg_return_factor(): float
{
    return 100 / max(1, (float)S('march_return_speed_pct'));
}

/** 타일 목록 → [[q, r, 초]] (첫 칸 = $startOff, 이후 들어가는 타일 비용 × 초) */
function vg_army_timed_path(array $tiles, float $secPerTile, float $factor, float $startOff = 0.0): array
{
    $all = vg_map_tiles();
    $t = $startOff;
    $out = [[$tiles[0][0], $tiles[0][1], round($t, 3)]];
    for ($i = 1; $i < count($tiles); $i++) {
        $t += vg_tile_cost($all[vg_tkey($tiles[$i][0], $tiles[$i][1])]) * $secPerTile * $factor;
        $out[] = [$tiles[$i][0], $tiles[$i][1], round($t, 3)];
    }
    return $out;
}

/**
 * 현재 위치. 반환: cur(마지막으로 도착한 타일), next(향하는 타일|null), t_cur/t_next(절대 시각), frac(0~1)
 */
function vg_army_pos(array $a, float $now): array
{
    if ($a['state'] !== 'moving' || !$a['path']) return ['cur' => [$a['q'], $a['r']], 'next' => null, 't_cur' => null, 't_next' => null, 'frac' => 0];
    $p = $a['path'];
    $el = $now - $a['depart_at'];
    $i = 0;
    while ($i + 1 < count($p) && $p[$i + 1][2] <= $el) $i++;
    if ($i + 1 >= count($p)) return ['cur' => [$p[$i][0], $p[$i][1]], 'next' => null, 't_cur' => $a['depart_at'] + $p[$i][2], 't_next' => null, 'frac' => 0, 'done' => true];
    $a0 = $p[$i][2]; $b0 = $p[$i + 1][2];
    return ['cur' => [$p[$i][0], $p[$i][1]], 'next' => [$p[$i + 1][0], $p[$i + 1][1]],
        't_cur' => $a['depart_at'] + $a0, 't_next' => $a['depart_at'] + $b0, 'frac' => $b0 > $a0 ? ($el - $a0) / ($b0 - $a0) : 1];
}

function vg_army_workers(array $a): int
{
    return vg_army_counts($a)['worker'] ?? 0;
}

function vg_army_cargo_cap(array $a, array $research): float
{
    $cap = 0.0;
    $udefs = vg_udefs();
    foreach (vg_army_counts($a) as $code => $n) $cap += $n * ($udefs[$code]['carry'] ?? 0);
    return $cap * (1 + vg_research_bonus($research, 'carry_pct') / 100);
}

/** 이 타일에서 채집하는 자원 (없으면 null) */
function vg_tile_gather_res(?array $t): ?string
{
    if (!$t) return null;
    $f = vg_map_features()[$t['f']] ?? null;
    if ($f && $f[1]) return $f[1];
    if ($t['t'] === 'forest' && $t['f'] === '') return 'wood';
    return null;
}

/** 지금까지 실은 짐 (채집 중이면 진행분 포함) */
function vg_army_cargo_now(array $a, array $research, float $now): array
{
    $cargo = $a['cargo'];
    if ($a['task'] === 'gather' && $a['task_start'] !== null) {
        $res = vg_tile_gather_res(vg_map_tiles()[vg_tkey($a['q'], $a['r'])] ?? null);
        if ($res) {
            $room = max(0.0, vg_army_cargo_cap($a, $research) - array_sum($cargo));
            $got = min($room, vg_army_workers($a) * (float)S('gather_per_worker_hour') * max(0, $now - $a['task_start']) / 3600);
            $cargo[$res] = ($cargo[$res] ?? 0) + $got;
        }
    }
    return $cargo;
}

function vg_army_store(array $a): void
{
    vg_db()->prepare('UPDATE vg_armies SET state = ?, q = ?, r = ?, path_json = ?, depart_at = ?, arrive_at = ?, is_return = ?,
        task = ?, task_q = ?, task_r = ?, task_start = ?, task_end = ?, cargo_json = ? WHERE id = ?')
        ->execute([$a['state'], $a['q'], $a['r'], $a['path'] ? json_encode($a['path']) : null, $a['depart_at'], $a['arrive_at'],
            $a['returning'], $a['task'], $a['task_q'], $a['task_r'], $a['task_start'], $a['task_end'],
            json_encode(array_map(fn($x) => round($x, 3), $a['cargo'])), $a['id']]);
}

function vg_army_delete(int $aid): void
{
    vg_db()->prepare('DELETE FROM vg_army_units WHERE army_id = ?')->execute([$aid]);
    vg_db()->prepare('DELETE FROM vg_armies WHERE id = ?')->execute([$aid]);
}

/** 정산 끝에서: 도착·귀환·다리 완성 처리 ($X 는 잠긴 마을 정산 상태) */
function vg_armies_resolve(array &$X, float $now): void
{
    $vid = (int)$X['village']['id'];
    $home = [$X['village']['q'] === null ? null : (int)$X['village']['q'], $X['village']['r'] === null ? null : (int)$X['village']['r']];
    foreach ($X['armies'] as $aid => &$a) {
        if ($a['task'] === 'bridge' && $a['task_end'] !== null && $a['task_end'] <= $now) {
            $t = vg_map_tiles()[vg_tkey($a['task_q'], $a['task_r'])] ?? null;
            if ($t && $t['t'] === 'river' && $t['f'] === '') {
                vg_db()->prepare("UPDATE vg_map_tiles SET feature = 'bridge' WHERE q = ? AND r = ?")->execute([$a['task_q'], $a['task_r']]);
                vg_map_tiles(true);
                vg_map_bump();
                vg_log($vid, 'army', "{$a['name']} 이(가) ({$a['task_q']}, {$a['task_r']}) 에 다리를 놓았습니다.", $a['task_end']);
            }
            $a['task'] = '';
            $a['task_q'] = $a['task_r'] = null;
            $a['task_start'] = $a['task_end'] = null;
            vg_army_store($a);
        }
        if ($a['state'] !== 'moving' || $a['arrive_at'] === null || $a['arrive_at'] > $now) continue;
        $last = end($a['path']);
        if ($home[0] !== null && (int)$last[0] === $home[0] && (int)$last[1] === $home[1]) {
            // 귀환: 병력은 마을로, 짐은 창고로
            foreach ($a['units'] as $u) {
                if ($u['owner'] !== $vid) continue;
                $X['units'][$u['code']] = ($X['units'][$u['code']] ?? 0) + $u['count'];
                $X['udirty'][$u['code']] = true;
                $X['away'][$u['code']] = max(0, ($X['away'][$u['code']] ?? 0) - $u['count']);
            }
            $cap = vg_storage_cap($X['blds']);
            $got = [];
            foreach ($a['cargo'] as $r => $amt) {
                if (!in_array($r, VG_RES, true) || $amt <= 0) continue;
                $before = $X['res'][$r];
                $X['res'][$r] = vg_apply_delta($before, $amt, vg_res_cap($r, $cap));
                $got[$r] = floor($X['res'][$r] - $before);
            }
            vg_army_delete($aid);
            vg_log($vid, 'army', "{$a['name']} 귀환" . ($got ? ' (짐: ' . vg_fmt_cost($got) . ')' : ''), $a['arrive_at']);
            unset($X['armies'][$aid]);
            continue;
        }
        $a['state'] = 'stationed';
        $a['q'] = (int)$last[0];
        $a['r'] = (int)$last[1];
        $a['path'] = null;
        vg_log($vid, 'army', "{$a['name']} 이(가) ({$a['q']}, {$a['r']}) 에 도착해 주둔합니다.", $a['arrive_at']);
        $a['depart_at'] = $a['arrive_at'] = null;
        $a['returning'] = 0;
        vg_army_store($a);
    }
    unset($a);
}

/** 맵 조회 전: 도착·다리 완성 시각이 지난 부대의 마을을 정산 (마을 잠금 순서 유지) */
function vg_armies_resolve_due(): void
{
    $now = vg_now();
    $st = vg_db()->prepare("SELECT DISTINCT village_id FROM vg_armies WHERE (state = 'moving' AND arrive_at <= ?) OR (task = 'bridge' AND task_end <= ?)");
    $st->execute([$now, $now]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) vg_tx(fn() => vg_settle((int)$vid, $now));
}

/** 맵 재생성 등: 모든 부대를 즉시 마을로 (짐은 사라짐) */
function vg_armies_all_home(): void
{
    $pdo = vg_db();
    $pdo->exec('INSERT INTO vg_village_units (village_id, unit_code, count)
        SELECT owner_village_id, unit_code, SUM(count) FROM vg_army_units GROUP BY owner_village_id, unit_code
        ON DUPLICATE KEY UPDATE count = count + VALUES(count)');
    $pdo->exec('DELETE FROM vg_army_units');
    $pdo->exec('DELETE FROM vg_armies');
}

// ───────────── 행동 ─────────────

function vg_army_of(array $X, int $aid): array
{
    $a = $X['armies'][$aid] ?? null;
    if (!$a) throw new VgError('부대를 찾을 수 없습니다.');
    return $a;
}

function vg_home_of(array $X): array
{
    if ($X['village']['q'] === null) throw new VgError('마을이 아직 맵에 놓이지 않았습니다.');
    return [(int)$X['village']['q'], (int)$X['village']['r']];
}

/** 이동 중 과제(채집·다리) 정리: 채집분은 짐으로 확정, 다리는 취소 */
function vg_army_stop_task(array &$a, array $research, float $now): ?string
{
    $msg = null;
    if ($a['task'] === 'gather') $a['cargo'] = vg_army_cargo_now($a, $research, $now);
    if ($a['task'] === 'bridge') $msg = '다리 건설을 멈췄습니다 (나무는 돌려받지 못함).';
    $a['task'] = '';
    $a['task_q'] = $a['task_r'] = null;
    $a['task_start'] = $a['task_end'] = null;
    return $msg;
}

/** 부대 경로 설정 (지금 위치에서 목적지까지). 목적지가 자기 마을이면 회군 속도 */
function vg_army_route(array &$a, array $X, int $q, int $r, float $now, bool $forceReturn = false): void
{
    $vid = (int)$X['village']['id'];
    $home = vg_home_of($X);
    $pos = vg_army_pos($a, $now);
    $start = $pos['next'] ?? $pos['cur'];
    $tiles = vg_astar($start[0], $start[1], $q, $r, $vid);
    if ($tiles === null) throw new VgError('그곳까지 갈 수 있는 길이 없습니다. (강은 다리가 있어야 건널 수 있습니다)');
    $toHome = ($q === $home[0] && $r === $home[1]);
    $returning = $toHome || $forceReturn;
    $spt = vg_army_sec_per_tile(vg_army_counts($a), $X['research']);
    $factor = $returning ? vg_return_factor() : 1.0;
    if ($pos['next'] !== null) {
        // 가던 칸까지는 원래 속도로 마저 가고, 거기서 새 길
        $path = vg_army_timed_path($tiles, $spt, $factor, $pos['t_next'] - $now);
        array_unshift($path, [$pos['cur'][0], $pos['cur'][1], round($pos['t_cur'] - $now, 3)]);
    } else {
        $path = vg_army_timed_path($tiles, $spt, $factor, 0.0);
    }
    if (count($path) === 1 && !$toHome) { // 이미 그 자리
        $a['state'] = 'stationed';
        $a['q'] = $path[0][0]; $a['r'] = $path[0][1];
        $a['path'] = null; $a['depart_at'] = $a['arrive_at'] = null; $a['returning'] = 0;
        return;
    }
    $a['state'] = 'moving';
    $a['path'] = $path;
    $a['depart_at'] = $now;
    $a['arrive_at'] = $now + end($path)[2];
    $a['returning'] = $returning ? 1 : 0;
}

/** 편성·출진 */
function vg_act_army_create(int $vid, array $units, int $q, int $r): int
{
    return vg_tx(function (PDO $pdo) use ($vid, $units, $q, $r) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $home = vg_home_of($X);
        $max = max(1, (int)S('army_max'));
        if (count($X['armies']) >= $max) throw new VgError("부대는 {$max}개까지 내보낼 수 있습니다.");
        $pick = [];
        foreach ($units as $code => $n) {
            $n = (int)$n;
            if ($n <= 0) continue;
            if (!isset(vg_udefs()[$code])) throw new VgError('알 수 없는 병종입니다.');
            $have = $X['units'][$code] ?? 0;
            if ($n > $have) throw new VgError((vg_udefs()[$code]['name']) . " 이(가) {$have}명뿐입니다.");
            $pick[$code] = $n;
        }
        if (!$pick) throw new VgError('부대에 넣을 병력을 고르세요.');
        if ($q === $home[0] && $r === $home[1]) throw new VgError('목적지를 마을 밖으로 고르세요.');
        $used = array_map(fn($a) => $a['name'], $X['armies']);
        for ($i = 1; in_array("{$i}부대", $used, true); $i++);
        $a = ['id' => 0, 'village_id' => $vid, 'name' => "{$i}부대", 'state' => 'stationed', 'q' => $home[0], 'r' => $home[1],
            'path' => null, 'depart_at' => null, 'arrive_at' => null, 'returning' => 0, 'task' => '', 'task_q' => null, 'task_r' => null,
            'task_start' => null, 'task_end' => null, 'cargo' => [], 'units' => []];
        foreach ($pick as $code => $n) $a['units'][] = ['owner' => $vid, 'code' => $code, 'count' => $n];
        vg_army_route($a, $X, $q, $r, $now);
        $pdo->prepare('INSERT INTO vg_armies (village_id, name, state, q, r, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$vid, $a['name'], $a['state'], $a['q'], $a['r'], $now]);
        $a['id'] = (int)$pdo->lastInsertId();
        vg_army_store($a);
        $ins = $pdo->prepare('INSERT INTO vg_army_units (army_id, owner_village_id, unit_code, count) VALUES (?, ?, ?, ?)');
        $take = $pdo->prepare('UPDATE vg_village_units SET count = count - ? WHERE village_id = ? AND unit_code = ?');
        foreach ($pick as $code => $n) {
            $ins->execute([$a['id'], $vid, $code, $n]);
            $take->execute([$n, $vid, $code]);
        }
        $total = array_sum($pick);
        vg_log($vid, 'army', "{$a['name']} 출진 ({$total}명 → ({$q}, {$r}))", $now);
        return $a['id'];
    });
}

function vg_act_army_move(int $vid, int $aid, int $q, int $r, bool $recall = false): void
{
    vg_tx(function () use ($vid, $aid, $q, $r, $recall) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $a = vg_army_of($X, $aid);
        if ($recall) [$q, $r] = vg_home_of($X);
        vg_army_stop_task($a, $X['research'], $now);
        vg_army_route($a, $X, $q, $r, $now, $recall);
        vg_army_store($a);
        vg_log($vid, 'army', $recall ? "{$a['name']} 회군" : "{$a['name']} 이동 → ({$q}, {$r})", $now);
    });
}

function vg_act_army_gather(int $vid, int $aid, bool $on): void
{
    vg_tx(function () use ($vid, $aid, $on) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $a = vg_army_of($X, $aid);
        if (!$on) {
            if ($a['task'] !== 'gather') throw new VgError('채집 중이 아닙니다.');
            vg_army_stop_task($a, $X['research'], $now);
            vg_army_store($a);
            return;
        }
        if ($a['state'] !== 'stationed') throw new VgError('주둔 중인 부대만 채집할 수 있습니다.');
        if ($a['task'] !== '') throw new VgError('이미 다른 일을 하고 있습니다.');
        $res = vg_tile_gather_res(vg_map_tiles()[vg_tkey($a['q'], $a['r'])] ?? null);
        if (!$res) throw new VgError('여기서는 채집할 것이 없습니다. (광산·농장 거점, 항구, 숲)');
        if (vg_army_workers($a) <= 0) throw new VgError('채집은 일꾼이 합니다. 부대에 일꾼을 넣으세요.');
        if (vg_army_cargo_cap($a, $X['research']) - array_sum($a['cargo']) <= 0) throw new VgError('더 실을 자리가 없습니다. 마을로 돌아가세요.');
        $a['task'] = 'gather';
        $a['task_start'] = $now;
        vg_army_store($a);
        vg_log($vid, 'army', "{$a['name']} 채집 시작 (" . vg_res_names()[$res] . ')', $now);
    });
}

function vg_act_army_bridge(int $vid, int $aid, int $q, int $r): void
{
    vg_tx(function (PDO $pdo) use ($vid, $aid, $q, $r) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $a = vg_army_of($X, $aid);
        if ($a['state'] !== 'stationed') throw new VgError('주둔 중인 부대만 다리를 놓을 수 있습니다.');
        if ($a['task'] !== '') throw new VgError('이미 다른 일을 하고 있습니다.');
        if (vg_hex_dist($a['q'], $a['r'], $q, $r) !== 1) throw new VgError('부대 바로 옆 강 타일에만 다리를 놓을 수 있습니다.');
        $t = vg_map_tiles()[vg_tkey($q, $r)] ?? null;
        if (!$t || $t['t'] !== 'river' || $t['f'] !== '') throw new VgError('다리를 놓을 수 있는 강이 아닙니다.');
        $busy = $pdo->prepare("SELECT COUNT(*) FROM vg_armies WHERE task = 'bridge' AND task_q = ? AND task_r = ?");
        $busy->execute([$q, $r]);
        if ($busy->fetchColumn() > 0) throw new VgError('다른 부대가 이미 다리를 놓고 있습니다.');
        $min = max(1, (int)S('bridge_workers_min'));
        $w = vg_army_workers($a);
        if ($w < $min) throw new VgError("다리를 놓으려면 일꾼이 {$min}명 이상 필요합니다 (지금 {$w}명).");
        $res = $X['res'];
        vg_pay($vid, $res, ['wood' => (int)S('bridge_wood')]);
        $time = (float)S('bridge_time_sec') * max(0.25, $min / $w);
        $a['task'] = 'bridge';
        $a['task_q'] = $q; $a['task_r'] = $r;
        $a['task_start'] = $now; $a['task_end'] = $now + $time;
        vg_army_store($a);
        vg_log($vid, 'army', "{$a['name']} ({$q}, {$r}) 다리 건설 시작", $now);
    });
}

// ───────────── 화면 상태 ─────────────

/** 맵에 보일 전체 부대 (내 부대는 자세히) */
function vg_state_armies(int $viewerVid): array
{
    $pdo = vg_db();
    $now = vg_now();
    $vnames = [];
    foreach ($pdo->query('SELECT id, name, user_name FROM vg_villages') as $v) $vnames[(int)$v['id']] = [$v['name'], $v['user_name']];
    $rows = [];
    foreach ($pdo->query('SELECT * FROM vg_armies ORDER BY id') as $a) $rows[(int)$a['id']] = vg_army_norm($a);
    if ($rows) {
        foreach ($pdo->query('SELECT * FROM vg_army_units WHERE army_id IN (' . implode(',', array_keys($rows)) . ')') as $u) {
            $rows[(int)$u['army_id']]['units'][] = ['owner' => (int)$u['owner_village_id'], 'code' => $u['unit_code'], 'count' => (int)$u['count']];
        }
    }
    $myResearch = vg_load_research($viewerVid);
    $udefs = vg_udefs();
    $out = [];
    foreach ($rows as $a) {
        $counts = vg_army_counts($a);
        $mine = $a['village_id'] === $viewerVid;
        arsort($counts);
        $main = key($counts);
        foreach ($counts as $c => $n) if ($c !== 'worker') { $main = $c; break; }
        $item = [
            'id' => $a['id'], 'village_id' => $a['village_id'], 'mine' => $mine, 'name' => $a['name'],
            'village' => $vnames[$a['village_id']][0] ?? '', 'owner' => $vnames[$a['village_id']][1] ?? '',
            'state' => $a['state'], 'returning' => $a['returning'], 'q' => $a['q'], 'r' => $a['r'],
            'path' => $a['path'], 'depart_at' => $a['depart_at'], 'arrive_at' => $a['arrive_at'],
            'total' => array_sum($counts), 'main' => $main, 'main_category' => $udefs[$main]['category'] ?? '',
            'task' => $a['task'],
        ];
        if ($mine) {
            $research = $myResearch;
            $item += [
                'units' => array_map(fn($c, $n) => ['code' => $c, 'name' => $udefs[$c]['name'] ?? $c, 'count' => $n], array_keys($counts), $counts),
                'sec_per_tile' => vg_army_sec_per_tile($counts, $research),
                'workers' => $counts['worker'] ?? 0,
                'cargo' => vg_army_cargo_now($a, $research, $now), 'cargo_cap' => vg_army_cargo_cap($a, $research),
                'gather_rate' => ($counts['worker'] ?? 0) * (float)S('gather_per_worker_hour'),
                'task_q' => $a['task_q'], 'task_r' => $a['task_r'], 'task_start' => $a['task_start'], 'task_end' => $a['task_end'],
            ];
        }
        $out[] = $item;
    }
    return ['now' => $now, 'armies' => $out];
}
