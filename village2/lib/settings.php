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
        'villager'   => '주민',
        'military'   => '병력·훈련',
        'research'   => '연구',
        'map'        => '세계 맵',
        'march'      => '부대·이동',
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

        // 주민
        'villager_cap_per_hall'       => [1, 'int', 'villager', '회관 레벨당 인구', '인구 상한 = 회관 레벨 × 이 값'],
        'villager_food_each'          => [20, 'float', 'villager', '주민 식량 소비(시간당)', '주민 1명이 시간당 먹는 식량. 고용비는 없다'],
        'villager_max_per_building'   => [1, 'int', 'villager', '건물당 주민 수', '생산 건물 한 채에 배치할 수 있는 주민 수'],
        'villager_bonus_base_pct'     => [10, 'float', 'villager', '배치 기본 보너스(%)', '생산 건물에 배치하면 그 건물 생산량 증가'],
        'villager_bonus_per_level_pct' => [2, 'float', 'villager', '주민 레벨당 보너스(%)', 'Lv2 부터 레벨이 오를 때마다 추가'],
        'villager_spec_bonus_pct'     => [10, 'float', 'villager', '특화 보너스(%)', '특화된 건물 종류에서 일하면 추가'],
        'villager_spec_hours'         => [24, 'float', 'villager', '특화까지 시간', '같은 종류 건물에서 이 시간만큼 계속 일하면 자동 특화'],
        'villager_xp_hours_per_level' => [6, 'float', 'villager', '레벨업 필요 시간', 'Lv N → N+1 에 필요한 근무 시간 = 이 값 × N'],
        'villager_max_level'          => [10, 'int', 'villager', '주민 최대 레벨', ''],

        // 병력·훈련
        'barracks_speed_per_level'   => [0.15, 'float', 'military', '훈련 건물 레벨당 속도', '훈련 속도 = 1 + 이 값 × (같은 종류 훈련 건물 레벨 합 − 1)'],
        'train_speed_mult'           => [1.0, 'float', 'military', '전체 훈련 속도 배수', ''],
        'train_cancel_refund_pct'    => [50, 'float', 'military', '훈련 취소 환급률(%)', '남은 수량 비용 중 돌려받는 비율'],
        'train_queue_max'            => [5, 'int', 'military', '훈련 대기열 길이', '훈련 건물 종류마다 걸어 둘 수 있는 주문 수'],
        'train_max_batch'            => [500, 'int', 'military', '한 번에 훈련할 최대 수', ''],
        'counters_on'                => [1, 'bool', 'military', '병종 상성 사용', '끄면 상성 배수를 적용하지 않는다 (5단계 전투)'],
        'counter_bonus_pct'          => [50, 'float', 'military', '상성 보너스(%)', '강한 상대에게 공격력 증가. 상대 부대 구성 비율로 가중평균'],
        'starve_desert_pct_per_hour' => [5, 'float', 'military', '굶주림 이탈률(시간당 %)', '식량이 바닥난 동안 마을 병력이 떠나는 비율. 0 이면 끔'],
        'worker_build_speed_pct'     => [1, 'float', 'military', '일꾼 1명당 건설 단축(%)', '마을에 있는 일꾼 수만큼 새로 시작하는 공사 시간 단축'],
        'worker_build_speed_max_pct' => [50, 'float', 'military', '일꾼 건설 단축 최대(%)', ''],

        // 연구
        'research_speed_mult'     => [1.0, 'float', 'research', '연구 속도 배수', ''],
        'research_max_concurrent' => [1, 'int', 'research', '동시 연구 수', ''],

        // 세계 맵
        'map_radius'           => [16, 'int', 'map', '맵 반지름(타일)', '가운데에서 가장자리까지 타일 수. 바꾸면 맵 재생성 때 적용'],
        'map_seed'             => [0, 'int', 'map', '맵 시드', '같은 시드면 같은 맵. 0 이면 재생성 때 무작위'],
        'map_rivers'           => [3, 'int', 'map', '강 개수', ''],
        'map_mines'            => [8, 'int', 'map', '광산 거점 수', '산 근처에 생긴다'],
        'map_farms'            => [8, 'int', 'map', '농장 거점 수', ''],
        'map_ports'            => [4, 'int', 'map', '항구 수', '호숫가에 생긴다'],
        'village_min_distance' => [6, 'int', 'map', '마을 사이 최소 거리', '새 마을을 놓을 때 다른 마을과 떨어뜨릴 타일 수 (자리가 없으면 줄여서 놓음)'],
        'map_poll_sec'         => [5, 'int', 'map', '맵 화면 갱신(초)', '세계 맵을 보고 있을 때 부대 정보를 다시 읽는 주기'],

        // 부대·이동
        'army_max'               => [3, 'int', 'march', '마을당 부대 수', '한 마을이 동시에 내보낼 수 있는 부대 수'],
        'march_speed_mult'       => [1.0, 'float', 'march', '전체 이동 속도 배수', '2 이면 이동 시간이 절반'],
        'march_return_speed_pct' => [70, 'float', 'march', '회군 속도(%)', '마을로 돌아올 때는 진군 속도의 이 비율'],
        'gather_per_worker_hour' => [60, 'float', 'march', '일꾼 채집량(시간당)', '거점·숲에 주둔한 부대의 일꾼 1명이 시간당 모으는 양. 부대 운반량까지만'],
        'bridge_workers_min'     => [5, 'int', 'march', '다리 건설 최소 일꾼', ''],
        'bridge_wood'            => [300, 'float', 'march', '다리 건설 나무', '마을 창고에서 낸다'],
        'bridge_time_sec'        => [600, 'float', 'march', '다리 건설 시간(초)', '최소 일꾼 수 기준. 일꾼이 많으면 빨라진다 (최대 4배)'],
        'bridge_move_cost'       => [1, 'float', 'march', '다리 이동 비용', '다리가 놓인 강 타일의 이동 비용'],

        // 시작 조건
        'start_money'     => [800, 'float', 'start', '시작 돈', ''],
        'start_food'      => [800, 'float', 'start', '시작 식량', ''],
        'start_wood'      => [800, 'float', 'start', '시작 나무', ''],
        'start_iron'      => [300, 'float', 'start', '시작 철광석', ''],
        'start_gold'      => [0, 'float', 'start', '시작 금괴', ''],
        'start_buildings' => ['farm,lumber', 'str', 'start', '시작 건물', '새 마을에 Lv1 로 지어 주는 건물 코드 (쉼표 구분)'],

        // 화면
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
