<?php
// village2 기본 설정.
// 서버마다 다른 값(DB 접속 정보, 관리자 목록 등)은 같은 폴더의 config.local.php 에서
// $VG_CONFIG['키'] = 값; 형태로 덮어쓴다. config.local.php 는 git 에 올리지 않는다.
// 게임 수치는 여기가 아니라 관리자 페이지 설정 탭(vg_settings)에서 관리한다.

$VG_CONFIG = [
    'db_host'   => 'localhost',
    'db_port'   => 3306,        // Synology MariaDB 10 기본 포트는 3307
    'db_socket' => '',          // 예: /run/mysqld/mysqld10.sock (지정 시 host/port 무시)
    'db_user'   => 'root',
    'db_pass'   => '',
    'db_name'   => 'village2_db',

    // 포털 SSO. getSessionUser() 를 제공하는 파일
    'auth_file' => __DIR__ . '/../shared/auth.php',
    // 포털 로그인 페이지 (미로그인 시 이동). 비우면 안내 문구만 표시
    'login_url' => '/portal/',

    // 관리자: 포털 사용자 id 또는 로그인명 목록.
    // 포털 사용자 정보에 is_admin / role=admin 이 있으면 그것도 관리자로 인정한다.
    'admins' => [],

    // auth_file 이 없을 때만 쓰는 개발용 가짜 사용자. 운영 서버에서는 반드시 null.
    // 예: ['id' => 'dev', 'name' => '개발자', 'admin' => true]
    'dev_user' => null,

    'upload_dir' => __DIR__ . '/uploads',
    'upload_url' => 'uploads',
    'timezone'   => 'Asia/Seoul',
];

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
