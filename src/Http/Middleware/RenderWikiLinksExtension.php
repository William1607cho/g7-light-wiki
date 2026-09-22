<?php

namespace Plugins\G7\Light\Wiki\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Support\BoardLookup;
use Plugins\G7\Light\Wiki\Support\DocFooterBuilder;
use Plugins\G7\Light\Wiki\Support\EventKey;
use Plugins\G7\Light\Wiki\Support\FrontPlaceholderRenderer;
use Plugins\G7\Light\Wiki\Support\HtmlLinkRewriter;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiCategory;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiGate;
use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;
use Plugins\G7\Light\Wiki\Support\WikiRefQuery;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 글 상세 응답의 본문에서 위키 표기를 링크·목록으로 바꾸고, 문서 뒤에 자동 영역을 붙인다.
 *
 * 붙는 곳은 `plugin.php::getMiddleware()` 의 `targets` 하나뿐이다:
 * `api.modules.sirsoft-board.boards.posts.show`.
 *
 * ## 첫 줄에서 가른다
 *
 * 위키 게시판이 아니면 **응답 객체를 건드리지 않는다**. 본문에 `[[` 가 없거나
 * `content_mode` 가 `html` 이 아니어도 마찬가지다. 그럴 때 응답 바이트는 설치 전과 같다.
 *
 * 예외가 하나 있다: 표기가 하나도 없어도 **역링크와 분류 소속 목록은 붙을 수 있다**(남이
 * 이 문서를 가리켰거나, 이 문서가 분류 문서인 경우). 그래서 `[[` 가 없는 본문도 위키
 * 게시판의 HTML 모드 문서라면 자동 영역 조립까지는 간다. 붙일 것이 없으면 그때 원본을
 * 그대로 돌려준다.
 *
 * ## `content_mode` 를 보는 이유
 *
 * 상세 응답의 `data.content` 는 저장된 문자열 그대로이고, `content_mode` 가 `html` 이
 * 아니면 방문자 화면과 봇 SSR 이 **본문을 통째로 이스케이프해 평문으로** 그린다.
 * 그 상태에서 `<a>` 를 끼워 넣으면 태그가 글자로 보인다. 그래서 HTML 모드에서만 바꾼다.
 *
 * ## 조회 횟수
 *
 * 자동 영역이 몇 덩이든, 자리표시가 몇 개든 **종류마다 1회**다.
 *
 * | 조회 | 언제 |
 * |---|---|
 * | 슬러그 → 게시판 ID | 늘 (1.5단계까지와 같다) |
 * | 제목 일괄 조회 | 링크 대상이나 분류가 하나라도 있을 때 1회 — 링크 대상과 분류 문서 제목을 **함께** 묻는다 |
 * | 별칭 해석 | 제목으로 못 찾은 이름이 남았을 때 1회 |
 * | 역링크 | 늘 1회 |
 * | 분류 소속 | 이 문서가 분류 문서이거나 `[[#분류\|…]]` 가 있을 때 1회 (분류가 몇 개든) |
 * | 사건 | `[[#연표]]` 가 있을 때 1회 (+`\|문서명` 이면 대상 문서를 찾는 1회) |
 * | 자리표시 목록 | 1.5단계와 같다 |
 *
 * 이 문서 **자신**의 분류·별칭·사건은 조회하지 않는다 — 지금 그리고 있는 본문에 적혀 있다.
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

        if (! is_string($body)) {
            return $response;
        }

        if (($data['data']['content_mode'] ?? 'text') !== 'html') {
            return $response;
        }

        $postId = (int) ($data['data']['id'] ?? 0);
        $titleNorm = TitleNormalizer::normalize((string) ($data['data']['title'] ?? ''));

        $rewriter = new HtmlLinkRewriter($body);
        $tokens = $rewriter->tokens();

        $canRead = WikiGate::canRead($slug, $request->user());

        // 본문 치환과 자동 영역이 쓸 것을 한 자리에서 모아 온다.
        $context = $this->context($request, $slug, $boardId, $postId, $titleNorm, $tokens, $canRead);

        $rewritten = $rewriter->hasMarkup()
            ? $rewriter->rewrite($this->resolver($slug, $boardId, $context, $request), pruneEmptyBlocks: true)
            : $body;

        // 자동 영역은 요청자가 이 게시판을 읽을 수 있을 때만 붙인다 — 목록에 남의 문서 제목이
        // 실리기 때문이다. 자기 본문은 코어가 이미 판정해 여기까지 왔다.
        $footer = $canRead
            ? DocFooterBuilder::build($slug, $context['footer'], self::docLabels())
            : '';

        $final = $rewritten.$footer;

        if ($final === $body) {
            return $response;
        }

        $data['data']['content'] = $final;

        // setData 는 이 응답이 쥐고 있는 인코딩 옵션 그대로 되쓴다 — 본문 밖은 바이트가 같다.
        $response->setData($data);

        return $response;
    }

    /**
     * 본문 치환과 자동 영역이 쓸 자료를 모은다 — 조회가 일어나는 **유일한** 자리.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return array{
     *     found: array<string, array{post_id: int, title: string}>,
     *     alias: array<string, array{post_id: int, title: string}>,
     *     members: array<string, array{items: list<array{post_id: int, title: string}>, more: int}>,
     *     canWrite: bool,
     *     renderer: ?FrontPlaceholderRenderer,
     *     footer: array<string, mixed>,
     * }
     */
    private function context(
        Request $request,
        string $slug,
        int $boardId,
        int $postId,
        string $titleNorm,
        array $tokens,
        bool $canRead
    ): array {
        $ownCategories = self::targetsOf($tokens, WikiMarkupParser::KIND_CATEGORY);
        $ownAliases = self::targetsOf($tokens, WikiMarkupParser::KIND_ALIAS);
        $linkTargets = self::targetsOf($tokens, WikiMarkupParser::KIND_LINK);

        // 이 문서가 분류 문서라면 자기 소속 목록도 필요하다.
        $isCategoryDoc = WikiCategory::isCategoryTitle($titleNorm);

        // `[[#분류|이름]]` 이 가리키는 분류들.
        $askedCategories = self::placeholderArguments($tokens, WikiMarkupParser::PLACEHOLDER_CATEGORY);

        // ── 조회 1: 제목. 링크 대상과 분류 문서 제목을 **함께** 묻는다.
        $normalizedByTarget = [];

        foreach ($linkTargets as $target) {
            $normalizedByTarget[$target] = TitleNormalizer::normalize($target);
        }

        $categoryTitles = [];

        foreach ($ownCategories as $name) {
            $categoryTitles[$name] = TitleNormalizer::normalize(WikiCategory::title($name));
        }

        $ask = array_values(array_unique(array_merge(
            array_values($normalizedByTarget),
            array_values($categoryTitles),
        )));

        $found = $ask === [] ? [] : WikiDocQuery::resolve($boardId, $ask);

        // ── 조회 2: 별칭. 제목으로 못 찾은 이름만 묻는다.
        $unresolved = array_values(array_unique(array_filter(
            array_values($normalizedByTarget),
            static fn (string $name): bool => ! isset($found[$name])
        )));

        $alias = $unresolved === [] ? [] : WikiRefQuery::aliasOwners($boardId, $unresolved);

        self::warnShadowedAliases($boardId, $normalizedByTarget, $found);

        // ── 조회 3: 역링크. 이 문서의 제목과 별칭을 모두 대상으로 본다.
        $backlinks = null;

        if ($canRead && $postId > 0 && $titleNorm !== '') {
            $names = array_merge(
                [$titleNorm],
                array_map(static fn (string $a): string => TitleNormalizer::normalize($a), $ownAliases),
            );

            $backlinks = WikiRefQuery::backlinks($boardId, $names, $postId);
        }

        // ── 조회 4: 분류 소속. 이 문서가 분류 문서인 것과 `[[#분류|…]]` 을 **한 번에** 묻는다.
        $wantMembers = [];

        if ($isCategoryDoc) {
            $wantMembers[] = WikiCategory::nameOf($titleNorm);
        }

        foreach ($askedCategories as $name) {
            $wantMembers[] = TitleNormalizer::normalize($name);
        }

        $wantMembers = array_values(array_unique(array_filter(
            $wantMembers,
            static fn (string $name): bool => $name !== ''
        )));

        $members = ($canRead && $wantMembers !== []) ? WikiRefQuery::categoryMembers($boardId, $wantMembers) : [];

        $canWrite = WikiGate::canWrite($slug, $request->user());

        $footer = [
            'category_members' => $isCategoryDoc
                ? ($members[WikiCategory::nameOf($titleNorm)] ?? ['items' => [], 'more' => 0])
                : null,
            'aliases' => $ownAliases,
            'backlinks' => $backlinks,
            'categories' => array_map(
                fn (string $name): string => $this->categoryLink($slug, $boardId, $name, $categoryTitles[$name] ?? '', $found, $canWrite),
                $ownCategories,
            ),
        ];

        return [
            'found' => $found,
            'alias' => $alias,
            'members' => $members,
            'canWrite' => $canWrite,
            'renderer' => $this->placeholderRenderer($request, $slug, $boardId, $postId, $tokens, $canRead, $members),
            'footer' => $footer,
        ];
    }

    /**
     * 분류 줄에 들어갈 링크 하나 — 그 분류 문서가 있으면 파란 링크, 없으면 기존 빨간 링크 규칙.
     *
     * @param  array<string, array{post_id: int, title: string}>  $found
     */
    private function categoryLink(
        string $slug,
        int $boardId,
        string $name,
        string $normalizedTitle,
        array $found,
        bool $canWrite
    ): string {
        if ($normalizedTitle !== '' && isset($found[$normalizedTitle])) {
            return WikiHtml::link(WikiUrl::post($slug, $found[$normalizedTitle]['post_id']), $name);
        }

        return $canWrite
            ? WikiHtml::newLink(WikiUrl::newDoc($boardId, WikiCategory::title($name)), $name)
            : WikiHtml::newText($name);
    }

    /**
     * 별칭이 다른 문서의 실제 제목에 가려졌으면 경고를 남긴다.
     *
     * 명령서 A절: 실제 제목이 이기고 그 별칭은 무효다. 조용히 무시하면 "왜 내 별칭으로
     * 연결되지 않지" 를 알 길이 없어 기록만 남긴다. 제목·별칭 **글자는 적지 않는다**.
     *
     * @param  array<string, string>  $normalizedByTarget
     * @param  array<string, array{post_id: int, title: string}>  $found
     */
    private static function warnShadowedAliases(int $boardId, array $normalizedByTarget, array $found): void
    {
        $resolved = array_values(array_unique(array_filter(
            array_values($normalizedByTarget),
            static fn (string $name): bool => isset($found[$name])
        )));

        if ($resolved === []) {
            return;
        }

        $shadowed = WikiRefQuery::aliasPostIds($boardId, $resolved, 20);

        if ($shadowed === []) {
            return;
        }

        Log::info('[g7-light-wiki] 실제 제목과 겹치는 별칭이 있어 그 별칭은 쓰이지 않습니다', [
            'board_id' => $boardId,
            'alias_post_ids' => $shadowed,
        ]);
    }

    /**
     * 토큰 하나를 대체 HTML 로 바꾸는 판정기.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolver(string $slug, int $boardId, array $context, Request $request): Closure
    {
        $found = $context['found'];
        $alias = $context['alias'];
        $canWrite = $context['canWrite'];
        $renderer = $context['renderer'];

        return function (array $token) use ($slug, $boardId, $found, $alias, $canWrite, $renderer): ?string {
            $kind = $token['kind'] ?? '';

            // 분류·별칭은 본문에서 **지운다** — 문서 아래 자동 영역으로 옮겨 간다.
            if ($kind === WikiMarkupParser::KIND_CATEGORY || $kind === WikiMarkupParser::KIND_ALIAS) {
                return '';
            }

            if ($kind === WikiMarkupParser::KIND_EVENT) {
                return $this->eventHtml($token);
            }

            if ($kind === WikiMarkupParser::KIND_PLACEHOLDER) {
                return $renderer?->render($token);
            }

            if ($kind !== WikiMarkupParser::KIND_LINK) {
                return null;
            }

            $target = (string) $token['target'];
            $normalized = TitleNormalizer::normalize($target);

            if (! TitleNormalizer::isRegistrable($normalized)) {
                return null;
            }

            $label = $token['label'] ?? $target;

            // 해석 순서: ① 실제 제목 ② 별칭. 별칭으로 이어진 링크도 파란 링크이고 주소는 본 문서다.
            $doc = $found[$normalized] ?? $alias[$normalized] ?? null;

            if ($doc !== null) {
                return WikiHtml::link(WikiUrl::post($slug, $doc['post_id']), $label);
            }

            return $canWrite
                ? WikiHtml::newLink(WikiUrl::newDoc($boardId, $target), $label)
                : WikiHtml::newText($label);
        };
    }

    /**
     * `[[연표:키|설명]]` 이 있던 자리 — 설명 글자만 남는다.
     *
     * 형식이 틀린 키는 사건이 아니므로 **원문 그대로** 둔다(`null`). 사람이 오타를 알아채려면
     * 적은 것이 그대로 보여야 한다.
     *
     * @param  array<string, mixed>  $token
     */
    private function eventHtml(array $token): ?string
    {
        $key = (string) ($token['target'] ?? '');

        if (! EventKey::isValid($key)) {
            return null;
        }

        $label = $token['label'] ?? null;

        return WikiHtml::eventInline(is_string($label) && $label !== '' ? $label : $key);
    }

    /**
     * 자리표시 렌더러 — 자리표시가 실제로 있을 때만 만든다.
     *
     * 목록 조회는 그 자리표시가 실제로 치환될 때만 일어난다(공급자가 클로저라서).
     *
     * 랜덤 대상도 **여기서** 고른다. 플러그인 주소로 보내 서버가 302 로 고르게 하면 브라우저
     * 전체 이동에 토큰이 실리지 않아 로그인한 사람도 비회원으로 보인다. 이 응답은 토큰이
     * 실린 요청의 결과라 요청자 기준으로 고를 수 있다.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @param  array<string, array{items: list<array{post_id: int, title: string}>, more: int}>  $members
     */
    private function placeholderRenderer(
        Request $request,
        string $slug,
        int $boardId,
        int $postId,
        array $tokens,
        bool $canRead,
        array $members
    ): ?FrontPlaceholderRenderer {
        $hasPlaceholder = false;

        foreach ($tokens as $token) {
            if (($token['kind'] ?? '') === WikiMarkupParser::KIND_PLACEHOLDER) {
                $hasPlaceholder = true;

                break;
            }
        }

        if (! $hasPlaceholder) {
            return null;
        }

        // 후보에서는 대문 글과 지금 그리는 문서 자신을 뺀다.
        $exclude = [(int) (WikiBoardSettings::frontPostId($boardId) ?? 0), $postId];

        // 같은 자리표시를 한 문서에 두 번 쓰면 조회도 두 번 돈다. 결과가 정해져 있는 것
        // (최근수정·최근작성·색인)은 개수별로 한 번만 조회한다. 색인은 최대 2000행이라
        // 두 번 도는 비용이 특히 크다.
        //
        // 랜덤은 **일부러 캐시하지 않는다** — 한 문서에 랜덤을 두 번 쓰면 서로 다른 문서가
        // 나오는 편이 자연스럽다.
        $recentMemo = [];
        $createdMemo = [];
        $indexMemo = null;
        $eventsMemo = [];

        return new FrontPlaceholderRenderer(
            $slug,
            $canRead,
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
            // 분류 소속 — 이미 한 번에 받아 둔 것에서 꺼낸다(추가 조회 없음).
            static function (string $name) use ($members): array {
                return $members[TitleNormalizer::normalize($name)] ?? ['items' => [], 'more' => 0];
            },
            // 연표 — 인자가 있으면 그 문서와 그 문서를 링크한 문서들의 사건.
            function (?string $docName) use ($boardId, &$eventsMemo): array {
                $key = $docName ?? '';

                return $eventsMemo[$key] ??= $this->timeline($boardId, $docName);
            },
            self::docLabels(),
        );
    }

    /**
     * 연표 자리표시가 쓸 사건 목록.
     *
     * 인자가 없으면 게시판 전체다(조회 1회). 인자가 있으면 그 문서와 **그 문서를 링크한
     * 문서들**의 사건이다 — 대상 문서를 찾는 조회 1회가 더 붙는다.
     *
     * @return array{items: list<array{post_id: int, title: string, target: string, label: string}>, more: int}
     */
    private function timeline(int $boardId, ?string $docName): array
    {
        if ($docName === null || trim($docName) === '') {
            return WikiRefQuery::events($boardId);
        }

        $normalized = TitleNormalizer::normalize($docName);

        // 문서명은 실제 제목일 수도 별칭일 수도 있다.
        $doc = WikiDocQuery::resolve($boardId, [$normalized])[$normalized]
            ?? WikiRefQuery::aliasOwners($boardId, [$normalized])[$normalized]
            ?? null;

        if ($doc === null) {
            return ['items' => [], 'more' => 0];
        }

        $postId = (int) $doc['post_id'];

        // 그 문서 + 그 문서를 가리킨 문서들. 중복은 `eventsOf` 가 걸러 낸다.
        $linkers = WikiRefQuery::backlinks($boardId, [$normalized], 0, WikiRefQuery::EVENT_LIMIT);

        $ids = array_merge(
            [$postId],
            array_map(static fn (array $row): int => (int) $row['post_id'], $linkers['items']),
        );

        return WikiRefQuery::eventsOf($boardId, $ids);
    }

    /**
     * 토큰에서 그 종류의 대상 이름을 등장 순서로 꺼낸다 (중복 제거).
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return list<string>
     */
    private static function targetsOf(array $tokens, string $kind): array
    {
        $out = [];

        foreach ($tokens as $token) {
            if (($token['kind'] ?? '') !== $kind) {
                continue;
            }

            $target = (string) ($token['target'] ?? '');

            if ($target !== '' && ! in_array($target, $out, true)) {
                $out[] = $target;
            }
        }

        return $out;
    }

    /**
     * 자리표시의 인자를 등장 순서로 꺼낸다 (중복 제거, 빈 인자 제외).
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return list<string>
     */
    private static function placeholderArguments(array $tokens, string $name): array
    {
        $out = [];

        foreach ($tokens as $token) {
            if (($token['kind'] ?? '') !== WikiMarkupParser::KIND_PLACEHOLDER || ($token['name'] ?? '') !== $name) {
                continue;
            }

            $argument = $token['argument'] ?? null;

            if (is_string($argument) && trim($argument) !== '' && ! in_array(trim($argument), $out, true)) {
                $out[] = trim($argument);
            }
        }

        return $out;
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

    /**
     * 자동 영역·연표가 쓰는 언어 파일 문구.
     *
     * @return array<string, string>
     */
    private static function docLabels(): array
    {
        $keys = ['categories', 'category_members', 'aliases', 'backlinks', 'timeline', 'more', 'empty', 'timeline_empty'];
        $out = [];

        foreach ($keys as $key) {
            $out[$key] = (string) __('g7-light-wiki::messages.doc.'.$key);
        }

        return $out;
    }
}
