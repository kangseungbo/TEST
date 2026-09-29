<?php
// 이 파일을 config.local.php 로 복사한 뒤 값을 채운다. (config.local.php 는 git 에 올리지 않음)

// ── DB (Synology MariaDB 10 기준) ──
$VG_CONFIG['db_host'] = 'localhost';
$VG_CONFIG['db_port'] = 3307;                       // MariaDB 10 기본 포트. 5 버전이면 3306
// $VG_CONFIG['db_socket'] = '/run/mysqld/mysqld10.sock';  // 포트 연결이 안 되면 이 줄 사용
$VG_CONFIG['db_user'] = 'village2';
$VG_CONFIG['db_pass'] = '여기에_비밀번호';
$VG_CONFIG['db_name'] = 'village2_db';

// ── 포털 연동 ──
// getSessionUser() 가 들어 있는 파일. village2 폴더 기준 ../shared/auth.php 가 기본값
// $VG_CONFIG['auth_file'] = '/volume1/web/portal/shared/auth.php';
$VG_CONFIG['login_url'] = '/portal/';

// ── 관리자 (포털 사용자 id 또는 로그인명) ──
$VG_CONFIG['admins'] = ['내_포털_아이디'];
