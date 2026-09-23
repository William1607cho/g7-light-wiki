<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

/**
 * 세트 설치가 만드는 시드 문서 3건 — 순수 정의 (DB·HTTP·설정 접근 없음).
 *
 * ## 순서
 *
 * 모든 문서 → 위키 문법 도움말 → 대문. 대문이 앞의 둘을 `[[…]]` 로 가리키므로 마지막에
 * 만든다. 링크 색(있음·없음)은 그릴 때 정해져 순서가 틀려도 결과는 같지만, 대문이 처음
 * 그려지는 순간 대상 문서가 이미 있으면 봇 캐시에 빨간 링크가 박힐 틈이 없다.
 *
 * ## 본문 규칙
 *
 * - `content_mode = html`. 평문 모드면 화면이 본문을 통째로 이스케이프하고 표기도 뽑지 않는다.
 * - 자리표시는 기존 대문 글들과 같이 `<p>` 한 줄에 둔다(스테이징 글 38·39 와 같은 모양).
 * - 검색창은 템플릿이 대문 위에 그리므로 본문에 넣지 않는다.
 * - `$t:` 모양의 글자를 쓰지 않는다 — 방문자 화면과 봇 SSR 이 본문 안의 그 모양을 번역으로 바꾼다.
 * - 문법 문서는 이번에는 자리만 만든다. 본문은 다음 작업(6-3)에서 채운다.
 */
final class SeedDocuments
{
    public const INDEX = 'index';

    public const SYNTAX = 'syntax';

    public const FRONT = 'front';

    /** 문서 제목 — 대문 본문의 링크와 글자가 같아야 한다 */
    public const TITLES = [
        self::INDEX => '모든 문서',
        self::SYNTAX => '위키 문법 도움말',
        self::FRONT => '대문',
    ];

    /**
     * 만들 순서대로 시드 정의.
     *
     * @return list<array{key: string, title: string, content: string, content_mode: string}>
     */
    public static function all(): array
    {
        $seeds = [];

        foreach (array_keys(self::TITLES) as $key) {
            $seeds[] = [
                'key' => $key,
                'title' => self::TITLES[$key],
                'content' => self::body($key),
                'content_mode' => 'html',
            ];
        }

        return $seeds;
    }

    /**
     * 시드 본문 HTML.
     */
    public static function body(string $key): string
    {
        return match ($key) {
            self::INDEX => '<p>[[#색인]]</p>',
            self::SYNTAX => '<p>이 위키에서 쓰는 표기를 모아 설명하는 문서입니다.</p>'
                .'<p>(설명은 곧 채워집니다.)</p>',
            self::FRONT => '<p>이 위키에 오신 것을 환영합니다.</p>'
                .'<p>[[#둘러보기]]</p>'
                .'<p>[['.self::TITLES[self::INDEX].']] · [['.self::TITLES[self::SYNTAX].']]</p>',
            default => throw new \InvalidArgumentException("알 수 없는 시드: {$key}"),
        };
    }
}
