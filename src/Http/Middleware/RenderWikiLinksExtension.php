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
 * 본문에 표기가 있으면 슬러그→게시판 ID 1회, 제목 일괄 조회 1회. 대문 글이면 자리표시가
 * 실제로 있을 때만 최근수정·색인 조회가 하나씩 더 붙는다.
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
        $frontPostId = WikiBoardSettings::frontPostId($boardId);
        $isFront = $frontPostId !== null && $frontPostId === $postId;

        $rewritten = $rewriter->rewrite($this->resolver(
            $request,
            $slug,
            $boardId,
            $rewriter->linkTargets(),
            $isFront ? $this->frontRenderer($request, $slug, $boardId, $frontPostId) : null,
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
     * 대문 글의 자리표시 렌더러 — 목록 조회는 자리표시가 실제로 있을 때만 일어난다.
     *
     * 랜덤 대상도 **여기서** 고른다. 플러그인 주소로 보내 서버가 302 로 고르게 하면 브라우저
     * 전체 이동에 토큰이 실리지 않아 로그인한 사람도 비회원으로 보인다. 이 응답은 토큰이
     * 실린 요청의 결과라 요청자 기준으로 고를 수 있다.
     */
    private function frontRenderer(Request $request, string $slug, int $boardId, int $frontPostId): FrontPlaceholderRenderer
    {
        $exclude = [$frontPostId];

        return new FrontPlaceholderRenderer(
            $slug,
            WikiGate::canRead($slug, $request->user()),
            static fn (int $limit): array => WikiDocQuery::recent($boardId, $exclude, $limit),
            static fn (int $limit): array => WikiDocQuery::recentCreated($boardId, $exclude, $limit),
            static fn (): array => WikiDocQuery::forIndex($boardId, $exclude),
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
