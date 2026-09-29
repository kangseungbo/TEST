<?php
// CLI 테스트: VG_DB=village2_test php tests/run.php
// 지정한 DB 를 지우고 새로 만든 뒤 마을 로직을 검사한다. 운영 DB 이름으로 절대 실행하지 말 것.
if (PHP_SAPI !== 'cli') exit;
$db = getenv('VG_DB');
if (!$db || !str_contains($db, 'test')) {
    fwrite(STDERR, "VG_DB 환경변수에 'test' 가 들어간 DB 이름을 지정하세요.\n");
    exit(2);
}
require __DIR__ . '/../lib/bootstrap.php';
$VG_CONFIG['db_name'] = $db;

// 깨끗한 DB
$c = $VG_CONFIG;
$raw = new PDO($c['db_socket'] ? "mysql:unix_socket={$c['db_socket']}" : "mysql:host={$c['db_host']};port={$c['db_port']}", $c['db_user'], $c['db_pass']);
$raw->exec("DROP DATABASE IF EXISTS `$db`");
unset($raw);

$fails = 0;
$n = 0;
function ok($cond, string $name): void
{
    global $fails, $n;
    $n++;
    if ($cond) { echo "  ok  $name\n"; return; }
    $fails++;
    echo "FAIL  $name\n";
}
function near(float $a, float $b, float $eps = 0.01): bool { return abs($a - $b) <= $eps; }
function expectErr(callable $f, string $name, string $contains = ''): void
{
    try { $f(); ok(false, "$name (오류가 나야 함)"); }
    catch (VgError $e) { ok($contains === '' || str_contains($e->getMessage(), $contains), "$name → " . $e->getMessage()); }
}
function setS(string $k, $v): void
{
    vg_db()->prepare('UPDATE vg_settings SET sval = ? WHERE skey = ?')->execute([(string)$v, $k]);
    vg_settings_all(true);
}
function res(int $vid): array
{
    $st = vg_db()->prepare('SELECT money, food, wood, iron, gold FROM vg_resources WHERE village_id = ?');
    $st->execute([$vid]);
    return array_map('floatval', $st->fetch());
}
function setRes(int $vid, array $r): void
{
    foreach ($r as $k => $v) vg_db()->prepare("UPDATE vg_resources SET $k = ? WHERE village_id = ?")->execute([$v, $vid]);
}
/** 마을 시계를 dt 초 과거로 돌린다 (정산 시각·공사 시각 모두) */
function timeWarp(int $vid, float $dt): void
{
    $pdo = vg_db();
    $pdo->prepare('UPDATE vg_villages SET last_settle = last_settle - ? WHERE id = ?')->execute([$dt, $vid]);
    $pdo->prepare('UPDATE vg_buildings SET build_start = build_start - ?, build_finish = build_finish - ? WHERE village_id = ? AND build_finish IS NOT NULL')
        ->execute([$dt, $dt, $vid]);
}
function blds(int $vid): array { return vg_load_buildings($vid); }
function byCode(int $vid, string $code): ?array
{
    foreach (blds($vid) as $b) if ($b['code'] === $code) return $b;
    return null;
}
function settle(int $vid, ?float $now = null): array { return vg_tx(fn() => vg_settle($vid, $now)); }

echo "[스키마]\n";
$pdo = vg_db();
ok((int)vg_meta_get($pdo, 'schema_version') === VG_SCHEMA_VERSION, '스키마 자동 생성');
ok(count(vg_bdefs()) >= 14, '기본 건물 정의 입력');
ok(S('hall_headroom') === 2, '설정 기본값 hall_headroom=2');
setS('crit_chance_pct', 0);
setS('early_boost_pct', 0);

echo "[격자]\n";
ok(vg_cells_for_hall(1) === 4 && vg_cells_for_hall(2) === 6 && vg_cells_for_hall(7) === 16 && vg_cells_for_hall(20) === 16, '공식: 4 + 2/레벨, 최대 16');
setS('grid_expand_levels', '4,5,9');
ok(vg_cells_for_hall(1) === 4 && vg_cells_for_hall(2) === 5 && vg_cells_for_hall(3) === 9 && vg_cells_for_hall(9) === 9, '레벨별 직접 지정 + 마지막 값 유지');
setS('grid_expand_levels', '');

echo "[마을 생성]\n";
$v = vg_create_village('t1', '테스트');
$vid = (int)$v['id'];
$b = blds($vid);
ok(count($b) === 3 && byCode($vid, 'hall')['level'] === 1 && byCode($vid, 'farm') && byCode($vid, 'lumber'), '회관 + 시작 건물(농장·벌목장)');
ok(near(res($vid)['food'], 800), '시작 식량 800');
$v2 = vg_create_village('t1', '테스트');
ok((int)$v2['id'] === $vid, '같은 사용자 두 번 생성해도 한 마을');

