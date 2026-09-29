<?php
// 관리자 POST 처리. 반환값은 완료 메시지.
defined('VG_ADMIN') || exit;

function vg_admin_action(string $do, array $P, array $F): string
{
    $pdo = vg_db();
    switch ($do) {
        case 'save_settings': return vg_admin_save_settings($P['s'] ?? []);
        case 'save_bdefs':    return vg_admin_save_bdefs($P['d'] ?? []);
        case 'add_bdef':      return vg_admin_add_bdef($P);
        case 'delete_bdef':   return vg_admin_delete_bdef((string)($P['code'] ?? ''));
        case 'player_save':   return vg_admin_player_save((int)($P['vid'] ?? 0), $P);
        case 'player_add_building':
            return vg_admin_player_add_building((int)($P['vid'] ?? 0), (string)($P['code'] ?? ''), (int)($P['slot'] ?? 0), (int)($P['level'] ?? 1));
        case 'player_finish': return vg_admin_player_finish((int)($P['vid'] ?? 0));
        case 'player_delete': return vg_admin_player_delete((int)($P['vid'] ?? 0));
        case 'reset':         return vg_admin_reset((string)($P['what'] ?? ''), (string)($P['confirm'] ?? ''));
        case 'save_udefs':    return vg_admin_save_udefs($P['u'] ?? []);
        case 'add_udef':      return vg_admin_add_udef($P);
        case 'delete_udef':   return vg_admin_delete_udef((string)($P['code'] ?? ''));
        case 'save_rdefs':    return vg_admin_save_rdefs($P['r'] ?? []);
        case 'add_rdef':      return vg_admin_add_rdef($P);
        case 'delete_rdef':   return vg_admin_delete_rdef((string)($P['code'] ?? ''));
    }
    throw new VgError('알 수 없는 요청입니다.');
}

function vg_admin_save_settings(array $vals): string
{
    $pdo = vg_db();
    $meta = [];
    foreach ($pdo->query('SELECT skey, stype, label FROM vg_settings') as $r) $meta[$r['skey']] = $r;
    $up = $pdo->prepare('UPDATE vg_settings SET sval = ? WHERE skey = ?');
    $n = 0;
    foreach ($vals as $k => $v) {
        if (!isset($meta[$k])) continue;
        $v = trim((string)$v);
        switch ($meta[$k]['stype']) {
            case 'int':
                if (!preg_match('/^-?\d+$/', $v)) throw new VgError("'{$meta[$k]['label']}' 은(는) 정수여야 합니다.");
                break;
            case 'float':
                if (!is_numeric($v)) throw new VgError("'{$meta[$k]['label']}' 은(는) 숫자여야 합니다.");
                break;
            case 'bool':
                $v = $v === '1' ? '1' : '0';
                break;
        }
        if ($k === 'grid_expand_levels' && $v !== '' && !preg_match('/^\s*\d+(\s*,\s*\d+)*\s*$/', $v)) {
            throw new VgError('레벨별 칸 수는 숫자를 쉼표로 구분해 적으세요 (예: 4,6,8).');
        }
        $up->execute([$v, $k]);
        $n += $up->rowCount();
    }
    return "설정 {$n}개를 바꿨습니다.";
}

function vg_admin_bdef_row(array $d): array
{
    $cats = vg_building_categories();
    $cost = [];
    foreach (VG_RES as $r) {
        $a = (float)($d['cost'][$r] ?? 0);
        if ($a > 0) $cost[$r] = $a == floor($a) ? (int)$a : $a;
    }
    $cat = (string)($d['category'] ?? 'production');
    if (!isset($cats[$cat])) throw new VgError('분류가 올바르지 않습니다.');
    $prod = (string)($d['produces'] ?? '');
    if ($prod !== '' && !in_array($prod, VG_RES, true)) throw new VgError('생산 자원이 올바르지 않습니다.');
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') throw new VgError('건물 이름을 입력하세요.');
    return [
        'name' => mb_substr($name, 0, 50), 'category' => $cat, 'produces' => $prod,
        'base_rate' => max(0, (float)($d['base_rate'] ?? 0)), 'rate_growth' => max(0.01, (float)($d['rate_growth'] ?? 1)),
        'cost_json' => json_encode($cost), 'cost_growth' => max(0.01, (float)($d['cost_growth'] ?? 1.5)),
        'base_time' => max(1, (float)($d['base_time'] ?? 30)), 'time_growth' => max(0.01, (float)($d['time_growth'] ?? 1.5)),
        'max_level' => max(1, (int)($d['max_level'] ?? 20)), 'multi' => !empty($d['multi']) ? 1 : 0,
        'req_hall' => max(1, (int)($d['req_hall'] ?? 1)), 'sort_order' => (int)($d['sort_order'] ?? 0),
        'enabled' => !empty($d['enabled']) ? 1 : 0, 'descr' => mb_substr(trim((string)($d['descr'] ?? '')), 0, 500),
    ];
}

