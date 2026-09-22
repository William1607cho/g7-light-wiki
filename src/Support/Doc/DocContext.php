<?php

namespace Plugins\G7\Light\Wiki\Support\Doc;

use Illuminate\Support\Facades\Log;
use Plugins\G7\Light\Wiki\Support\DocLists;
use Plugins\G7\Light\Wiki\Support\Placeholders\PlaceholderRenderer;
use Plugins\G7\Light\Wiki\Support\TitleNormalizer;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;
use Plugins\G7\Light\Wiki\Support\WikiCategory;
use Plugins\G7\Light\Wiki\Support\WikiDocQuery;
use Plugins\G7\Light\Wiki\Support\WikiHtml;
use Plugins\G7\Light\Wiki\Support\WikiLabels;
use Plugins\G7\Light\Wiki\Support\WikiMarkupParser;
use Plugins\G7\Light\Wiki\Support\WikiRefQuery;
use Plugins\G7\Light\Wiki\Support\WikiUrl;

/**
 * 본문 치환과 자동 영역이 쓸 자료를 모으는 곳 — **조회가 일어나는 유일한 자리**.
 *
 * 전에는 미들웨어의 `context()` 메서드였다. 102줄로 이 플러그인에서 가장 긴 메서드였고,
 * 미들웨어가 "위키 게시판인가" 만 보고 넘기는 얇은 층이 되지 못한 주된 까닭이었다.
 *
 * ## 조회 횟수 (옮기면서 바뀌지 않았다)
 *
 * 자동 영역이 몇 덩이든, 자리표시가 몇 개든 **종류마다 1회**다. 순서도 전과 같다.
 *
 * | # | 조회 | 언제 |
 * |---|---|---|
 * | 1 | 제목 일괄 조회 | 링크 대상이나 분류가 하나라도 있을 때 — 링크 대상과 분류 문서 제목을 **함께** 묻는다 |
 * | 2 | 별칭 해석 | 제목으로 못 찾은 이름이 남았을 때 |
 * | — | 가려진 별칭 경고 | 제목으로 찾은 이름이 있을 때 (기록만 남긴다) |
 * | 3 | 역링크 | 읽기 권한이 있고 글·제목이 있을 때 |
 * | 4 | 분류 소속 | 이 문서가 분류 문서이거나 `[[#분류\|…]]` 가 있을 때 (분류가 몇 개든 1회) |
 * | + | 자리표시 목록 | 그 자리표시가 실제로 치환될 때 종류·개수별 1회 ({@see DocLists}) |
 *
 * 4번 결과는 **자동 영역과 자리표시가 함께 쓴다** — 분류 문서 자신의 소속 목록과
 * `[[#분류|…]]` 의 목록이 같은 한 번의 조회에서 나온다.
 *
 * 이 문서 **자신**의 분류·별칭·사건은 조회하지 않는다 — 지금 그리고 있는 본문에 적혀 있다.
 */
final class DocContext
{
    /**
     * @param  array<string, array{post_id: int, title: string}>  $found  정규화 제목 → 문서
     * @param  array<string, array{post_id: int, title: string}>  $alias  정규화 별칭 → 문서
     * @param  array<string, mixed>  $footer  자동 영역 조립기에 넘길 조각들
     */
    private function __construct(
        public readonly array $found,
        public readonly array $alias,
        public readonly array $footer,
        public readonly ?PlaceholderRenderer $renderer,
    ) {}

    public static function gather(DocTarget $target, TokenSet $tokens, DocGate $gate, WikiLabels $labels): self
    {
        $ownCategories = $tokens->targetsOf(WikiMarkupParser::KIND_CATEGORY);
        $ownAliases = $tokens->targetsOf(WikiMarkupParser::KIND_ALIAS);

        // 이 문서가 분류 문서라면 자기 소속 목록도 필요하다.
        $isCategoryDoc = WikiCategory::isCategoryTitle($target->titleNorm);

        // ── 조회 1: 제목. 링크 대상과 분류 문서 제목을 **함께** 묻는다.
        $normalizedByTarget = [];

        foreach ($tokens->targetsOf(WikiMarkupParser::KIND_LINK) as $name) {
            $normalizedByTarget[$name] = TitleNormalizer::normalize($name);
        }

        $categoryTitles = [];

        foreach ($ownCategories as $name) {
            $categoryTitles[$name] = TitleNormalizer::normalize(WikiCategory::title($name));
        }

        $ask = array_values(array_unique(array_merge(
            array_values($normalizedByTarget),
            array_values($categoryTitles),
        )));

        $found = $ask === [] ? [] : WikiDocQuery::resolve($target->boardId, $ask);

        // ── 조회 2: 별칭. 제목으로 못 찾은 이름만 묻는다.
        $unresolved = array_values(array_unique(array_filter(
            array_values($normalizedByTarget),
            static fn (string $name): bool => ! isset($found[$name])
        )));

        $alias = $unresolved === [] ? [] : WikiRefQuery::aliasOwners($target->boardId, $unresolved);

        self::warnShadowedAliases($target->boardId, $normalizedByTarget, $found);

        // ── 조회 3: 역링크.
        $backlinks = self::backlinks($target, $gate, $ownAliases);

        // ── 조회 4: 분류 소속. 자동 영역과 자리표시가 함께 쓴다.
        $members = self::members($target, $gate, $tokens, $isCategoryDoc);

        $footer = [
            'category_members' => $isCategoryDoc
                ? ($members[WikiCategory::nameOf($target->titleNorm)] ?? ['items' => [], 'more' => 0])
                : null,
            'aliases' => $ownAliases,
            'backlinks' => $backlinks,
            'categories' => array_map(
                static fn (string $name): string => self::categoryLink(
                    $target,
                    $gate,
                    $name,
                    $categoryTitles[$name] ?? '',
                    $found,
                ),
                $ownCategories,
            ),
        ];

        return new self(
            $found,
            $alias,
            $footer,
            $tokens->hasPlaceholder() ? self::renderer($target, $gate, $labels, $members) : null,
        );
    }

