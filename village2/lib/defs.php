<?php
// 고정 목록(자원·건물 분류)과 건물 정의 초기 기본값. 기본값은 DB(vg_building_defs)에 한 번 들어간 뒤
// 관리자 건물 탭에서 수정한다. 게임 로직은 항상 DB 값을 읽는다.

const VG_RES = ['money', 'food', 'wood', 'iron', 'gold'];

function vg_res_names(): array
{
    return ['money' => '돈', 'food' => '식량', 'wood' => '나무', 'iron' => '철광석', 'gold' => '금괴'];
}

/** 성벽 등 둘레 건물용 예약 슬롯 (90번대). 일반 칸은 1~16, 회관은 0 */
const VG_SLOT_HALL = 0;
const VG_SLOT_WALL = 90;

function vg_building_categories(): array
{
    return [
        'hall'       => '마을회관',
        'production' => '생산',
        'storage'    => '창고',
        'military'   => '훈련',
        'research'   => '연구',
        'defense'    => '방어',
        'wall'       => '성벽(둘레)',
    ];
}

function vg_default_building_defs(): array
{
    return [
        'hall' => ['name' => '마을회관', 'category' => 'hall', 'produces' => 'money', 'base_rate' => 0.5, 'rate_growth' => 1.08,
            'cost' => ['money' => 200, 'wood' => 300, 'iron' => 60], 'cost_growth' => 1.6, 'base_time' => 45, 'time_growth' => 1.55,
            'max_level' => 20, 'req_hall' => 1,
            'descr' => '마을의 중심. 다른 건물의 레벨 상한과 마을 칸 수를 정한다. 세금으로 돈을 조금 번다.'],
        'farm' => ['name' => '농장', 'category' => 'production', 'produces' => 'food', 'base_rate' => 1.0, 'rate_growth' => 1.08,
            'cost' => ['money' => 40, 'wood' => 60], 'cost_growth' => 1.45, 'base_time' => 12, 'time_growth' => 1.45,
            'multi' => 1, 'descr' => '식량을 생산한다.'],
        'lumber' => ['name' => '벌목장', 'category' => 'production', 'produces' => 'wood', 'base_rate' => 1.0, 'rate_growth' => 1.08,
            'cost' => ['money' => 40, 'food' => 40], 'cost_growth' => 1.45, 'base_time' => 12, 'time_growth' => 1.45,
            'multi' => 1, 'descr' => '나무를 생산한다.'],
        'mine' => ['name' => '광산', 'category' => 'production', 'produces' => 'iron', 'base_rate' => 0.6, 'rate_growth' => 1.08,
            'cost' => ['money' => 60, 'wood' => 80, 'food' => 30], 'cost_growth' => 1.45, 'base_time' => 18, 'time_growth' => 1.45,
            'multi' => 1, 'descr' => '철광석을 캔다.'],
        'market' => ['name' => '시장', 'category' => 'production', 'produces' => 'money', 'base_rate' => 0.8, 'rate_growth' => 1.08,
            'cost' => ['wood' => 120, 'food' => 60], 'cost_growth' => 1.5, 'base_time' => 25, 'time_growth' => 1.5,
            'multi' => 1, 'req_hall' => 2, 'descr' => '장사로 돈을 번다.'],
        'smelter' => ['name' => '제련소', 'category' => 'production', 'produces' => 'gold', 'base_rate' => 0.1, 'rate_growth' => 1.08,
            'cost' => ['money' => 150, 'wood' => 150, 'iron' => 100], 'cost_growth' => 1.55, 'base_time' => 40, 'time_growth' => 1.5,
            'multi' => 1, 'req_hall' => 3, 'descr' => '철광석을 녹여 금괴를 만든다. 철광석 재고가 있을 때만 돌아간다.'],
        'warehouse' => ['name' => '창고', 'category' => 'storage', 'produces' => '', 'base_rate' => 3000, 'rate_growth' => 1.1,
            'cost' => ['money' => 80, 'wood' => 150], 'cost_growth' => 1.5, 'base_time' => 20, 'time_growth' => 1.5,
            'multi' => 1, 'req_hall' => 2, 'descr' => '자원별 보관 한도를 늘린다.'],
        'barracks' => ['name' => '병영', 'category' => 'military',
            'cost' => ['money' => 150, 'wood' => 200, 'iron' => 50], 'cost_growth' => 1.55, 'base_time' => 40, 'time_growth' => 1.5,
            'multi' => 1, 'req_hall' => 2, 'descr' => '민병·창병·검사·근위기사를 훈련한다.'],
        'archery' => ['name' => '궁사양성소', 'category' => 'military',
            'cost' => ['money' => 150, 'wood' => 250, 'iron' => 30], 'cost_growth' => 1.55, 'base_time' => 45, 'time_growth' => 1.5,
            'multi' => 1, 'req_hall' => 3, 'descr' => '궁수를 훈련한다.'],
        'stable' => ['name' => '마굿간', 'category' => 'military',
            'cost' => ['money' => 250, 'wood' => 250, 'food' => 200, 'iron' => 80], 'cost_growth' => 1.55, 'base_time' => 60, 'time_growth' => 1.5,
            'multi' => 1, 'req_hall' => 4, 'descr' => '기병·코끼리병을 훈련한다.'],
        'workshop' => ['name' => '공성작업장', 'category' => 'military',
            'cost' => ['money' => 300, 'wood' => 400, 'iron' => 200], 'cost_growth' => 1.6, 'base_time' => 80, 'time_growth' => 1.5,
            'multi' => 1, 'req_hall' => 5, 'descr' => '공성추·투석기를 만든다.'],
        'smithy' => ['name' => '대장간', 'category' => 'research',
            'cost' => ['money' => 200, 'wood' => 150, 'iron' => 150], 'cost_growth' => 1.6, 'base_time' => 60, 'time_growth' => 1.5,
            'req_hall' => 3, 'descr' => '무기단조·갑옷제작 등 병력 강화 연구를 한다.'],
        'watchtower' => ['name' => '망루', 'category' => 'defense',
            'cost' => ['money' => 100, 'wood' => 200], 'cost_growth' => 1.5, 'base_time' => 30, 'time_growth' => 1.5,
            'req_hall' => 2, 'descr' => '시야 반경과 정찰 범위를 넓힌다.'],
        'wall' => ['name' => '성벽', 'category' => 'wall',
            'cost' => ['money' => 150, 'wood' => 300, 'iron' => 100], 'cost_growth' => 1.55, 'base_time' => 50, 'time_growth' => 1.5,
            'req_hall' => 3, 'descr' => '마을 둘레를 감싸는 성벽. 칸을 차지하지 않는다.'],
    ];
}
