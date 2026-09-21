<?php

namespace Plugins\G7\Light\Wiki\Http\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiGate;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 없는 문서의 빨간 링크가 들어오는 곳 —
 * `GET /api/plugins/g7-light-wiki/new?board={id}&title={제목}`
 *
 * 제목을 **세션에 한 번만** 실어 두고 작성 화면으로 302 한다. 그 뒤 작성 폼이 부르는
 * `…posts.form-data` 응답에 {@see \Plugins\G7\Light\Wiki\Http\Middleware\PrefillWikiTitleExtension}
 * 이 그 값을 꺼내 제목 초기값으로 넣고 즉시 지운다.
 *
 * 쿼리스트링을 작성 화면 주소에 그대로 달지 않는 이유: 프론트 폼 데이터소스가 페이지
 * 쿼리를 API 로 넘기지 않아(선언된 `post_id`·`parent_id` 만 보낸다) 어차피 서버가 읽을
 * 수 없고, 템플릿 수정 없이 값을 건네려면 서버 쪽 통로가 필요하기 때문이다.
 */
class NewDocController extends Controller
{
    /** 세션 키 — 작성 화면 제목 초기값 */
    public const SESSION_KEY = 'g7lw.prefill_title';

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

        WikiGate::assert($request, $slug, 'posts.write');

        $title = mb_substr(trim((string) $request->query('title', '')), 0, TitleNormalizer::MAX_LENGTH, 'UTF-8');

        if ($title !== '' && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $title);
        }

        return redirect(WikiUrl::write($slug));
    }
}