    /**
     * 분류 소속 조회 — 분류 문서 자신의 것과 `[[#분류|…]]` 이 가리키는 것을 한 번에 묻는다.
     *
     * @return array<string, array{items: list<array{post_id: int, title: string}>, more: int}>
     */
    private static function members(DocTarget $target, DocGate $gate, TokenSet $tokens, bool $isCategoryDoc): array
    {
        $want = [];

        if ($isCategoryDoc) {
            $want[] = WikiCategory::nameOf($target->titleNorm);
        }

        foreach ($tokens->placeholderArguments(WikiMarkupParser::PLACEHOLDER_CATEGORY) as $name) {
            $want[] = TitleNormalizer::normalize($name);
        }

        $want = array_values(array_unique(array_filter(
            $want,
            static fn (string $name): bool => $name !== ''
        )));

        return ($gate->canRead && $want !== []) ? WikiRefQuery::categoryMembers($target->boardId, $want) : [];
    }

    /**
     * 자리표시 디스패처 — 목록 공급자를 달아 만든다.
     *
     * 랜덤 대상은 **치환 시점에** 고른다. 플러그인 주소로 보내 서버가 302 로 고르게 하면
     * 브라우저 전체 이동에 토큰이 실리지 않아 로그인한 사람도 비회원으로 보인다. 이 응답은
     * 토큰이 실린 요청의 결과라 요청자 기준으로 고를 수 있다.
     *
     * @param  array<string, array{items: list<array{post_id: int, title: string}>, more: int}>  $members
     */
    private static function renderer(DocTarget $target, DocGate $gate, WikiLabels $labels, array $members): PlaceholderRenderer
    {
        // 후보에서는 대문 글과 지금 그리는 문서 자신을 뺀다.
        $exclude = [(int) (WikiBoardSettings::frontPostId($target->boardId) ?? 0), $target->postId];

        return PlaceholderRenderer::forDoc(
            $target->slug,
            $gate->canRead,
            new DocLists($target->boardId, $exclude, $members),
            $labels,
        );
    }

    /**
     * 역링크 — 이 문서의 제목과 별칭을 모두 대상으로 본다(별칭으로 건 링크도 본 문서의 역링크다).
     *
     * @param  list<string>  $ownAliases
     * @return array{items: list<array{post_id: int, title: string}>, more: int}|null
     */
    private static function backlinks(DocTarget $target, DocGate $gate, array $ownAliases): ?array
    {
        if (! $gate->canRead || $target->postId < 1 || $target->titleNorm === '') {
            return null;
        }

        $names = array_merge(
            [$target->titleNorm],
            array_map(static fn (string $alias): string => TitleNormalizer::normalize($alias), $ownAliases),
        );

        return WikiRefQuery::backlinks($target->boardId, $names, $target->postId);
    }

    /**
     * 분류 줄에 들어갈 링크 하나 — 그 분류 문서가 있으면 파란 링크, 없으면 빨간 링크 규칙.
     *
     * @param  array<string, array{post_id: int, title: string}>  $found
     */
    private static function categoryLink(
        DocTarget $target,
        DocGate $gate,
        string $name,
        string $normalizedTitle,
        array $found
    ): string {
        if ($normalizedTitle !== '' && isset($found[$normalizedTitle])) {
            return WikiHtml::link(WikiUrl::post($target->slug, $found[$normalizedTitle]['post_id']), $name);
        }

        return $gate->canWrite
            ? WikiHtml::newLink(WikiUrl::newDoc($target->boardId, WikiCategory::title($name)), $name)
            : WikiHtml::newText($name);
    }

    /**
     * 별칭이 다른 문서의 실제 제목에 가려졌으면 경고를 남긴다.
     *
     * 실제 제목이 이기고 그 별칭은 무효다. 조용히 무시하면 "왜 내 별칭으로 연결되지 않지" 를
     * 알 길이 없어 기록만 남긴다. 제목·별칭 **글자는 적지 않는다**.
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
}