function vg_admin_save_bdefs(array $rows): string
{
    $pdo = vg_db();
    $n = 0;
    $link = $pdo->prepare('UPDATE vg_unit_defs SET train_bld = ? WHERE code = ?');
    $unlink = $pdo->prepare("UPDATE vg_unit_defs SET train_bld = '' WHERE code = ? AND train_bld = ?");
    $udefs = vg_udefs(true);
    $links = 0;
    foreach ($rows as $code => $d) {
        $row = vg_admin_bdef_row($d);
        if ($code === 'hall') { $row['category'] = 'hall'; $row['enabled'] = 1; }
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        $st = $pdo->prepare("UPDATE vg_building_defs SET $sets WHERE code = ?");
        $st->execute([...array_values($row), $code]);
        $n += $st->rowCount();
        // 병종 ↔ 훈련 건물 연결 (병종 탭에서도 같은 값을 편집)
        if (!empty($d['train_link'])) {
            $want = array_map('strval', $d['train_units'] ?? []);
            foreach ($udefs as $uc => $u) {
                if (in_array($uc, $want, true)) { if ($u['train_bld'] !== $code) { $link->execute([$code, $uc]); $links++; } }
                elseif ($u['train_bld'] === $code) { $unlink->execute([$uc, $code]); $links++; }
            }
        }
    }
    return "건물 정의 {$n}개를 바꿨습니다." . ($links ? " (훈련 병종 연결 {$links}건 변경)" : '');
}

function vg_admin_add_bdef(array $P): string
{
    $code = strtolower(trim((string)($P['code'] ?? '')));
    if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code)) throw new VgError('코드는 영문 소문자로 시작하는 영문·숫자·_ 2~31자');
    $row = vg_admin_bdef_row($P);
    if ($row['category'] === 'hall') throw new VgError('마을회관은 하나만 있을 수 있습니다.');
    $row = ['code' => $code] + $row;
    $st = vg_db()->prepare('INSERT IGNORE INTO vg_building_defs (' . implode(',', array_keys($row)) . ') VALUES ('
        . implode(',', array_fill(0, count($row), '?')) . ')');
    $st->execute(array_values($row));
    if (!$st->rowCount()) throw new VgError('이미 있는 코드입니다.');
    return "건물 '{$row['name']}' 을(를) 추가했습니다.";
}

function vg_admin_delete_bdef(string $code): string
{
    if ($code === 'hall') throw new VgError('마을회관은 지울 수 없습니다.');
    $pdo = vg_db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM vg_buildings WHERE code = ?');
    $st->execute([$code]);
    if ($st->fetchColumn() > 0) throw new VgError('이 건물을 가진 마을이 있어 지울 수 없습니다. 대신 사용 안 함으로 바꾸세요.');
    $pdo->prepare('DELETE FROM vg_building_defs WHERE code = ?')->execute([$code]);
    return '건물 정의를 지웠습니다.';
}

