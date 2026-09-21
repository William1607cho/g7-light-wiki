<?php

use Illuminate\Support\Facades\Route;
use Plugins\G7\Light\Wiki\Http\Controllers\Admin\WikiBoardsAdminController;
use Plugins\G7\Light\Wiki\Http\Controllers\NewDocController;

/*
 * g7-light-wiki 플러그인 API 라우트
 *
 * URL prefix: /api/plugins/g7-light-wiki  (PluginRouteServiceProvider 자동 적용)
 *
 * ## 페이지 이동으로 들어오는 주소에서는 권한을 판정하지 않는다
 *
 * 본문 링크를 누르면 브라우저가 **전체 페이지 이동**으로 이 주소에 온다. 그 요청에는
 * SPA 가 붙이는 `Authorization: Bearer …` 헤더가 없다 — 토큰은 localStorage 에 있고
 * `ApiClient` 가 XHR 에만 싣는다. 그래서 여기서 요청자를 확인하려 하면 로그인한 사람도
 * 비회원으로 보인다(랜덤 링크가 401 로 떨어지던 원인).
 *
 * 남은 식별 수단은 `start.api.session` 이 여는 세션뿐인데, 그 세션은 `/dev` 대시보드용으로
 * 로그인 때 곁들여 열리는 것이고 수명이 `SESSION_LIFETIME` 에 묶여 있다. 토큰이 아직
 * 살아 있어도 세션이 먼저 끊기면 같은 링크가 갑자기 막힌다.
 *
 * 그래서 이 라우트는 **위키 게시판인지만** 보고, 글쓰기 권한 판정은 토큰이 실려 오는
 * 곳(작성 폼 데이터 응답 — 코어가 이미 `posts.write` 로 막는다)과 코어 저장에 맡긴다.
 */

// 없는 문서의 빨간 링크 — 제목을 세션에 한 번 싣고 작성 화면으로 302.
// start.api.session: 제목을 URL 이 아니라 세션으로 건네기 위해 세션을 시작한다.
Route::get('new', NewDocController::class)
    ->middleware(['start.api.session', 'optional.sanctum', 'throttle:60,1'])
    ->name('new');

// 위키 게시판 설정(관리자) — 조회는 코어 플러그인 조회 권한, 저장은 코어 플러그인 수정 권한.
Route::prefix('admin')->name('admin.')->middleware('auth:sanctum')->group(function () {
    Route::get('wiki-boards', [WikiBoardsAdminController::class, 'show'])
        ->middleware('permission:admin,core.plugins.read')
        ->name('wiki-boards.show');

    Route::put('wiki-boards', [WikiBoardsAdminController::class, 'update'])
        ->middleware('permission:admin,core.plugins.update')
        ->name('wiki-boards.update');
});
