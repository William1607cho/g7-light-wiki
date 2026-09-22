<?php

namespace Plugins\G7\Light\Wiki\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\Doc\DocListRenderer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 글 목록 응답을 문서 목록으로 가공할지 가르는 곳.
 *
 * 붙는 곳은 `plugin.php::getMiddleware()` 의 `targets` 하나뿐이다:
 * `api.modules.sirsoft-board.boards.posts.index`.
 * 관리자 게시물 API(`api.modules.sirsoft-board.admin.*`)와 홈 위젯 API 는 대상이 아니라
 * 코어 게이트(`ExtensionMiddlewareGate`)가 이 미들웨어를 **실행조차 하지 않는다.**
 *
 * ## 이 클래스가 하는 일은 셋뿐이다
 *
 * 1. **위키 게시판인가** — 아니면 응답 객체를 건드리지 않는다.
 * 2. 200 인 JSON 응답인가 — 코어가 낸 비공개 게시판의 401 등은 그대로 내보낸다.
 * 3. 가공을 {@see DocListRenderer} 에 넘기고, 실패하면 **원본 응답을 그대로 내보낸다**.
 */
class WikiBoardListExtension
{
    public function handle(Request $request, Closure $next): mixed
    {
        $slug = (string) $request->route('slug');
        $boardId = $slug === '' ? null : BoardLookup::id($slug);

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return $next($request);
        }

        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }

        try {
            return (new DocListRenderer($slug, (int) $boardId, $request))->apply($response);
        } catch (\Throwable $e) {
            // 가공 실패가 목록 조회 자체를 막으면 안 된다 — 원본 응답을 그대로 내보낸다.
            Log::warning('[g7-light-wiki] 위키 목록 가공 실패 (원본 응답을 그대로 내보냅니다)', [
                'board_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $response;
        }
    }
}