/** 자원·건물 레벨 직접 변경. 먼저 정산해서 그 시점 기준으로 덮어쓴다 */
function vg_admin_player_save(int $vid, array $P): string
{
    vg_tx(function (PDO $pdo) use ($vid, $P) {
        $X = vg_settle($vid);
        ['res' => $res, 'blds' => $blds] = $X;
        foreach (VG_RES as $r) {
            if (isset($P['res'][$r]) && is_numeric($P['res'][$r])) $res[$r] = max(0, (float)$P['res'][$r]);
        }
        vg_save_res($vid, $res);
        $defs = vg_bdefs();
        foreach ($P['lv'] ?? [] as $bid => $lv) {
            $bid = (int)$bid;
            if (!isset($blds[$bid]) || !is_numeric($lv)) continue;
            $b = $blds[$bid];
            $lv = (int)$lv;
            $del = !empty($P['del'][$bid]);
            if ($lv === $b['level'] && !$del) continue;
            if ($lv <= 0 || $del) {
                if ($b['code'] === 'hall') throw new VgError('마을회관은 지울 수 없고 Lv1 이상이어야 합니다.');
                $pdo->prepare('DELETE FROM vg_buildings WHERE id = ?')->execute([$bid]);
                vg_villagers_release_building($bid);
                continue;
            }
            $lv = min($lv, $defs[$b['code']]['max_level'] ?? 99);
            // 레벨을 직접 바꾸면 진행 중 공사는 없앤다 (비용 환급 없음)
            $pdo->prepare('UPDATE vg_buildings SET level = ?, target_level = ?, build_start = NULL, build_finish = NULL WHERE id = ?')
                ->execute([$lv, $lv, $bid]);
        }
        $up = $pdo->prepare('INSERT INTO vg_village_units (village_id, unit_code, count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE count = VALUES(count)');
        foreach ($P['units'] ?? [] as $code => $n) {
            if (!isset(vg_udefs()[$code]) || !is_numeric($n)) continue;
            $up->execute([$vid, $code, max(0, (int)$n)]);
        }
        $rup = $pdo->prepare('INSERT INTO vg_research (village_id, code, level, target_level) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE level = VALUES(level), target_level = VALUES(level), start = NULL, finish = NULL');
        foreach ($P['research'] ?? [] as $code => $lv) {
            $d = vg_rdefs()[$code] ?? null;
            if (!$d || !is_numeric($lv)) continue;
            $lv = max(0, min($d['max_level'], (int)$lv));
            if ($lv === ($X['research'][$code]['level'] ?? 0)) continue;
            $rup->execute([$vid, $code, $lv, $lv]);
        }
        if (isset($P['vname']) && trim($P['vname']) !== '') vg_act_rename($vid, (string)$P['vname']);
        vg_log($vid, 'admin', '관리자가 마을 정보를 수정했습니다.');
    });
    return '플레이어 마을을 수정했습니다.';
}

function vg_admin_player_add_building(int $vid, string $code, int $slot, int $level): string
{
    vg_tx(function (PDO $pdo) use ($vid, $code, $slot, $level) {
        ['blds' => $blds] = vg_settle($vid);
        $def = vg_bdef($code);
        if ($def['category'] === 'hall') throw new VgError('마을회관은 추가할 수 없습니다.');
        if ($def['category'] === 'wall') $slot = VG_SLOT_WALL;
        elseif ($slot < 1 || $slot > 16) throw new VgError('칸 번호는 1~16 입니다.');
        foreach ($blds as $b) if ($b['slot'] === $slot) throw new VgError("{$slot}번 칸에 이미 건물이 있습니다.");
        $level = max(1, min($def['max_level'], $level));
        $pdo->prepare('INSERT INTO vg_buildings (village_id, code, slot, level, target_level) VALUES (?, ?, ?, ?, ?)')
            ->execute([$vid, $code, $slot, $level, $level]);
        vg_log($vid, 'admin', "관리자가 {$def['name']} Lv{$level} 을(를) 지어 주었습니다.");
    });
    return '건물을 추가했습니다.';
}

function vg_admin_player_finish(int $vid): string
{
    $n = vg_tx(function (PDO $pdo) use ($vid) {
        $now = vg_now();
        vg_settle($vid, $now);
        $st = $pdo->prepare('UPDATE vg_buildings SET build_finish = ? WHERE village_id = ? AND build_finish IS NOT NULL');
        $st->execute([$now, $vid]);
        vg_settle($vid, $now);
        return $st->rowCount();
    });
    return "공사 {$n}건을 즉시 완료했습니다.";
}

function vg_admin_delete_village_rows(PDO $pdo, ?int $vid): void
{
    $w = $vid === null ? '' : ' WHERE village_id = ' . (int)$vid;
    foreach (['vg_buildings', 'vg_resources', 'vg_logs', 'vg_villagers', 'vg_village_units', 'vg_train_queue', 'vg_research'] as $t) {
        $pdo->exec("DELETE FROM $t$w");
    }
    $pdo->exec('DELETE FROM vg_villages' . ($vid === null ? '' : ' WHERE id = ' . (int)$vid));
}

function vg_admin_player_delete(int $vid): string
{
    vg_tx(fn(PDO $pdo) => vg_admin_delete_village_rows($pdo, $vid));
    return '마을을 삭제했습니다. 그 플레이어가 다시 접속하면 새 마을이 만들어집니다.';
}

