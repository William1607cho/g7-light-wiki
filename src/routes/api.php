<?php

use Illuminate\Support\Facades\Route;
use Plugins\G7\Light\Wiki\Http\Controllers\Admin\WikiBoardsAdminController;
use Plugins\G7\Light\Wiki\Http\Controllers\NewDocController;
use Plugins\G7\Light\Wiki\Http\Controllers\RandomDocController;

/*
 * g7-light-wiki 플러그인 API 라우트
 *
 * URL prefix: /api/plugins/g7-light-wiki  (PluginRouteServiceProvider 자동 적용)
 *
 * 두 공개 라우트는 **본문 링크가 직접 가리키는 주소**라 응답이 JSON 이 아니라 302 다.
 * 권한 미들웨어를 라우트에 걸지 않는 이유: 대상 게시판이 요청 인자로 오므로 라우트 정의
 * 시점에 권한 이름을 알 수 없다. 컨트롤러가 `WikiGate` 로 같은 판정을 한다
 * (비회원 401 / 회원 403, 위키 게시판이 아니면 404).
 */

// 랜덤 문서로 302 — 대문의 "랜덤" 링크가 이 주소를 가리킨다.
Route::get('random', RandomDocController::class)
    ->middleware(['optional.sanctum', 'throttle:60,1'])
    ->name('random');

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
