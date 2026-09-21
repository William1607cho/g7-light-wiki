<?php

namespace Plugins\G7\Light\Wiki\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Http\Controllers\NewDocController;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 작성 화면의 제목 초기값을 채운다.
 *
 * 붙는 곳은 `api.modules.sirsoft-board.boards.posts.form-data` 하나뿐이고,
 * **위키 게시판의 새 글 작성**일 때만 동작한다. 수정·답변 모드(`post_id`·`parent_id`)에는
 * 개입하지 않는다.
 *
 * ## 왜 세션인가
 *
 * 작성 폼의 데이터소스는 선언된 `post_id`·`parent_id` 만 API 로 넘긴다 — 페이지 주소의
 * 쿼리스트링은 서버에 오지 않는다. 템플릿을 고치지 않고 값을 건네려면 서버 쪽 통로가
 * 필요해서, 빨간 링크 → `…/new` (세션에 저장) → 302 → 작성 화면 → 이 미들웨어(세션에서
 * 꺼내 넣고 즉시 지움) 순서를 쓴다. 코어 별칭 `start.api.session` 과 같은 파이프라인을
 * 이 미들웨어 안에서 직접 돌린다(form-data 라우트에는 세션 미들웨어가 없다).
 *
 * 세션을 시작하는 것은 **위키 게시판의 새 글 작성 요청뿐**이다. 그 밖의 요청은 첫 줄에서
 * 걸러져 원본 응답을 그대로 돌려받는다.
 */
class PrefillWikiTitleExtension
{
    public function handle(Request $request, Closure $next): mixed
    {
        $slug = (string) $request->route('slug');
        $boardId = $slug === '' ? null : BoardLookup::id($slug);

        if (! WikiBoardSettings::isWikiBoard($boardId)) {
            return $next($request);
        }

        // 생성 모드에서만 개입한다 — 수정·답변 모드는 코어가 채운 값이 정답이다.
        if ($this->filled($request, 'post_id') || $this->filled($request, 'parent_id')) {
            return $next($request);
        }

        return (new Pipeline(app()))
            ->send($request)
            ->through([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
            ])
            ->then(function (Request $request) use ($next): mixed {
                $response = $next($request);

                try {
                    return $this->inject($request, $response);
                } catch (\Throwable $e) {
                    Log::warning('[g7-light-wiki] 작성 화면 제목 미리 채우기 실패 (원본 응답을 그대로 내보냅니다)', [
                        'error' => $e->getMessage(),
                    ]);

                    return $response;
                }
            });
    }

    /**
     * 세션에 실린 제목을 응답에 넣고 **즉시 지운다** (한 번만 쓰인다).
     */
    private function inject(Request $request, mixed $response): mixed
    {
        if (! $response instanceof JsonResponse || $response->getStatusCode() !== 200) {
            return $response;
        }

        if (! $request->hasSession()) {
            return $response;
        }

        $title = $request->session()->pull(NewDocController::SESSION_KEY);

        if (! is_string($title) || $title === '') {
            return $response;
        }

        $data = $response->getData(true);

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            return $response;
        }

        // 코어가 생성 모드에서 넣는 값은 빈 문자열이다. 그 자리만 채운다.
        if (($data['data']['title'] ?? '') !== '') {
            return $response;
        }

        $data['data']['title'] = $title;
        $response->setData($data);

        return $response;
    }

    /**
     * 쿼리 파라미터가 뜻 있는 값으로 들어왔는가 (코어 `getFormData()` 와 같은 판정).
     */
    private function filled(Request $request, string $key): bool
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' && $value !== 'undefined';
    }
}