function vg_admin_reset(string $what, string $confirm): string
{
    if ($confirm !== '초기화') throw new VgError("확인란에 '초기화' 라고 입력하세요.");
    $pdo = vg_db();
    switch ($what) {
        case 'villages':
            vg_tx(fn(PDO $pdo) => vg_admin_delete_village_rows($pdo, null));
            return '모든 마을을 지웠습니다.';
        case 'settings':
            $st = $pdo->prepare('UPDATE vg_settings SET sval = ? WHERE skey = ?');
            foreach (vg_setting_defs() as $k => $d) $st->execute([vg_setting_to_str($d[0]), $k]);
            return '설정을 기본값으로 되돌렸습니다.';
        case 'bdefs':
            vg_insert_building_defs($pdo, true);
            return '기본 건물 정의를 기본값으로 되돌렸습니다. (직접 추가한 건물은 그대로)';
        case 'udefs':
            vg_insert_unit_defs($pdo, true);
            return '기본 병종 정의를 기본값으로 되돌렸습니다. (직접 추가한 병종은 그대로)';
        case 'rdefs':
            vg_insert_research_defs($pdo, true);
            return '기본 연구 정의를 기본값으로 되돌렸습니다. (직접 추가한 연구는 그대로)';
    }
    throw new VgError('알 수 없는 초기화 항목입니다.');
}

// ───────────── 병종 ─────────────

function vg_admin_udef_row(array $d): array
{
    $cats = vg_unit_categories();
    $cost = [];
    foreach (VG_RES as $r) {
        $a = (float)($d['cost'][$r] ?? 0);
        if ($a > 0) $cost[$r] = $a == floor($a) ? (int)$a : $a;
    }
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') throw new VgError('병종 이름을 입력하세요.');
    $cat = (string)($d['category'] ?? 'infantry');
    if (!isset($cats[$cat])) throw new VgError('병종 분류가 올바르지 않습니다.');
    $bld = (string)($d['train_bld'] ?? '');
    if ($bld !== '' && !isset(vg_bdefs()[$bld])) throw new VgError('훈련 건물이 올바르지 않습니다.');
    $counters = array_values(array_intersect(array_map('strval', $d['counters'] ?? []), array_keys(vg_udefs())));
    return [
        'name' => mb_substr($name, 0, 50), 'train_bld' => $bld, 'req_level' => max(1, (int)($d['req_level'] ?? 1)),
        'category' => $cat, 'cost_json' => json_encode($cost), 'train_time' => max(1, (float)($d['train_time'] ?? 30)),
        'upkeep' => max(0, (float)($d['upkeep'] ?? 0)), 'attack' => max(0, (float)($d['attack'] ?? 0)),
        'defense' => max(0, (float)($d['defense'] ?? 0)), 'speed' => max(1, (float)($d['speed'] ?? 60)),
        'carry' => max(0, (float)($d['carry'] ?? 0)), 'ranged' => !empty($d['ranged']) ? 1 : 0,
        'counters' => implode(',', $counters), 'sort_order' => (int)($d['sort_order'] ?? 0),
        'enabled' => !empty($d['enabled']) ? 1 : 0, 'descr' => mb_substr(trim((string)($d['descr'] ?? '')), 0, 500),
    ];
}

function vg_admin_save_udefs(array $rows): string
{
    $pdo = vg_db();
    $n = 0;
    foreach ($rows as $code => $d) {
        $row = vg_admin_udef_row($d);
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        $st = $pdo->prepare("UPDATE vg_unit_defs SET $sets WHERE code = ?");
        $st->execute([...array_values($row), $code]);
        $n += $st->rowCount();
    }
    return "병종 정의 {$n}개를 바꿨습니다.";
}

function vg_admin_add_udef(array $P): string
{
    $code = strtolower(trim((string)($P['code'] ?? '')));
    if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code)) throw new VgError('코드는 영문 소문자로 시작하는 영문·숫자·_ 2~31자');
    $row = ['code' => $code] + vg_admin_udef_row($P);
    $st = vg_db()->prepare('INSERT IGNORE INTO vg_unit_defs (' . implode(',', array_keys($row)) . ') VALUES ('
        . implode(',', array_fill(0, count($row), '?')) . ')');
    $st->execute(array_values($row));
    if (!$st->rowCount()) throw new VgError('이미 있는 코드입니다.');
    return "병종 '{$row['name']}' 을(를) 추가했습니다.";
}

