# 마을 전략 (village2)

사내 포털용 실시간 마을 전략 게임. PHP 8 + MySQL/MariaDB, 프레임워크 없음.
전체 설계는 [docs/PLAN.md](docs/PLAN.md) 참고.

## 진행 상황

| 단계 | 내용 | 상태 |
|---|---|---|
| 1 | 기반: 폴더·DB 자동 생성, SSO 연동, 설정 테이블, 관리자 골격 | ✅ |
| 2 | 마을: 자원 자동 축적, 건설·철거·배치, 격자 확장, 아이소메트릭 화면·카메라 | ✅ |
| 3 | 주민·병종·훈련 대기열·대장간 연구 | |
| 4 | 육각 맵·이동 | |
| 5 | 합류·동맹·전투 | |
| 6 | 안개·시야·PvP 안전장치 | |
| 7 | 협동 몬스터 | |

## 설치

1. 이 폴더를 `/volume1/web/portal/village2/` 에 둔다. 포털의 `shared/auth.php` 가 `../shared/auth.php` 위치에 있어야 한다.
2. 같은 폴더에 `config.local.php` 를 만든다 (git 에 올리지 않음).

   ```php
   <?php
   $VG_CONFIG['db_host'] = 'localhost';
   $VG_CONFIG['db_port'] = 3307;              // Synology MariaDB 10 기본 포트
   // $VG_CONFIG['db_socket'] = '/run/mysqld/mysqld10.sock';
   $VG_CONFIG['db_user'] = 'village2';
   $VG_CONFIG['db_pass'] = '********';
   $VG_CONFIG['admins']  = ['포털사용자id또는로그인명'];
   ```

   DB 사용자에게 `village2_db` 를 만들 권한(CREATE)이 있어야 한다. 없으면 DB 만 미리 만들어 두고 그 DB 에 모든 권한을 주면 된다.
3. `uploads/` 폴더에 웹 서버 쓰기 권한을 준다 (관리자 이미지 업로드용).
4. 브라우저로 `/portal/village2/` 를 열면 첫 접속 때 DB·테이블·기본 설정·건물 정의가 자동으로 만들어지고 내 마을이 생긴다.

관리자 판정: `config.local.php` 의 `admins` 목록, 또는 포털 `getSessionUser()` 결과에 `is_admin`/`admin` 참값이나 `role = admin` 이 있으면 관리자. 관리자는 상단에 `관리자` 링크가 보인다 (`/portal/village2/admin/`).

### 포털 사이드바 히든 링크

포털 사이드바의 TOOLS 섹션 제목을 링크로 감싸면 된다. 겉보기는 그대로 두고 제목 글자를 누를 때만 들어가게 한다.

```html
<a href="/portal/village2/" style="color:inherit;text-decoration:none;cursor:default">TOOLS</a>
```

## 폴더 구조

```
village2/
  index.php            게임 화면 (마을 / 세계 맵 / 기록)
  api.php              게임 JSON API
  config.php           기본 설정 (서버별 값은 config.local.php)
  lib/
    bootstrap.php      공통 로딩
    db.php             연결, DB 자동 생성, 마이그레이션(vg_migrations), 기본값 동기화
    settings.php       vg_settings 기본값 목록과 S('키') 조회
    defs.php           자원 목록, 건물 분류, 건물 정의 기본값
    auth.php           포털 SSO 정규화, CSRF
    village.php        정산·건설·철거·이동·화면 상태
  admin/               관리자 (탭별 tab_*.php, 처리 actions.php)
  assets/js/camera.js  공용 카메라 (휠·드래그·핀치·맞춤, 드래그 후 클릭 무시)
  assets/js/village.js 마을 화면
  uploads/             업로드 이미지 (스크립트 실행 차단 .htaccess)
  tests/run.php        CLI 테스트
```

## 설계 메모

