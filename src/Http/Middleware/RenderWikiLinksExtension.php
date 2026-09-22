<?php

namespace Plugins\G7\Light\Wiki\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\Doc\DocPageRenderer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 글 상세 응답을 위키 문서로 가공할지 가르는 곳.
 *
 * 붙는 곳은 `plugin.php::getMiddleware()` 의 `targets` 하나뿐이다:
 * `api.modules.sirsoft-board.boards.posts.show`.
 *
 * ## 이 클래스가 하는 일은 셋뿐이다
 *
 * 1. **위키 게시판인가** — 아니면 응답 객체를 건드리지 않는다. 그럴 때 응답 바이트는
 *    설치 전과 같다.
 * 2. JSON 응답인가.
 * 3. 가공을 {@see DocPageRenderer} 에 넘기고, 실패하면 **원본 응답을 그대로 내보낸다**.
 *
 * 본문 치환·자동 영역 조립·조회는 전부 `Support\Doc` 쪽에 있다. 전에는 이 파일이 591줄로
 * 그 일을 다 쥐고 있었다.
 */
class RenderWikiLinksExtension
{
    public function handle(Request $request, Closure $next): mixed
    {
        $slug = (string) $request->route('slug');
        $boardId = $slug === '' ? null : BoardLookup::id($slug);

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return $next($request);
        }

        $response = $next($request);

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        try {
            return (new DocPageRenderer($slug, (int) $boardId, $request->user()))->apply($response);
        } catch (\Throwable $e) {
            // 가공 실패가 글 조회 자체를 막으면 안 된다 — 원본 응답을 그대로 내보낸다.
            Log::warning('[g7-light-wiki] 본문 위키 표기 가공 실패 (원본 응답을 그대로 내보냅니다)', [
                'board_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $response;
        }
    }
}
