<?php
// DB 연결 + 첫 실행 시 DB/스키마 자동 생성.
// 스키마 변경은 vg_migrations() 에 새 번호를 추가하는 방식으로만 한다 (기존 번호 수정 금지).

const VG_SCHEMA_VERSION = 4;
// 설정/건물 기본값 목록이 바뀌면 올린다 → 새 설정 키/새 건물이 기존 DB 에 추가된다.
const VG_DEFAULTS_VERSION = 4;

function vg_db(?PDO $use = null): PDO
{
    static $pdo = null;
    if ($use) return $pdo = $use; // 테스트용: 연결 교체
    if ($pdo) return $pdo;
    global $VG_CONFIG;
    $c = $VG_CONFIG;
    $name = $c['db_name'];
    if (!preg_match('/^\w+$/', $name)) throw new RuntimeException('db_name 형식 오류');

    $dsn = $c['db_socket']
        ? "mysql:unix_socket={$c['db_socket']};charset=utf8mb4"
        : "mysql:host={$c['db_host']};port={$c['db_port']};charset=utf8mb4";
    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$name`");
    $pdo->exec("SET time_zone = '+00:00'");
    vg_migrate($pdo);
    return $pdo;
}

function vg_meta_get(PDO $pdo, string $k): ?string
{
    try {
        $st = $pdo->prepare('SELECT v FROM vg_meta WHERE k = ?');
        $st->execute([$k]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string)$v;
    } catch (PDOException $e) {
        return null; // 테이블 없음 = 첫 실행
    }
}

function vg_meta_set(PDO $pdo, string $k, string $v): void
{
    $pdo->prepare('INSERT INTO vg_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

function vg_migrate(PDO $pdo): void
{
    $schema = (int)vg_meta_get($pdo, 'schema_version');
    $defaults = (int)vg_meta_get($pdo, 'defaults_version');
    if ($schema >= VG_SCHEMA_VERSION && $defaults >= VG_DEFAULTS_VERSION) return;

    // 동시 첫 접속 대비: 한 요청만 마이그레이션
    $pdo->query("SELECT GET_LOCK('village2_migrate', 30)")->fetchColumn();
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS vg_meta (k VARCHAR(64) PRIMARY KEY, v TEXT NOT NULL) ENGINE=InnoDB');
        $schema = (int)vg_meta_get($pdo, 'schema_version');
        foreach (vg_migrations() as $ver => $sqls) {
            if ($ver <= $schema) continue;
            foreach ($sqls as $sql) vg_migrate_exec($pdo, $sql);
            vg_meta_set($pdo, 'schema_version', (string)$ver);
        }
        if ((int)vg_meta_get($pdo, 'defaults_version') < VG_DEFAULTS_VERSION) {
            vg_sync_defaults($pdo);
            vg_meta_set($pdo, 'defaults_version', (string)VG_DEFAULTS_VERSION);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('village2_migrate')")->fetchColumn();
    }
}

/**
 * DDL 은 트랜잭션으로 되돌릴 수 없어 중간에 실패하면 일부만 적용된 채 남는다.
 * 다시 실행할 때 이미 있는 테이블·컬럼·인덱스 오류는 건너뛴다.
 */
function vg_migrate_exec(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        // 1050 테이블 있음, 1060 컬럼 있음, 1061 인덱스 있음, 1051/1091 지울 것 없음
        if (!in_array($code, [1050, 1060, 1061, 1051, 1091], true)) throw $e;
    }
}

function vg_migrations(): array
{
    $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        1 => [
            "CREATE TABLE vg_settings (
                skey VARCHAR(64) PRIMARY KEY,
                sval TEXT NOT NULL,
                stype VARCHAR(8) NOT NULL DEFAULT 'float',
                category VARCHAR(32) NOT NULL DEFAULT '',
                label VARCHAR(100) NOT NULL DEFAULT '',
                descr VARCHAR(500) NOT NULL DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0
            ) $E",
            "CREATE TABLE vg_building_defs (
                code VARCHAR(32) PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                category VARCHAR(16) NOT NULL,
                produces VARCHAR(16) NOT NULL DEFAULT '',
                base_rate DOUBLE NOT NULL DEFAULT 0,
                rate_growth DOUBLE NOT NULL DEFAULT 1,
                cost_json VARCHAR(500) NOT NULL DEFAULT '{}',
                cost_growth DOUBLE NOT NULL DEFAULT 1.5,
                base_time DOUBLE NOT NULL DEFAULT 30,
                time_growth DOUBLE NOT NULL DEFAULT 1.5,
                max_level INT NOT NULL DEFAULT 20,
                multi TINYINT NOT NULL DEFAULT 0,
                req_hall INT NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                enabled TINYINT NOT NULL DEFAULT 1,
                descr VARCHAR(500) NOT NULL DEFAULT ''
            ) $E",
            "CREATE TABLE vg_villages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(64) NOT NULL,
                user_name VARCHAR(100) NOT NULL DEFAULT '',
                name VARCHAR(50) NOT NULL,
                created_at DOUBLE NOT NULL,
                last_settle DOUBLE NOT NULL,
                last_seen DOUBLE NOT NULL,
                UNIQUE KEY uk_user (user_id)
            ) $E",
            "CREATE TABLE vg_resources (
                village_id INT PRIMARY KEY,
                money DOUBLE NOT NULL DEFAULT 0,
                food DOUBLE NOT NULL DEFAULT 0,
                wood DOUBLE NOT NULL DEFAULT 0,
                iron DOUBLE NOT NULL DEFAULT 0,
                gold DOUBLE NOT NULL DEFAULT 0
            ) $E",
            "CREATE TABLE vg_buildings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                village_id INT NOT NULL,
                code VARCHAR(32) NOT NULL,
                slot INT NOT NULL,
                level INT NOT NULL DEFAULT 0,
                target_level INT NOT NULL DEFAULT 0,
                build_start DOUBLE NULL,
                build_finish DOUBLE NULL,
                KEY idx_village (village_id),
                KEY idx_finish (build_finish)
            ) $E",
            "CREATE TABLE vg_logs (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                village_id INT NOT NULL,
                t DOUBLE NOT NULL,
                kind VARCHAR(16) NOT NULL,
                msg VARCHAR(500) NOT NULL,
                KEY idx_village_t (village_id, t)
            ) $E",
            "CREATE TABLE vg_images (
                ikey VARCHAR(64) PRIMARY KEY,
                path VARCHAR(255) NOT NULL,
                updated_at DOUBLE NOT NULL
            ) $E",
        ],
        // 그림은 전부 코드로 그리므로 이미지 업로드 기능 제거
        2 => [
            "DROP TABLE IF EXISTS vg_images",
        ],
        // 3단계: 주민·병종·훈련 대기열·대장간 연구
        3 => [
            "CREATE TABLE vg_unit_defs (
                code VARCHAR(32) PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                train_bld VARCHAR(32) NOT NULL DEFAULT '',
                req_level INT NOT NULL DEFAULT 1,
                category VARCHAR(16) NOT NULL DEFAULT 'infantry',
                cost_json VARCHAR(500) NOT NULL DEFAULT '{}',
                train_time DOUBLE NOT NULL DEFAULT 30,
                upkeep DOUBLE NOT NULL DEFAULT 10,
                attack DOUBLE NOT NULL DEFAULT 10,
                defense DOUBLE NOT NULL DEFAULT 10,
                speed DOUBLE NOT NULL DEFAULT 60,
                carry DOUBLE NOT NULL DEFAULT 10,
                ranged TINYINT NOT NULL DEFAULT 0,
                counters VARCHAR(255) NOT NULL DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                enabled TINYINT NOT NULL DEFAULT 1,
                descr VARCHAR(500) NOT NULL DEFAULT ''
            ) $E",
            "CREATE TABLE vg_village_units (
                village_id INT NOT NULL,
                unit_code VARCHAR(32) NOT NULL,
                count INT NOT NULL DEFAULT 0,
                PRIMARY KEY (village_id, unit_code)
            ) $E",
            "CREATE TABLE vg_train_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                village_id INT NOT NULL,
                bld_code VARCHAR(32) NOT NULL,
                unit_code VARCHAR(32) NOT NULL,
                total INT NOT NULL,
                done INT NOT NULL DEFAULT 0,
                unit_progress DOUBLE NOT NULL DEFAULT 0,
                progress_at DOUBLE NOT NULL DEFAULT 0,
                cost_json VARCHAR(500) NOT NULL DEFAULT '{}',
                created_at DOUBLE NOT NULL,
                KEY idx_village (village_id)
            ) $E",
            "CREATE TABLE vg_research_defs (
                code VARCHAR(32) PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                effect VARCHAR(32) NOT NULL,
                target VARCHAR(16) NOT NULL DEFAULT 'all',
                value_per_level DOUBLE NOT NULL DEFAULT 5,
                max_level INT NOT NULL DEFAULT 10,
                req_smithy INT NOT NULL DEFAULT 1,
                cost_json VARCHAR(500) NOT NULL DEFAULT '{}',
                cost_growth DOUBLE NOT NULL DEFAULT 1.6,
                base_time DOUBLE NOT NULL DEFAULT 60,
                time_growth DOUBLE NOT NULL DEFAULT 1.5,
                sort_order INT NOT NULL DEFAULT 0,
                enabled TINYINT NOT NULL DEFAULT 1,
                descr VARCHAR(500) NOT NULL DEFAULT ''
            ) $E",
            "CREATE TABLE vg_research (
                village_id INT NOT NULL,
                code VARCHAR(32) NOT NULL,
                level INT NOT NULL DEFAULT 0,
                target_level INT NOT NULL DEFAULT 0,
                start DOUBLE NULL,
                finish DOUBLE NULL,
                PRIMARY KEY (village_id, code)
            ) $E",
            "CREATE TABLE vg_villagers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                village_id INT NOT NULL,
                name VARCHAR(30) NOT NULL,
                xp DOUBLE NOT NULL DEFAULT 0,
                level INT NOT NULL DEFAULT 1,
                building_id INT NULL,
                job_code VARCHAR(32) NULL,
                job_since DOUBLE NULL,
                spec_code VARCHAR(32) NULL,
                hired_at DOUBLE NOT NULL,
                KEY idx_village (village_id)
            ) $E",
        ],
        // 4단계: 육각 세계 맵·부대 이동
        4 => [
            "CREATE TABLE vg_terrain_defs (
                code VARCHAR(16) PRIMARY KEY,
                name VARCHAR(30) NOT NULL,
                color VARCHAR(9) NOT NULL DEFAULT '#b9c77a',
                move_cost DOUBLE NOT NULL DEFAULT 1,
                def_bonus_pct DOUBLE NOT NULL DEFAULT 0,
                cav_bonus_pct DOUBLE NOT NULL DEFAULT 0,
                passable TINYINT NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                descr VARCHAR(300) NOT NULL DEFAULT ''
            ) $E",
            "CREATE TABLE vg_map_tiles (
                q SMALLINT NOT NULL,
                r SMALLINT NOT NULL,
                terrain VARCHAR(16) NOT NULL,
                feature VARCHAR(16) NOT NULL DEFAULT '',
                village_id INT NULL,
                PRIMARY KEY (q, r),
                KEY idx_village (village_id)
            ) $E",
            "ALTER TABLE vg_villages ADD COLUMN q SMALLINT NULL, ADD COLUMN r SMALLINT NULL",
            "CREATE TABLE vg_armies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                village_id INT NOT NULL,
                name VARCHAR(40) NOT NULL,
                state VARCHAR(12) NOT NULL DEFAULT 'stationed',
                q SMALLINT NOT NULL,
                r SMALLINT NOT NULL,
                path_json MEDIUMTEXT NULL,
                depart_at DOUBLE NULL,
                arrive_at DOUBLE NULL,
                is_return TINYINT NOT NULL DEFAULT 0,
                task VARCHAR(12) NOT NULL DEFAULT '',
                task_q SMALLINT NULL,
                task_r SMALLINT NULL,
                task_start DOUBLE NULL,
                task_end DOUBLE NULL,
                cargo_json VARCHAR(500) NOT NULL DEFAULT '{}',
                created_at DOUBLE NOT NULL,
                KEY idx_village (village_id),
                KEY idx_arrive (arrive_at),
                KEY idx_task_end (task_end)
            ) $E",
            "CREATE TABLE vg_army_units (
                army_id INT NOT NULL,
                owner_village_id INT NOT NULL,
                unit_code VARCHAR(32) NOT NULL,
                count INT NOT NULL,
                PRIMARY KEY (army_id, owner_village_id, unit_code)
            ) $E",
        ],
    ];
}

/** 설정 메타데이터는 코드 기준으로 갱신(값은 보존), 건물 정의는 없는 것만 추가 */
function vg_sync_defaults(PDO $pdo): void
{
    $st = $pdo->prepare('INSERT INTO vg_settings (skey, sval, stype, category, label, descr, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE stype = VALUES(stype), category = VALUES(category), label = VALUES(label),
            descr = VALUES(descr), sort_order = VALUES(sort_order)');
    $i = 0;
    foreach (vg_setting_defs() as $k => $d) {
        $st->execute([$k, vg_setting_to_str($d[0]), $d[1], $d[2], $d[3], $d[4] ?? '', $i++]);
    }
    // 코드에서 없어진 설정 키는 지운다
    $keys = array_keys(vg_setting_defs());
    $pdo->prepare('DELETE FROM vg_settings WHERE skey NOT IN (' . implode(',', array_fill(0, count($keys), '?')) . ')')->execute($keys);
    vg_insert_building_defs($pdo, false);
    vg_insert_unit_defs($pdo, false);
    vg_insert_research_defs($pdo, false);
    vg_insert_terrain_defs($pdo, false);
}

function vg_insert_terrain_defs(PDO $pdo, bool $overwrite): void
{
    $st = $pdo->prepare(($overwrite ? 'REPLACE' : 'INSERT IGNORE') . ' INTO vg_terrain_defs
        (code, name, color, move_cost, def_bonus_pct, cav_bonus_pct, passable, sort_order, descr) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $i = 0;
    foreach (vg_default_terrain_defs() as $code => $d) {
        $st->execute([$code, $d['name'], $d['color'], $d['move_cost'], $d['def'], $d['cav'], $d['passable'], ($i++) * 10, $d['descr']]);
    }
}

function vg_insert_unit_defs(PDO $pdo, bool $overwrite): void
{
    $cols = ['code', 'name', 'train_bld', 'req_level', 'category', 'cost_json', 'train_time', 'upkeep', 'attack', 'defense',
        'speed', 'carry', 'ranged', 'counters', 'sort_order', 'enabled', 'descr'];
    $st = $pdo->prepare(($overwrite ? 'REPLACE' : 'INSERT IGNORE') . ' INTO vg_unit_defs (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')');
    $i = 0;
    foreach (vg_default_unit_defs() as $code => $d) {
        $st->execute([$code, $d['name'], $d['train_bld'], $d['req_level'], $d['category'], json_encode($d['cost']),
            $d['train_time'], $d['upkeep'], $d['attack'], $d['defense'], $d['speed'], $d['carry'], !empty($d['ranged']) ? 1 : 0,
            implode(',', $d['counters'] ?? []), ($i++) * 10, 1, $d['descr'] ?? '']);
    }
}

function vg_insert_research_defs(PDO $pdo, bool $overwrite): void
{
    $cols = ['code', 'name', 'effect', 'target', 'value_per_level', 'max_level', 'req_smithy', 'cost_json', 'cost_growth',
        'base_time', 'time_growth', 'sort_order', 'enabled', 'descr'];
    $st = $pdo->prepare(($overwrite ? 'REPLACE' : 'INSERT IGNORE') . ' INTO vg_research_defs (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')');
    $i = 0;
    foreach (vg_default_research_defs() as $code => $d) {
        $st->execute([$code, $d['name'], $d['effect'], $d['target'], $d['value'], $d['max_level'], $d['req_smithy'],
            json_encode($d['cost']), $d['cost_growth'] ?? 1.6, $d['base_time'], $d['time_growth'] ?? 1.5, ($i++) * 10, 1, $d['descr'] ?? '']);
    }
}

function vg_insert_building_defs(PDO $pdo, bool $overwrite): void
{
    $cols = ['code', 'name', 'category', 'produces', 'base_rate', 'rate_growth', 'cost_json', 'cost_growth',
        'base_time', 'time_growth', 'max_level', 'multi', 'req_hall', 'sort_order', 'enabled', 'descr'];
    $sql = ($overwrite ? 'REPLACE' : 'INSERT IGNORE') . ' INTO vg_building_defs (' . implode(',', $cols) . ') VALUES ('
        . implode(',', array_fill(0, count($cols), '?')) . ')';
    $st = $pdo->prepare($sql);
    $i = 0;
    foreach (vg_default_building_defs() as $code => $d) {
        $st->execute([$code, $d['name'], $d['category'], $d['produces'] ?? '', $d['base_rate'] ?? 0,
            $d['rate_growth'] ?? 1, json_encode($d['cost'], JSON_UNESCAPED_UNICODE), $d['cost_growth'] ?? 1.5,
            $d['base_time'] ?? 30, $d['time_growth'] ?? 1.5, $d['max_level'] ?? 20, !empty($d['multi']) ? 1 : 0,
            $d['req_hall'] ?? 1, ($i++) * 10, 1, $d['descr'] ?? '']);
    }
}

/** 트랜잭션 헬퍼. 콜백 안에서 예외가 나면 롤백 */
function vg_tx(callable $fn)
{
    $pdo = vg_db();
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
