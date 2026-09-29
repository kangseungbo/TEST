<?php
// 게임 설정(vg_settings). 모든 게임 수치는 여기 기본값으로 시작하고 관리자 설정 탭에서 바꾼다.
// 형식: 키 => [기본값, 타입(int|float|bool|str), 분류, 이름, 설명]

function vg_setting_categories(): array
{
    return [
        'grid'       => '마을 격자',
        'build'      => '건설·철거',
        'production' => '생산·크리티컬',
        'resource'   => '자원·창고',
        'start'      => '시작 조건',
        'display'    => '화면·표시',
    ];
}

function vg_setting_defs(): array
{
    return [
        // 마을 격자
        'cells_base'           => [4, 'int', 'grid', '시작 칸 수', '회관 Lv1 일 때 사용할 수 있는 칸 수'],
        'cells_per_hall_level' => [2, 'int', 'grid', '회관 레벨당 추가 칸', '회관 레벨이 1 오를 때마다 늘어나는 칸 수'],
        'cells_max'            => [16, 'int', 'grid', '최대 칸 수', '마을 격자 최대 칸 수 (최대 16)'],
        'grid_expand_levels'   => ['', 'str', 'grid', '레벨별 칸 수 직접 지정',
            '쉼표로 회관 Lv1, Lv2, … 의 칸 수를 적는다 (예: 4,6,8,10). 비우면 위 공식 사용. 목록보다 높은 레벨은 마지막 값'],

        // 건설
        'hall_headroom'           => [2, 'int', 'build', '회관 여유 레벨', '다른 건물 레벨 상한 = 회관 레벨 + 이 값'],
        'max_concurrent_builds'   => [3, 'int', 'build', '동시 건설 수', '한 마을에서 동시에 진행할 수 있는 건설·업그레이드 수'],
        'build_speed_mult'        => [1.0, 'float', 'build', '건설 속도 배수', '2 이면 건설 시간이 절반'],
        'demolish_refund_pct'     => [30, 'float', 'build', '철거 환급률(%)', '철거 시 지금까지 들어간 건설 비용 중 돌려받는 비율'],
        'build_cancel_refund_pct' => [100, 'float', 'build', '건설 취소 환급률(%)', '진행 중인 건설·업그레이드를 취소할 때 돌려받는 비율'],

        // 생산
        'prod_speed_mult'       => [1.0, 'float', 'production', '전체 생산 배수', '모든 생산 건물 생산량에 곱해지는 값'],
        'early_boost_pct'       => [100, 'float', 'production', '초반 생산 보너스(%)', '회관 레벨이 아래 값 이하일 때 생산량 추가 비율'],
        'early_boost_until_hall' => [5, 'int', 'production', '초반 보너스 회관 레벨', '이 회관 레벨까지 초반 생산 보너스 적용 (0 이면 끔)'],
        'crit_chance_pct'       => [5, 'float', 'production', '크리티컬 확률(%)', '생산 주기마다 크리티컬이 터질 확률'],
        'crit_mult'             => [3, 'float', 'production', '크리티컬 배수', '크리티컬 시 그 주기 생산량 배수'],
        'crit_tick_sec'         => [10, 'float', 'production', '생산 주기(초)', '크리티컬 판정 단위 시간'],
        'smelt_iron_per_gold'   => [3, 'float', 'production', '금괴 1개당 철광석', '제련소가 금괴 1개를 만들 때 쓰는 철광석. 철광석 재고가 없으면 제련하지 않는다'],

        // 자원·창고
        'storage_base'      => [5000, 'float', 'resource', '기본 창고 용량', '창고 없이 자원별로 보관 가능한 양'],
        'storage_cap_money' => [1, 'bool', 'resource', '돈도 창고 용량 적용', '끄면 돈은 무제한 보관'],

        // 시작 조건
        'start_money'     => [800, 'float', 'start', '시작 돈', ''],
        'start_food'      => [800, 'float', 'start', '시작 식량', ''],
        'start_wood'      => [800, 'float', 'start', '시작 나무', ''],
        'start_iron'      => [300, 'float', 'start', '시작 철광석', ''],
        'start_gold'      => [0, 'float', 'start', '시작 금괴', ''],
        'start_buildings' => ['farm,lumber', 'str', 'start', '시작 건물', '새 마을에 Lv1 로 지어 주는 건물 코드 (쉼표 구분)'],

        // 화면
        'wall_img_scale'    => [1.0, 'float', 'display', '성벽 이미지 크기 배수', '성벽 뒤/앞 이미지 가로 크기 배수 (1 = 마을 둘레 폭)'],
        'wall_back_offset'  => [0, 'float', 'display', '뒤 성벽 세로 보정(px)', '+ 면 아래로'],
        'wall_front_offset' => [0, 'float', 'display', '앞 성벽 세로 보정(px)', '+ 면 아래로'],
        'poll_sec'          => [20, 'int', 'display', '화면 자동 갱신(초)', '마을 화면이 서버 상태를 다시 읽는 주기'],
    ];
}

function vg_setting_to_str($v): string
{
    if (is_bool($v)) return $v ? '1' : '0';
    return (string)$v;
}

function vg_setting_cast(string $raw, string $type)
{
    switch ($type) {
        case 'int': return (int)$raw;
        case 'float': return (float)$raw;
        case 'bool': return $raw === '1' || strtolower($raw) === 'true';
        default: return $raw;
    }
}

/** 설정값 조회. 요청당 한 번만 DB 에서 읽는다 */
function S(string $key)
{
    $all = vg_settings_all();
    if (array_key_exists($key, $all)) return $all[$key];
    $defs = vg_setting_defs();
    if (isset($defs[$key])) return $defs[$key][0];
    throw new RuntimeException("알 수 없는 설정: $key");
}

function vg_settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = [];
    foreach (vg_db()->query('SELECT skey, sval, stype FROM vg_settings') as $r) {
        $cache[$r['skey']] = vg_setting_cast($r['sval'], $r['stype']);
    }
    return $cache;
}
