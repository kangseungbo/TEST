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

// ───────────── 3단계: 병종·연구 ─────────────

function vg_unit_categories(): array
{
    return ['worker' => '일꾼', 'infantry' => '보병', 'ranged' => '원거리', 'cavalry' => '기병', 'siege' => '공성'];
}

/**
 * 병종 기본값. upkeep = 1명당 시간당 식량, speed = 평원 1타일 이동 초(4단계), carry = 약탈 운반량(5단계).
 * counters = 이 병종이 상성상 강한 상대 병종 코드.
 */
function vg_default_unit_defs(): array
{
    return [
        'worker' => ['name' => '일꾼', 'train_bld' => 'hall', 'req_level' => 1, 'category' => 'worker',
            'cost' => ['money' => 20, 'food' => 30], 'train_time' => 15, 'upkeep' => 5, 'attack' => 3, 'defense' => 3,
            'speed' => 60, 'carry' => 60, 'counters' => [],
            'descr' => '싸움에는 약하지만 마을에 있으면 건설을 빠르게 한다. 4단계부터 자원 거점 채집·다리 건설에 쓴다.'],
        'spearman' => ['name' => '창병', 'train_bld' => 'barracks', 'req_level' => 1, 'category' => 'infantry',
            'cost' => ['money' => 40, 'wood' => 50, 'iron' => 20], 'train_time' => 25, 'upkeep' => 10, 'attack' => 10, 'defense' => 18,
            'speed' => 60, 'carry' => 20, 'counters' => ['cavalry', 'elephant'], 'descr' => '긴 창으로 기병을 막는 방어형 보병.'],
        'swordsman' => ['name' => '검사', 'train_bld' => 'barracks', 'req_level' => 3, 'category' => 'infantry',
            'cost' => ['money' => 60, 'food' => 30, 'iron' => 50], 'train_time' => 35, 'upkeep' => 12, 'attack' => 18, 'defense' => 12,
            'speed' => 60, 'carry' => 15, 'counters' => ['worker', 'ram'], 'descr' => '근접전에 강한 공격형 보병.'],
        'guard' => ['name' => '근위기사', 'train_bld' => 'barracks', 'req_level' => 8, 'category' => 'infantry',
            'cost' => ['money' => 150, 'iron' => 120, 'gold' => 5], 'train_time' => 90, 'upkeep' => 20, 'attack' => 30, 'defense' => 30,
            'speed' => 70, 'carry' => 10, 'counters' => [], 'descr' => '중갑을 두른 정예 보병. 느리지만 단단하다.'],
        'archer' => ['name' => '궁수', 'train_bld' => 'archery', 'req_level' => 1, 'category' => 'ranged',
            'cost' => ['money' => 50, 'wood' => 70], 'train_time' => 30, 'upkeep' => 10, 'attack' => 16, 'defense' => 8,
            'speed' => 60, 'carry' => 15, 'ranged' => 1, 'counters' => ['spearman'], 'descr' => '멀리서 활을 쏜다. 창병에 강하다.'],
        'cavalry' => ['name' => '기병', 'train_bld' => 'stable', 'req_level' => 1, 'category' => 'cavalry',
            'cost' => ['money' => 120, 'food' => 100, 'iron' => 60], 'train_time' => 60, 'upkeep' => 25, 'attack' => 26, 'defense' => 14,
            'speed' => 30, 'carry' => 40, 'counters' => ['archer', 'catapult'], 'descr' => '빠르게 달려 궁수를 친다.'],
        'elephant' => ['name' => '코끼리병', 'train_bld' => 'stable', 'req_level' => 6, 'category' => 'cavalry',
            'cost' => ['money' => 300, 'food' => 400, 'gold' => 10], 'train_time' => 150, 'upkeep' => 60, 'attack' => 50, 'defense' => 40,
            'speed' => 80, 'carry' => 100, 'counters' => ['swordsman', 'guard'], 'descr' => '거대한 전투 코끼리. 보병 대열을 무너뜨린다.'],
        'ram' => ['name' => '공성추', 'train_bld' => 'workshop', 'req_level' => 1, 'category' => 'siege',
            'cost' => ['money' => 150, 'wood' => 300, 'iron' => 50], 'train_time' => 120, 'upkeep' => 30, 'attack' => 5, 'defense' => 20,
            'speed' => 120, 'carry' => 0, 'counters' => [], 'descr' => '성문과 성벽을 부순다 (5단계 전투에서 성벽 피해).'],
        'catapult' => ['name' => '투석기', 'train_bld' => 'workshop', 'req_level' => 4, 'category' => 'siege',
            'cost' => ['money' => 200, 'wood' => 350, 'iron' => 100], 'train_time' => 150, 'upkeep' => 40, 'attack' => 40, 'defense' => 5,
            'speed' => 120, 'carry' => 0, 'ranged' => 1, 'counters' => [], 'descr' => '먼 거리에서 돌을 날린다.'],
    ];
}