echo "[정산]\n";
timeWarp($vid, 100);
settle($vid);
$r = res($vid);
ok(near($r['food'], 900, 0.1) && near($r['wood'], 900, 0.1), "100초 → 식량·나무 +100 (농장 Lv1 1/s): {$r['food']}");
ok(near($r['money'], 850, 0.1), "회관 돈 0.5/s: {$r['money']}");
setS('early_boost_pct', 100);
timeWarp($vid, 10);
settle($vid);
ok(near(res($vid)['food'], 920, 0.1), '초반 보너스 100% → 2배 생산');
setS('early_boost_pct', 0);

echo "[창고 한도]\n";
setRes($vid, ['food' => 4990]);
timeWarp($vid, 100);
settle($vid);
ok(near(res($vid)['food'], 5000), '창고 5000 에서 멈춤');
setRes($vid, ['food' => 7000]);
timeWarp($vid, 100);
settle($vid);
ok(near(res($vid)['food'], 7000), '이미 넘친 자원은 깎지 않음');
setRes($vid, ['money' => 5000, 'food' => 800, 'wood' => 3000, 'iron' => 2000, 'gold' => 0]);
settle($vid);

echo "[건설]\n";
vg_act_build($vid, 'mine', 3);
$m = byCode($vid, 'mine');
ok($m && $m['level'] === 0 && $m['target_level'] === 1 && near($m['build_finish'] - $m['build_start'], 18), '광산 건설 시작 (18초)');
ok(near(res($vid)['wood'], 3000 - 80, 0.1), '비용 차감 (나무 80)');
expectErr(fn() => vg_act_build($vid, 'farm', 3), '같은 칸 건설 불가', '이미');
expectErr(fn() => vg_act_build($vid, 'farm', 5), '잠긴 칸 건설 불가', '열리지');
expectErr(fn() => vg_act_build($vid, 'market', 4), '회관 레벨 부족', '회관 Lv2');
expectErr(fn() => vg_act_build($vid, 'hall', 4), '회관 추가 불가');

// 완공 시각을 구간 중간에: 18초 뒤 완공, 100초 경과 → 광산은 82초만 생산
$ironBefore = res($vid)['iron'];
timeWarp($vid, 100);
settle($vid);
$m = byCode($vid, 'mine');
ok($m['level'] === 1 && $m['build_finish'] === null, '시간 지나면 완공');
ok(near(res($vid)['iron'] - $ironBefore, 0.6 * 82, 0.05), '완공 시각부터만 생산 (0.6 × 82 = 49.2): ' . round(res($vid)['iron'] - $ironBefore, 2));

echo "[업그레이드·상한]\n";
$farm = byCode($vid, 'farm');
vg_act_upgrade($vid, $farm['id']);
ok(byCode($vid, 'farm')['target_level'] === 2, '농장 Lv2 업그레이드 시작');
expectErr(fn() => vg_act_upgrade($vid, $farm['id']), '공사 중 재업그레이드 불가', '공사 중');
$pdo->prepare('UPDATE vg_buildings SET level = 3, target_level = 3, build_start = NULL, build_finish = NULL WHERE id = ?')->execute([$farm['id']]);
expectErr(fn() => vg_act_upgrade($vid, $farm['id']), '회관 Lv1 + 2 = Lv3 상한', '회관');
setS('max_concurrent_builds', 1);
vg_act_upgrade($vid, byCode($vid, 'lumber')['id']);
expectErr(fn() => vg_act_upgrade($vid, byCode($vid, 'mine')['id']), '동시 건설 수 제한', '동시에 1곳');
setS('max_concurrent_builds', 3);

echo "[취소·철거]\n";
$lum = byCode($vid, 'lumber');
$before = res($vid);
vg_act_cancel($vid, $lum['id']);
$after = res($vid);
$cost = vg_level_cost(vg_bdef('lumber'), 2);
ok(near($after['money'] - $before['money'], $cost['money'], 0.1) && byCode($vid, 'lumber')['level'] === 1 && byCode($vid, 'lumber')['build_finish'] === null, '업그레이드 취소 → 100% 환급, 레벨 유지');
vg_act_build($vid, 'farm', 4);
$site = null;
foreach (blds($vid) as $x) if ($x['slot'] === 4) $site = $x;
vg_act_cancel($vid, $site['id']);
$still = array_filter(blds($vid), fn($x) => $x['slot'] === 4);
ok(!$still, '신규 건설 취소 → 건물 제거');
$m = byCode($vid, 'mine');
$before = res($vid);
vg_act_demolish($vid, $m['id']);
$after = res($vid);
ok(!byCode($vid, 'mine') && near($after['wood'] - $before['wood'], floor(80 * 0.3), 0.1), '철거 → 30% 환급 (나무 24)');
expectErr(fn() => vg_act_demolish($vid, byCode($vid, 'hall')['id']), '회관 철거 불가');

