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
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 없는 문서의 빨간 링크가 들어오는 곳 —
 * `GET /api/plugins/g7-light-wiki/new?board={id}&title={제목}`
 *
 * 제목을 **세션에 한 번만** 실어 두고 작성 화면으로 302 한다. 그 뒤 작성 폼이 부르는
 * `…posts.form-data` 응답에 {@see \Plugins\G7\Light\Wiki\Http\Middleware\PrefillWikiTitleExtension}
 * 이 그 값을 꺼내 제목 초기값으로 넣고 즉시 지운다.
 *
 * ## 여기서 권한을 보지 않는 이유
 *
 * 이 주소에는 브라우저가 **전체 페이지 이동**으로 온다. 그 요청에는 SPA 가 붙이는 Bearer
 * 토큰이 없어(토큰은 localStorage 에 있고 `ApiClient` 가 XHR 에만 싣는다) 로그인한 사람도
 * 비회원으로 보인다. 세션으로 식별할 수는 있지만 그 세션은 `/dev` 대시보드용 부산물이고
 * 수명이 `SESSION_LIFETIME` 에 묶여 있어, 토큰이 살아 있는데도 링크가 갑자기 막힌다.
 *
 * 권한은 **토큰이 실려 오는 곳**에서 본다:
 *  - 작성 화면의 폼 데이터 API 는 코어가 이미 `sirsoft-board.{slug}.posts.write` 로 막는다.
 *  - 글 저장도 코어가 같은 권한으로 막는다.
 *  - 위 미들웨어도 요청자에게 글쓰기 권한이 없으면 세션 값을 쓰지 않고 지우기만 한다.
 *
 * 그래서 여기서 하는 일은 "위키 게시판인가"(아니면 404) 확인과 제목 전달뿐이다.
 * 302 가 가리키는 작성 화면 자체가 권한이 없으면 열리지 않으므로 새는 것이 없다.
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

        $title = mb_substr(trim((string) $request->query('title', '')), 0, TitleNormalizer::MAX_LENGTH, 'UTF-8');

        if ($title !== '' && $request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $title);
        }

        return redirect(WikiUrl::write($slug));
    }
}