- **정산(lazy settle)**: 자원은 조회·행동 시점에 `last_settle ~ 지금` 을 한 번에 계산한다. 구간 중간에 끝난 공사는 완공 시각까지 먼저 정산하고 레벨을 올린 뒤 이어서 정산하므로, 접속하지 않아도 결과가 같다.
- **원자적 선점**: 모든 변경은 트랜잭션 안에서 `vg_villages`·`vg_resources` 행을 `FOR UPDATE` 로 잠근 뒤 정산 → 행동 순서로 한다. 동시 요청이 와도 차감·생산이 중복되거나 사라지지 않는다 (테스트로 확인).
- **시간 일치**: 공사 시작·완료 시각은 서버가 정해 DB 에 저장하고, 화면은 서버 시계와의 차이를 보정해 같은 시각으로 남은 시간을 그린다. 완료 시각이 지나면 화면이 서버에서 다시 읽는다.
- **크리티컬 생산**: `crit_tick_sec` 주기마다 `crit_chance_pct` 확률로 그 주기 생산량이 `crit_mult` 배. 정산 구간의 주기 수로 이항분포 표본을 뽑아 한 번에 반영한다.
- **제련소**: 철광석을 `smelt_iron_per_gold` 개 써서 금괴 1개. 철광석 재고(+구간 중 광산 생산분)만큼만 제련하고, 바닥나면 들어오는 만큼만 돌아간다.
- **칸**: 회관은 0번(가운데), 일반 칸 1~16 은 안쪽부터 열림, 성벽 같은 둘레 건물은 90번대. 칸 수를 줄여도 이미 건물이 있는 칸은 보호된다.
- **설정**: 게임 수치는 전부 `vg_settings` / `vg_building_defs` 에 있고 관리자에서 바꾼다. 새 설정을 코드에 추가하면 `VG_DEFAULTS_VERSION` 을 올린다 (기존 값은 보존, 새 키만 추가). 스키마 변경은 `vg_migrations()` 에 새 번호로만 추가한다.

## 테스트

로컬 MySQL/MariaDB 에 `test` 가 들어간 이름의 DB 를 지정해 실행한다. 그 DB 는 지우고 다시 만든다.

```sh
VG_DB=village2_test php tests/run.php
```

정산 수치, 완공 시각 반영, 창고 한도, 건설 규칙(칸·회관 레벨·동시 공사·상한), 취소·철거 환급, 이동·맞바꾸기, 제련소 재고 제한, 크리티컬, 동시 요청 정산·지불을 검사한다.

## 2단계 동작 확인 체크리스트

- [ ] 첫 접속 시 DB 가 만들어지고 회관 Lv1 + 농장 + 벌목장이 있는 마을이 보인다
- [ ] 상단 자원 5종이 이름과 함께 표시되고, 초당 생산량만큼 숫자가 올라간다
- [ ] 빈 칸(+) 을 눌러 건물을 짓고, 말풍선 안 진행 막대와 남은 시간이 표시된다. 시간이 끝나면 바로 완공된다
- [ ] 여러 건물을 동시에 지을 수 있고 `동시 건설 수` 를 넘으면 막힌다
- [ ] 건물 위에 `이름 Lv` 과 `생산물 +N/s` 이 글자로 보이고 옆 타일 이름표와 겹치지 않는다
- [ ] 회관 레벨을 올리면 칸이 늘어나고, 다른 건물은 회관 + 2 레벨까지만 오른다
- [ ] 건물 이동(빈 칸)·맞바꾸기, 철거(일부 환급), 공사 취소(환급)가 된다
- [ ] 성벽 버튼으로 성벽을 지으면 마을 둘레에 뒤/앞 성벽이 그려진다
- [ ] 휠 줌(커서 기준)·드래그·핀치·+/−/맞춤이 되고, 드래그 후에는 클릭이 눌리지 않는다
- [ ] 관리자: 설정 검색·저장, 격자 확장 미리보기, 건물 정의 편집, 이미지 업로드(건물 3단계·성벽 뒤/앞), 플레이어 건물 레벨·자원 직접 변경, 초기화
