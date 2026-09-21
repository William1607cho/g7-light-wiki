<?php

namespace Plugins\G7\Light\Wiki\Http\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiGate;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 랜덤 문서로 보낸다 — `GET /api/plugins/g7-light-wiki/random?board={id}`
 *
 * 대문의 "랜덤" 링크가 이 주소를 그대로 가리킨다. 본문 앵커는 SPA 가 가로채지 않으므로
 * 브라우저가 전체 이동으로 들어오고, 여기서 내보내는 302 를 그대로 따라간다.
 *
 * 권한은 **호출마다** 그 게시판 읽기 권한으로 판정한다(비회원 401 / 회원 403).
 * 위키 게시판이 아니면 404 — 이 확장이 관여하지 않는 게시판의 존재를 알려 주지 않는다.
 */
class RandomDocController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|JsonResponse
    {
        $boardId = (int) $request->query('board');

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return ResponseHelper::notFound('messages.board.not_wiki', domain: WikiBoardSettings::IDENTIFIER);
        }

        $slug = BoardLookup::slug($boardId);

        if ($slug === null) {
            return ResponseHelper::notFound('messages.board.not_wiki', domain: WikiBoardSettings::IDENTIFIER);
        }

        WikiGate::assert($request, $slug, 'posts.read');

        $frontPostId = WikiBoardSettings::frontPostId($boardId);
        $postId = WikiDocQuery::randomPostId($boardId, $frontPostId);

        if ($postId !== null) {
            return redirect(WikiUrl::post($slug, $postId));
        }

        // 후보가 없으면 대문으로, 대문도 없으면 게시판 목록으로 보낸다.
        return redirect($frontPostId !== null
            ? WikiUrl::post($slug, $frontPostId)
            : WikiUrl::board($slug));
    }
}