function vg_admin_delete_udef(string $code): string
{
    $pdo = vg_db();
    $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM vg_village_units WHERE unit_code = ? AND count > 0) + (SELECT COUNT(*) FROM vg_train_queue WHERE unit_code = ?)');
    $st->execute([$code, $code]);
    if ($st->fetchColumn() > 0) throw new VgError('이 병종을 가진 마을이나 훈련 중인 주문이 있어 지울 수 없습니다. 대신 사용 안 함으로 바꾸세요.');
    $pdo->prepare('DELETE FROM vg_unit_defs WHERE code = ?')->execute([$code]);
    $pdo->prepare('DELETE FROM vg_village_units WHERE unit_code = ?')->execute([$code]);
    return '병종 정의를 지웠습니다.';
}

// ───────────── 연구 ─────────────

function vg_admin_rdef_row(array $d): array
{
    $cost = [];
    foreach (VG_RES as $r) {
        $a = (float)($d['cost'][$r] ?? 0);
        if ($a > 0) $cost[$r] = $a == floor($a) ? (int)$a : $a;
    }
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') throw new VgError('연구 이름을 입력하세요.');
    $effect = (string)($d['effect'] ?? '');
    if (!isset(vg_research_effects()[$effect])) throw new VgError('연구 효과가 올바르지 않습니다.');
    $target = (string)($d['target'] ?? 'all');
    if ($target !== 'all' && !isset(vg_unit_categories()[$target])) throw new VgError('연구 대상이 올바르지 않습니다.');
    return [
        'name' => mb_substr($name, 0, 50), 'effect' => $effect, 'target' => $target,
        'value_per_level' => (float)($d['value_per_level'] ?? 5), 'max_level' => max(1, (int)($d['max_level'] ?? 10)),
        'req_smithy' => max(1, (int)($d['req_smithy'] ?? 1)), 'cost_json' => json_encode($cost),
        'cost_growth' => max(0.01, (float)($d['cost_growth'] ?? 1.6)), 'base_time' => max(1, (float)($d['base_time'] ?? 60)),
        'time_growth' => max(0.01, (float)($d['time_growth'] ?? 1.5)), 'sort_order' => (int)($d['sort_order'] ?? 0),
        'enabled' => !empty($d['enabled']) ? 1 : 0, 'descr' => mb_substr(trim((string)($d['descr'] ?? '')), 0, 500),
    ];
}

function vg_admin_save_rdefs(array $rows): string
{
    $pdo = vg_db();
    $n = 0;
    foreach ($rows as $code => $d) {
        $row = vg_admin_rdef_row($d);
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        $st = $pdo->prepare("UPDATE vg_research_defs SET $sets WHERE code = ?");
        $st->execute([...array_values($row), $code]);
        $n += $st->rowCount();
    }
    return "연구 정의 {$n}개를 바꿨습니다.";
}

function vg_admin_add_rdef(array $P): string
{
    $code = strtolower(trim((string)($P['code'] ?? '')));
    if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $code)) throw new VgError('코드는 영문 소문자로 시작하는 영문·숫자·_ 2~31자');
    $row = ['code' => $code] + vg_admin_rdef_row($P);
    $st = vg_db()->prepare('INSERT IGNORE INTO vg_research_defs (' . implode(',', array_keys($row)) . ') VALUES ('
        . implode(',', array_fill(0, count($row), '?')) . ')');
    $st->execute(array_values($row));
    if (!$st->rowCount()) throw new VgError('이미 있는 코드입니다.');
    return "연구 '{$row['name']}' 을(를) 추가했습니다.";
}

function vg_admin_delete_rdef(string $code): string
{
    $pdo = vg_db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM vg_research WHERE code = ? AND (level > 0 OR finish IS NOT NULL)');
    $st->execute([$code]);
    if ($st->fetchColumn() > 0) throw new VgError('이 연구를 한 마을이 있어 지울 수 없습니다. 대신 사용 안 함으로 바꾸세요.');
    $pdo->prepare('DELETE FROM vg_research_defs WHERE code = ?')->execute([$code]);
    $pdo->prepare('DELETE FROM vg_research WHERE code = ?')->execute([$code]);
    return '연구 정의를 지웠습니다.';
}
