<?php

namespace Plugins\G7\Light\Wiki\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\Doc\WikiBoardFlag;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 글쓰기 폼 **메타** 응답에 `board.wiki` 를 얹는다.
 *
 * 붙는 곳은 `plugin.php::getMiddleware()` 의 `targets` 하나뿐이다:
 * `api.modules.sirsoft-board.boards.posts.form-meta`. 코어 게이트
 * (`ExtensionMiddlewareGate`)가 라우트명을 대조하므로 글 저장(POST·PUT)에는 실행조차
 * 되지 않는다.
 *
 * ## 왜 `form-data` 가 아니라 `form-meta` 인가
 *
 * 작성 화면의 `form-data` 응답은 템플릿이 **폼 상태(`_local.form`)로 통째로 받아** 저장
 * 버튼에서 그대로 POST 한다. 거기에 칸을 하나 더하면 그 칸이 **글 저장 요청 본문에 실려
 * 나간다.** 반면 `form-meta` 는 게시판 정보를 담은 읽기 전용 메타라 화면 분기에 쓰기
 * 알맞고, 저장 요청에 섞이지 않는다.
 *
 * ## 하는 일은 셋뿐이다
 *
 * 1. **위키 게시판인가** — 아니면 응답 객체를 건드리지 않는다. 그럴 때 응답 바이트는
 *    설치 전과 같다.
 * 2. 200 인 JSON 응답인가 — 코어가 낸 403·401 은 그대로 내보낸다.
 * 3. 얹기를 {@see WikiBoardFlag} 에 넘기고, 실패하면 **원본 응답을 그대로 내보낸다**.
 */
class WikiFormMetaExtension
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
            $data = $response->getData(true);

            if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
                return $response;
            }

            $data['data'] = WikiBoardFlag::withWiki($data['data'], WikiBoardFlag::formMetaValue());

            // setData 는 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다.
            $response->setData($data);
        } catch (\Throwable $e) {
            // 얹기 실패가 작성 화면 자체를 막으면 안 된다 — 원본 응답을 그대로 내보낸다.
            Log::warning('[g7-light-wiki] 폼 메타 위키 표시 실패 (원본 응답을 그대로 내보냅니다)', [
                'board_slug' => $slug,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }
}
