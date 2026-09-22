<?php

namespace Plugins\G7\Light\Wiki\Support;

use Plugins\G7\Light\Wiki\Models\WikiRef;

/**
 * 본문 HTML 에서 표기를 뽑아 `light_wiki_refs` 에 넣을 줄로 바꾸는 순수 클래스
 * (DB·HTTP·설정 접근 없음).
 *
 * ## 표시 시점과 같은 눈으로 본다
 *
 * 뽑는 데 쓰는 것은 {@see HtmlLinkRewriter} 와 {@see WikiMarkupParser} 로, 화면에서 치환할 때
 * 쓰는 것과 **같다**. `<a>`·`<code>`·`<pre>` 안을 건너뛰는 것도 같다. 추출과 치환이 서로 다른
 * 규칙을 쓰면 "역링크에는 있는데 본문에는 링크가 없는" 어긋남이 생긴다.
 *
 * ## 뽑지 않는 것
 *
 * - `content_mode` 가 `html` 이 아닌 글. 그런 글은 화면에서도 치환하지 않는다(본문이 통째로
 *   평문으로 그려진다). 추출만 해 두면 역링크에 "누르면 링크가 없는 문서" 가 실린다.
 * - 자리표시(`[[#…]]`) — 그 자리에서 목록을 그릴 뿐, 무언가를 가리키는 표기가 아니다.
 * - 형식이 틀린 연표 키 — 사건으로 등록하지 않는다(본문 표기도 원문 그대로 남는다).
 */
final class RefExtractor
{
    /** 글 하나에서 뽑을 수 있는 최대 표기 수 — 한 글이 표를 통째로 채우지 못하게 한다 */
    public const MAX_ROWS = 500;

    /** `label`(사건 설명) 컬럼 길이 */
    private const LABEL_MAX = 300;

    /**
     * 본문에서 표기를 뽑습니다.
     *
     * `seq` 는 본문 등장 순서(0부터)이고, 종류를 가리지 않고 하나씩 올라간다 — 같은 문서
     * 안에서 사건들의 선후를 그대로 보존하려면 종류별로 다시 세면 안 된다.
     *
     * @param  string  $content  본문 HTML
     * @param  string  $contentMode  `content_mode` 값
     * @return list<array{kind: string, target: string, target_norm: string, sort_key: ?string, label: ?string, seq: int}>
     */
    public static function extract(string $content, string $contentMode): array
    {
        if ($contentMode !== 'html' || $content === '' || ! str_contains($content, '[[')) {
            return [];
        }

        $rows = [];
        $seq = 0;

        foreach ((new HtmlLinkRewriter($content))->tokens() as $token) {
            if ($seq >= self::MAX_ROWS) {
                break;
            }

            $row = self::row($token, $seq);

            if ($row === null) {
                continue;
            }

            $rows[] = $row;
            $seq++;
        }

        return $rows;
    }

    /**
     * 토큰 하나를 표의 한 줄로 바꿉니다 (뽑지 않을 토큰이면 `null`).
     *
     * @param  array<string, mixed>  $token
     * @return array{kind: string, target: string, target_norm: string, sort_key: ?string, label: ?string, seq: int}|null
     */
    private static function row(array $token, int $seq): ?array
    {
        $kind = (string) ($token['kind'] ?? '');
        $target = (string) ($token['target'] ?? '');

        if ($target === '') {
            return null;
        }

        return match ($kind) {
            WikiMarkupParser::KIND_LINK => self::named(WikiRef::KIND_LINK, $target, $seq),
            WikiMarkupParser::KIND_CATEGORY => self::named(WikiRef::KIND_CATEGORY, $target, $seq),
            WikiMarkupParser::KIND_ALIAS => self::named(WikiRef::KIND_ALIAS, $target, $seq),
            WikiMarkupParser::KIND_EVENT => self::event($target, $token['label'] ?? null, $seq),
            default => null,
        };
    }

    /**
     * 이름을 가리키는 표기(링크·분류·별칭) 한 줄.
     *
     * 이름의 정규화는 문서 제목과 **같은 규칙**({@see TitleNormalizer})이다. 그래야 링크
     * 대상과 문서 제목이, 분류 이름과 `분류:…` 문서의 제목이 맞물린다.
     *
     * @return array{kind: string, target: string, target_norm: string, sort_key: null, label: null, seq: int}|null
     */
    private static function named(string $kind, string $target, int $seq): ?array
    {
        $normalized = TitleNormalizer::normalize($target);

        if (! TitleNormalizer::isRegistrable($normalized)) {
            return null;
        }

        return [
            'kind' => $kind,
            'target' => mb_substr($target, 0, TitleNormalizer::MAX_LENGTH, 'UTF-8'),
            'target_norm' => $normalized,
            'sort_key' => null,
            'label' => null,
            'seq' => $seq,
        ];
    }

    /**
     * 사건 한 줄.
     *
     * 설명(`|` 뒤)이 없으면 키를 설명으로 쓴다 — 본문에도 키가 글자로 남으므로 목록과 본문이
     * 같은 글자를 보인다.
     *
     * @param  mixed  $label
     * @return array{kind: string, target: string, target_norm: string, sort_key: string, label: string, seq: int}|null
     */
    private static function event(string $key, mixed $label, int $seq): ?array
    {
        $sortKey = EventKey::sortKey($key);

        if ($sortKey === null) {
            return null;
        }

        $text = is_string($label) && $label !== '' ? $label : $key;

        return [
            'kind' => WikiRef::KIND_EVENT,
            'target' => mb_substr($key, 0, TitleNormalizer::MAX_LENGTH, 'UTF-8'),
            // 키는 이미 숫자와 `.` 뿐이라 정규화가 바꿀 것이 없지만, 컬럼의 뜻을 지키려고 같은 값을 넣는다.
            'target_norm' => mb_substr(trim($key), 0, TitleNormalizer::MAX_LENGTH, 'UTF-8'),
            'sort_key' => $sortKey,
            'label' => mb_substr($text, 0, self::LABEL_MAX, 'UTF-8'),
            'seq' => $seq,
        ];
    }
}