/** 연구 효과 종류: 효과 코드 => [이름, 적용 단계 설명] */
function vg_research_effects(): array
{
    return [
        'attack_pct'      => ['공격력', '5단계 전투'],
        'defense_pct'     => ['방어력', '5단계 전투'],
        'train_speed_pct' => ['훈련 속도', '지금 적용'],
        'march_speed_pct' => ['행군 속도', '4단계 이동'],
        'carry_pct'       => ['운반량', '5단계 약탈'],
    ];
}

function vg_default_research_defs(): array
{
    return [
        'weapons' => ['name' => '무기단조', 'effect' => 'attack_pct', 'target' => 'infantry', 'value' => 5, 'max_level' => 10, 'req_smithy' => 1,
            'cost' => ['money' => 100, 'iron' => 80], 'base_time' => 60, 'descr' => '보병 공격력 증가'],
        'armor' => ['name' => '갑옷제작', 'effect' => 'defense_pct', 'target' => 'all', 'value' => 4, 'max_level' => 10, 'req_smithy' => 1,
            'cost' => ['money' => 100, 'iron' => 100], 'base_time' => 60, 'descr' => '모든 병종 방어력 증가'],
        'bows' => ['name' => '활 개량', 'effect' => 'attack_pct', 'target' => 'ranged', 'value' => 6, 'max_level' => 10, 'req_smithy' => 2,
            'cost' => ['money' => 100, 'wood' => 150], 'base_time' => 70, 'descr' => '궁수·투석기 공격력 증가'],
        'harness' => ['name' => '마구 개량', 'effect' => 'attack_pct', 'target' => 'cavalry', 'value' => 5, 'max_level' => 10, 'req_smithy' => 3,
            'cost' => ['money' => 150, 'food' => 150, 'iron' => 80], 'base_time' => 80, 'descr' => '기병·코끼리병 공격력 증가'],
        'siegecraft' => ['name' => '공성 기술', 'effect' => 'attack_pct', 'target' => 'siege', 'value' => 8, 'max_level' => 10, 'req_smithy' => 4,
            'cost' => ['money' => 200, 'wood' => 200, 'iron' => 100], 'base_time' => 90, 'descr' => '공성 병기 공격력 증가'],
        'drill' => ['name' => '훈련 교범', 'effect' => 'train_speed_pct', 'target' => 'all', 'value' => 5, 'max_level' => 10, 'req_smithy' => 2,
            'cost' => ['money' => 150, 'food' => 100], 'base_time' => 80, 'descr' => '모든 훈련 속도 증가'],
        'march' => ['name' => '행군 교범', 'effect' => 'march_speed_pct', 'target' => 'all', 'value' => 4, 'max_level' => 10, 'req_smithy' => 3,
            'cost' => ['money' => 150, 'food' => 150], 'base_time' => 80, 'descr' => '부대 이동 속도 증가'],
        'carts' => ['name' => '수레 개량', 'effect' => 'carry_pct', 'target' => 'all', 'value' => 10, 'max_level' => 5, 'req_smithy' => 2,
            'cost' => ['money' => 120, 'wood' => 150], 'base_time' => 70, 'descr' => '약탈 운반량 증가'],
    ];
}

/** 주민 이름 후보 */
function vg_villager_names(): array
{
    return ['돌쇠', '마당쇠', '순이', '복남', '삼월이', '칠복', '억쇠', '귀남', '막동', '끝순', '덕배', '판돌', '옥분', '갑돌',
        '을순', '만석', '봉팔', '춘삼', '점순', '금동', '말순', '길동', '복순', '영칠', '달래', '두꺼비', '방울', '보리', '팥쥐', '콩쥐'];
}