echo "[배치 변경]\n";
$farm = byCode($vid, 'farm');
$lum = byCode($vid, 'lumber');
vg_act_move($vid, $farm['id'], 3);
ok(byCode($vid, 'farm')['slot'] === 3, '빈 칸으로 이동');
vg_act_move($vid, $farm['id'], $lum['slot']);
ok(byCode($vid, 'farm')['slot'] === $lum['slot'] && byCode($vid, 'lumber')['slot'] === 3, '건물끼리 맞바꾸기');
expectErr(fn() => vg_act_move($vid, $farm['id'], 9), '잠긴 칸으로 이동 불가');
expectErr(fn() => vg_act_move($vid, byCode($vid, 'hall')['id'], 4), '회관 이동 불가');
// 칸 수를 줄여도 기존 건물 칸은 보호 → 그 칸과 맞바꾸기 가능
$pdo->prepare('UPDATE vg_buildings SET slot = 12 WHERE id = ?')->execute([byCode($vid, 'lumber')['id']]);
vg_act_move($vid, byCode($vid, 'farm')['id'], 12);
ok(byCode($vid, 'farm')['slot'] === 12, '보호된 칸(건물 있음)과는 맞바꾸기 허용');

echo "[제련소]\n";
$v3 = vg_create_village('t3', '제련');
$v3id = (int)$v3['id'];
$pdo->prepare("INSERT INTO vg_buildings (village_id, code, slot, level, target_level) VALUES (?, 'smelter', 4, 1, 1)")->execute([$v3id]);
setRes($v3id, ['iron' => 30, 'gold' => 0]);
settle($v3id);
timeWarp($v3id, 1000);
settle($v3id);
$r = res($v3id);
ok(near($r['iron'], 0) && near($r['gold'], 10, 0.01), "철광석 30 → 금괴 10 (재고만큼만): 금괴 {$r['gold']}");
// 광산이 있으면 고갈 후에는 캐는 만큼만 제련 (제련소 Lv3 소모 1.05/s > 광산 Lv1 0.6/s)
$pdo->prepare("UPDATE vg_buildings SET level = 3, target_level = 3 WHERE village_id = ? AND code = 'smelter'")->execute([$v3id]);
$pdo->prepare("INSERT INTO vg_buildings (village_id, code, slot, level, target_level) VALUES (?, 'mine', 3, 1, 1)")->execute([$v3id]);
setRes($v3id, ['iron' => 0, 'gold' => 0]);
settle($v3id);
timeWarp($v3id, 100);
settle($v3id);
$r = res($v3id);
ok(near($r['gold'], 0.6 * 100 / 3, 0.05) && near($r['iron'], 0), '광산 0.6/s < 제련 소모 1.05/s → 금괴 = 캔 양/3: ' . round($r['gold'], 2));
$st = vg_settle_and_state($v3id);
ok($st['smelt_util'] < 1 && near($st['net']['iron'], 0) && near($st['net']['gold'], 0.2), '화면 순생산: 철광석 0, 금괴 0.2/s');

echo "[크리티컬]\n";
setS('crit_chance_pct', 100);
setS('crit_mult', 3);
setS('crit_tick_sec', 10);
$v4 = (int)vg_create_village('t4', '크리')['id'];
settle($v4);
$f0 = res($v4)['food'];
timeWarp($v4, 100);
$s = settle($v4);
ok(near(res($v4)['food'] - $f0, 300, 0.1) && isset($s['crit']['farm']), '확률 100%, 배수 3 → 생산 3배 + 기록');
setS('crit_chance_pct', 0);

echo "[동시 정산 — 원자적 선점]\n";
$v5 = (int)vg_create_village('t5', '동시')['id'];
settle($v5);
timeWarp($v5, 50);
$pids = [];
for ($i = 0; $i < 4; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        // 자식: 새 연결로 30번 정산. 부모와 공유하는 소켓을 닫지 않도록 소멸자 없이 종료
        for ($k = 0; $k < 30; $k++) vg_tx_child();
        posix_kill(posix_getpid(), SIGKILL);
    }
    $pids[] = $pid;
}
foreach ($pids as $p) pcntl_waitpid($p, $status);
settle($v5);
$st = $pdo->prepare('SELECT created_at, last_settle FROM vg_villages WHERE id = ?');
$st->execute([$v5]);
$row = $st->fetch();
$elapsed = (float)$row['last_settle'] - (float)$row['created_at'] + 50;
$expect = 800 + $elapsed * 1.0 - 120;
ok(near(res($v5)['food'], $expect, 0.05), '4 프로세스 × 30회 동시 정산·지불 후 식량 = 시작 + 생산 − 120 (' . round(res($v5)['food'], 3) . ' vs ' . round($expect, 3) . ')');

echo "\n$n 개 중 " . ($n - $fails) . " 통과\n";
exit($fails ? 1 : 0);

/** 자식 프로세스용: 부모의 PDO 연결을 공유하지 않도록 새로 연결해 정산 */
function vg_tx_child(): void
{
    static $pdo = null;
    global $VG_CONFIG, $v5;
    if (!$pdo) {
        $c = $VG_CONFIG;
        $pdo = new PDO("mysql:host={$c['db_host']};port={$c['db_port']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    vg_db($pdo);
    // 정산 + 자원 1 소모(건설비 지불과 같은 경로). 잠금이 없으면 동시 요청끼리 차감이 사라진다
    vg_tx(function () use ($v5) {
        ['res' => $res] = vg_settle($v5);
        vg_pay($v5, $res, ['food' => 1]);
    });
}
