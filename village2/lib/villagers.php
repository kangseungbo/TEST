<?php
// 주민: 고용비 없음, 식량만 먹는다. 생산 건물에 배치하면 그 건물 생산량 보조 보너스.
// - 근무 시간(xp, 시간 단위)으로 레벨이 오른다: Lv N → N+1 에 villager_xp_hours_per_level × N 시간
// - 같은 종류 건물에서 villager_spec_hours 동안 계속 일하면 그 건물 종류로 자동 특화
// - 인구 상한 = 회관 레벨 × villager_cap_per_hall

function vg_load_villagers(int $vid): array
{
    $st = vg_db()->prepare('SELECT * FROM vg_villagers WHERE village_id = ? ORDER BY id');
    $st->execute([$vid]);
    $out = [];
    foreach ($st as $r) {
        $r['id'] = (int)$r['id'];
        $r['level'] = (int)$r['level'];
        $r['xp'] = (float)$r['xp'];
        $r['building_id'] = $r['building_id'] === null ? null : (int)$r['building_id'];
        $r['job_since'] = $r['job_since'] === null ? null : (float)$r['job_since'];
        $out[$r['id']] = $r;
    }
    return $out;
}

function vg_villager_cap(int $hall): int
{
    return max(0, $hall * (int)S('villager_cap_per_hall'));
}

/** 누적 근무 시간 → 레벨 */
function vg_villager_level(float $xp): int
{
    $per = max(0.01, (float)S('villager_xp_hours_per_level'));
    $max = max(1, (int)S('villager_max_level'));
    $lv = 1;
    while ($lv < $max && $xp + 1e-9 >= $per * $lv * ($lv + 1) / 2) $lv++;
    return $lv;
}

/** 다음 레벨까지 필요한 누적 근무 시간 (최대 레벨이면 null) */
function vg_villager_next_xp(int $level): ?float
{
    if ($level >= (int)S('villager_max_level')) return null;
    return (float)S('villager_xp_hours_per_level') * $level * ($level + 1) / 2;
}

/** 주민 1명의 보너스(%) — 배치된 건물 종류 기준 */
function vg_villager_bonus_pct(array $v): float
{
    if ($v['building_id'] === null) return 0.0;
    $pct = (float)S('villager_bonus_base_pct') + (float)S('villager_bonus_per_level_pct') * ($v['level'] - 1);
    if ($v['spec_code'] !== null && $v['spec_code'] === $v['job_code']) $pct += (float)S('villager_spec_bonus_pct');
    return $pct;
}

/** building_id => 보너스 합(%) */
function vg_villager_bonus_map(array $vils): array
{
    $m = [];
    foreach ($vils as $v) {
        if ($v['building_id'] === null) continue;
        $m[$v['building_id']] = ($m[$v['building_id']] ?? 0) + vg_villager_bonus_pct($v);
    }
    return $m;
}

/** 주민이 일할 수 있는 건물인지 */
function vg_villager_workplace(array $b): bool
{
    $d = vg_bdefs()[$b['code']] ?? null;
    return $d && $d['category'] === 'production' && $b['level'] > 0;
}

/** 구간 [t0, t1] 근무: 경험치·레벨·특화 */
function vg_villagers_advance(array &$X, float $t0, float $t1): void
{
    $dt = $t1 - $t0;
    if ($dt <= 0 || !$X['vils']) return;
    $specSec = (float)S('villager_spec_hours') * 3600;
    $defs = vg_bdefs();
    foreach ($X['vils'] as $id => &$v) {
        if ($v['building_id'] === null) continue;
        $b = $X['blds'][$v['building_id']] ?? null;
        if (!$b || !vg_villager_workplace($b)) continue;
        $v['xp'] += $dt / 3600;
        $lv = vg_villager_level($v['xp']);
        if ($lv !== $v['level']) {
            $v['level'] = $lv;
            vg_log($X['village']['id'], 'villager', "{$v['name']} Lv{$lv} 이 되었습니다.", $t1);
        }
        if ($v['job_since'] !== null && $v['spec_code'] !== $v['job_code'] && $t1 - $v['job_since'] >= $specSec) {
            $v['spec_code'] = $v['job_code'];
            $nm = $defs[$v['job_code']]['name'] ?? $v['job_code'];
            vg_log($X['village']['id'], 'villager', "{$v['name']} 이(가) {$nm} 일에 특화되었습니다.", $v['job_since'] + $specSec);
        }
        $X['vdirty'][$id] = true;
    }
    unset($v);
}

function vg_villagers_save(array &$X): void
{
    if (empty($X['vdirty'])) return;
    $up = vg_db()->prepare('UPDATE vg_villagers SET xp = ?, level = ?, spec_code = ? WHERE id = ?');
    foreach (array_keys($X['vdirty']) as $id) {
        if (!isset($X['vils'][$id])) continue;
        $v = $X['vils'][$id];
        $up->execute([$v['xp'], $v['level'], $v['spec_code'], $id]);
    }
    $X['vdirty'] = [];
}

/** 건물이 없어지면 그 건물 주민은 쉬는 상태로 */
function vg_villagers_release_building(int $bid): void
{
    vg_db()->prepare('UPDATE vg_villagers SET building_id = NULL, job_code = NULL, job_since = NULL WHERE building_id = ?')->execute([$bid]);
}

