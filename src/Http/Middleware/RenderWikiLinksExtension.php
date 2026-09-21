<?php

namespace Plugins\G7\Light\Wiki\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\FrontPlaceholderRenderer;
use Plugins\G7\Light\Wiki\Support\HtmlLinkRewriter;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiGate;
use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 글 상세 응답의 본문에서 위키 표기를 링크·목록으로 바꾼다.
 *
 * 붙는 곳은 `plugin.php::getMiddleware()` 의 `targets` 하나뿐이다:
 * `api.modules.sirsoft-board.boards.posts.show`.
 *
 * ## 첫 줄에서 가른다
 *
 * 위키 게시판이 아니면 **응답 객체를 건드리지 않는다**. 본문에 `[[` 가 없거나
 * `content_mode` 가 `html` 이 아니어도 마찬가지다. 그럴 때 응답 바이트는 설치 전과 같다.
 *
 * ## `content_mode` 를 보는 이유
 *
 * 상세 응답의 `data.content` 는 저장된 문자열 그대로이고, `content_mode` 가 `html` 이
 * 아니면 방문자 화면과 봇 SSR 이 **본문을 통째로 이스케이프해 평문으로** 그린다.
 * 그 상태에서 `<a>` 를 끼워 넣으면 태그가 글자로 보인다. 그래서 HTML 모드에서만 바꾼다.
 *
 * ## 조회 횟수
 *
 * 본문에 표기가 있으면 슬러그→게시판 ID 1회, 제목 일괄 조회 1회(표기가 몇 개든 1회다).
 * 자리표시가 있으면 **종류(와 개수)마다 목록 조회 1회**가 더 붙는다. 같은 자리표시를 한
 * 문서에 여러 번 써도 조회는 한 번이고(랜덤만 예외 — 매번 다시 뽑는다),
 * `[[#둘러보기]]` 는 두 단을 채우므로 2회다.
 * 자리표시가 없는 문서에는 렌더러를 만들지 않으므로 읽기 권한 판정도 돌지 않는다.
 *
 * ## 어느 글에서 자리표시가 채워지는가
 *
 * 1단계에서는 대문 글뿐이었다. 1.5단계부터는 **위키 게시판의 HTML 모드 문서라면 어디서든**
 * 채운다. 후보에서는 대문 글과 "지금 그리는 그 문서 자신" 을 뺀다.
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
            return $this->rewrite($request, $response, $slug, (int) $boardId);
        } catch (\Throwable $e) {
            // 가공 실패가 글 조회 자체를 막으면 안 된다 — 원본 응답을 그대로 내보낸다.
            Log::warning('[g7-light-wiki] 본문 위키 표기 가공 실패 (원본 응답을 그대로 내보냅니다)', [
                'board_slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $response;
        }
    }

    /**
     * 본문 하나를 가공해 응답에 되쓴다.
     */
    private function rewrite(Request $request, JsonResponse $response, string $slug, int $boardId): JsonResponse
    {
        $data = $response->getData(true);

        if (! is_array($data) || ! isset($data['data']) || ! is_array($data['data'])) {
            return $response;
        }

        $body = $data['data']['content'] ?? null;

        if (! is_string($body) || $body === '' || ! str_contains($body, '[[')) {
            return $response;
        }

        if (($data['data']['content_mode'] ?? 'text') !== 'html') {
            return $response;
        }

        $rewriter = new HtmlLinkRewriter($body);

        if (! $rewriter->hasMarkup()) {
            return $response;
        }

        $postId = (int) ($data['data']['id'] ?? 0);

        // 1.5단계: 자리표시는 대문 글 전용이 아니다. 자리표시가 실제로 있는 문서면
        // 그 문서가 대문이든 아니든 채운다. 후보에서는 대문 글과 지금 그리는 문서 자신을 뺀다.
        $renderer = $rewriter->hasPlaceholder()
            ? $this->placeholderRenderer($request, $slug, $boardId, [
                (int) (WikiBoardSettings::frontPostId($boardId) ?? 0),
                $postId,
            ])
            : null;

        $rewritten = $rewriter->rewrite($this->resolver(
            $request,
            $slug,
            $boardId,
            $rewriter->linkTargets(),
            $renderer,
        ));

        if ($rewritten === $body) {
            return $response;
        }

        $data['data']['content'] = $rewritten;

        // setData 는 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다 — 본문 밖은 바이트가 같다.
        $response->setData($data);

        return $response;
    }

    /**
     * 토큰 하나를 대체 HTML 로 바꾸는 판정기.
     *
     * @param  list<string>  $targets  본문에 등장한 문서 이름(원문)
     */
    private function resolver(
        Request $request,
        string $slug,
        int $boardId,
        array $targets,
        ?FrontPlaceholderRenderer $front
    ): Closure {
        // 제목 존재 여부는 조회 1회로 끝낸다.
        $normalizedByTarget = [];

        foreach ($targets as $target) {
            $normalizedByTarget[$target] = TitleNormalizer::normalize($target);
        }

        $found = WikiDocQuery::resolve($boardId, array_values($normalizedByTarget));
        $canWrite = WikiGate::canWrite($slug, $request->user());

        return function (array $token) use ($slug, $boardId, $normalizedByTarget, $found, $canWrite, $front): ?string {
            if ($token['kind'] === WikiMarkupParser::KIND_PLACEHOLDER) {
                return $front?->render($token);
            }

            if ($token['kind'] !== WikiMarkupParser::KIND_LINK) {
                // 예약 표기(`[[분류:…]]`·`[[연표:…]]`)는 1단계에서 원문 그대로 둔다.
                return null;
            }

            $target = (string) $token['target'];
            $normalized = $normalizedByTarget[$target] ?? TitleNormalizer::normalize($target);

            if (! TitleNormalizer::isRegistrable($normalized)) {
                return null;
            }

            $label = $token['label'] ?? $target;

            if (isset($found[$normalized])) {
                return WikiHtml::link(WikiUrl::post($slug, $found[$normalized]['post_id']), $label);
            }

            return $canWrite
                ? WikiHtml::newLink(WikiUrl::newDoc($boardId, $target), $label)
                : WikiHtml::newText($label);
        };
    }

    /**
     * 자리표시 렌더러 — 호출부가 자리표시 존재를 확인한 뒤에만 만든다.
     *
     * 목록 조회는 그 자리표시가 실제로 치환될 때만 일어난다(공급자가 클로저라서).
     *
     * 랜덤 대상도 **여기서** 고른다. 플러그인 주소로 보내 서버가 302 로 고르게 하면 브라우저
     * 전체 이동에 토큰이 실리지 않아 로그인한 사람도 비회원으로 보인다. 이 응답은 토큰이
     * 실린 요청의 결과라 요청자 기준으로 고를 수 있다.
     *
     * @param  list<int>  $exclude  후보에서 뺄 글 ID (대문 글, 지금 그리는 문서 자신)
     */
    private function placeholderRenderer(Request $request, string $slug, int $boardId, array $exclude): FrontPlaceholderRenderer
    {
        // 같은 자리표시를 한 문서에 두 번 쓰면 조회도 두 번 돈다. 결과가 정해져 있는 것
        // (최근수정·최근작성·색인)은 개수별로 한 번만 조회한다. 색인은 최대 2000행이라
        // 두 번 도는 비용이 특히 크다.
        //
        // 랜덤은 **일부러 캐시하지 않는다** — 한 문서에 랜덤을 두 번 쓰면 서로 다른 문서가
        // 나오는 편이 자연스럽다.
        $recentMemo = [];
        $createdMemo = [];
        $indexMemo = null;

        return new FrontPlaceholderRenderer(
            $slug,
            WikiGate::canRead($slug, $request->user()),
            function (int $limit) use ($boardId, $exclude, &$recentMemo): array {
                return $recentMemo[$limit] ??= WikiDocQuery::recent($boardId, $exclude, $limit);
            },
            function (int $limit) use ($boardId, $exclude, &$createdMemo): array {
                return $createdMemo[$limit] ??= WikiDocQuery::recentCreated($boardId, $exclude, $limit);
            },
            function () use ($boardId, $exclude, &$indexMemo): array {
                return $indexMemo ??= WikiDocQuery::forIndex($boardId, $exclude);
            },
            static fn (): ?int => WikiDocQuery::randomPostId($boardId, $exclude),
            static fn (int $limit): array => WikiDocQuery::randomDocs($boardId, $exclude, $limit),
            self::labels(),
        );
    }

    /**
     * 자리표시가 쓰는 언어 파일 문구.
     *
     * @return array{random: string, other: string, empty: string, tour_created: string, tour_random: string}
     */
    private static function labels(): array
    {
        return [
            'random' => (string) __('g7-light-wiki::messages.front.random'),
            'other' => (string) __('g7-light-wiki::messages.front.other'),
            'empty' => (string) __('g7-light-wiki::messages.front.empty'),
            'tour_created' => (string) __('g7-light-wiki::messages.front.tour_created'),
            'tour_random' => (string) __('g7-light-wiki::messages.front.tour_random'),
        ];
    }
}