// ───────────── 행동 ─────────────

function vg_act_hire(int $vid): void
{
    vg_tx(function (PDO $pdo) use ($vid) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $cap = vg_villager_cap(vg_hall_level($X['blds']));
        if (count($X['vils']) >= $cap) throw new VgError("인구가 가득 찼습니다 ({$cap}명). 회관 레벨을 올리세요.");
        $used = array_column($X['vils'], 'name');
        $pool = array_values(array_diff(vg_villager_names(), $used)) ?: vg_villager_names();
        $name = $pool[mt_rand(0, count($pool) - 1)];
        if (in_array($name, $used, true)) $name .= count($X['vils']) + 1;
        $pdo->prepare('INSERT INTO vg_villagers (village_id, name, hired_at) VALUES (?, ?, ?)')->execute([$vid, $name, $now]);
        vg_log($vid, 'villager', "주민 {$name} 이(가) 마을에 들어왔습니다.", $now);
    });
}

function vg_act_fire(int $vid, int $vilId): void
{
    vg_tx(function (PDO $pdo) use ($vid, $vilId) {
        $X = vg_settle($vid);
        $v = $X['vils'][$vilId] ?? null;
        if (!$v) throw new VgError('주민을 찾을 수 없습니다.');
        $pdo->prepare('DELETE FROM vg_villagers WHERE id = ?')->execute([$vilId]);
        vg_log($vid, 'villager', "주민 {$v['name']} 이(가) 마을을 떠났습니다.");
    });
}

/** 배치 (bid 0 이면 쉬게 함). 같은 종류 건물로 옮기면 특화 진행 시간이 이어진다 */
function vg_act_assign(int $vid, int $vilId, int $bid): void
{
    vg_tx(function (PDO $pdo) use ($vid, $vilId, $bid) {
        $now = vg_now();
        $X = vg_settle($vid, $now);
        $v = $X['vils'][$vilId] ?? null;
        if (!$v) throw new VgError('주민을 찾을 수 없습니다.');
        if ($bid === 0) {
            $pdo->prepare('UPDATE vg_villagers SET building_id = NULL, job_code = NULL, job_since = NULL WHERE id = ?')->execute([$vilId]);
            return;
        }
        $b = $X['blds'][$bid] ?? null;
        if (!$b || !vg_villager_workplace($b)) throw new VgError('주민은 완공된 생산 건물에만 배치할 수 있습니다.');
        if ($v['building_id'] === $bid) return;
        $max = max(1, (int)S('villager_max_per_building'));
        $there = count(array_filter($X['vils'], fn($o) => $o['building_id'] === $bid));
        if ($there >= $max) throw new VgError("이 건물에는 {$max}명까지 배치할 수 있습니다.");
        $since = $v['job_code'] === $b['code'] && $v['job_since'] !== null ? $v['job_since'] : $now;
        $pdo->prepare('UPDATE vg_villagers SET building_id = ?, job_code = ?, job_since = ? WHERE id = ?')
            ->execute([$bid, $b['code'], $since, $vilId]);
        $nm = vg_bdefs()[$b['code']]['name'] ?? $b['code'];
        vg_log($vid, 'villager', "{$v['name']} → {$nm} 배치", $now);
    });
}

function vg_state_villagers(array $X): array
{
    $defs = vg_bdefs();
    $hall = vg_hall_level($X['blds']);
    $list = [];
    foreach ($X['vils'] as $v) {
        $b = $v['building_id'] !== null ? ($X['blds'][$v['building_id']] ?? null) : null;
        $list[] = [
            'id' => $v['id'], 'name' => $v['name'], 'level' => $v['level'], 'xp' => $v['xp'],
            'xp_prev' => $v['level'] > 1 ? vg_villager_next_xp($v['level'] - 1) : 0.0, 'xp_next' => vg_villager_next_xp($v['level']),
            'building_id' => $v['building_id'], 'job_code' => $v['job_code'], 'job_name' => $v['job_code'] ? ($defs[$v['job_code']]['name'] ?? '') : null,
            'job_since' => $v['job_since'], 'spec_code' => $v['spec_code'],
            'spec_name' => $v['spec_code'] ? ($defs[$v['spec_code']]['name'] ?? $v['spec_code']) : null,
            'bonus_pct' => $b && vg_villager_workplace($b) ? vg_villager_bonus_pct($v) : 0,
        ];
    }
    return [
        'villagers' => $list,
        'pop' => ['count' => count($X['vils']), 'cap' => vg_villager_cap($hall),
            'working' => count(array_filter($X['vils'], fn($v) => $v['building_id'] !== null))],
        'villager_ui' => [
            'max_per_building' => (int)S('villager_max_per_building'), 'food_each' => (float)S('villager_food_each'),
            'spec_hours' => (float)S('villager_spec_hours'), 'base_pct' => (float)S('villager_bonus_base_pct'),
            'per_level_pct' => (float)S('villager_bonus_per_level_pct'), 'spec_pct' => (float)S('villager_spec_bonus_pct'),
        ],
    ];
}
