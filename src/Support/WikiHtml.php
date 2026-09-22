<?php

namespace Plugins\G7\Light\Wiki\Support;

/**
 * 본문에 끼워 넣을 HTML **조각 하나**를 만드는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * 300줄을 넘겨 영역별로 나눴다. 여기 남은 것은 **낱개 요소와 공통 재료**다.
 *
 * | 클래스 | 맡는 것 |
 * |---|---|
 * | `WikiHtml` | 링크·빨간 링크·빨간 글자·랜덤 링크·사건 글자·빈 목록 안내, 이스케이프, class·style 상수 |
 * | {@see WikiHtml\Lists} | 목록(최근수정·최근작성·랜덤·색인·둘러보기·연표) |
 * | {@see WikiHtml\Footer} | 문서 뒤 자동 영역(감싸개·분류 줄·다른 이름 줄·머리글 있는 목록) |
 *
 * 여기서 만드는 태그·속성은 **방문자 화면(DOMPurify)과 봇 SSR 정제기 양쪽을 통과하는
 * 것들만** 쓴다: `a`·`span`·`ul`·`ol`·`li`·`div`·`h3` 와 `href`·`class`·`target`·`rel`.
 * `form`·`input`·`button` 은 두 정제기가 모두 지우므로 쓰지 않는다.
 *
 * 색은 템플릿 빌드 CSS 에 실제로 들어 있는 유틸리티 클래스로만 준다
 * (`text-red-600`, `dark:text-red-400`). 다크 모드는 `.dark` 조상 클래스 방식이다.
 */
final class WikiHtml
{
    /** 있는 문서 링크 */
    public const CLASS_LINK = 'g7lw-link';

    /** 없는 문서 표시 (빨간 링크·빨간 글자 공통) */
    public const CLASS_NEW = 'g7lw-link-new text-red-600 dark:text-red-400';

    /**
     * 자리표시 머리글(색인 묶음·둘러보기 단·자동 영역)의 강조.
     *
     * 템플릿 빌드 CSS 에 `g7lw-…` 전용 스타일이 없어 class 만으로는 본문 글자와 크기가
     * 같아 보인다(2026-09-21 실브라우저 관찰). 그래서 배치와 마찬가지로 **인라인 style
     * 로만** 준다. `style` 속성은 봇 SSR 정제기와 방문자 화면 DOMPurify 양쪽이 남긴다.
     *
     * 색은 **지정하지 않는다** — 상속을 받아야 다크 모드에서 깨지지 않는다.
     *
     * 크기는 `rem` 이 아니라 **`em`(본문 대비 배율)** 이다. 본문 글자 크기는 슈퍼팩 설정에서
     * 오므로(윌리엄 확인), `rem` 으로 고정하면 본문을 키운 사이트에서 머리글이 본문보다
     * 작아진다. `1.25em` 은 "본문의 1.25배" 라 설정을 따라간다. 여백도 같은 이유로 `em` 이다.
     *
     * 나눠진 `Lists`·`Footer` 도 같은 값을 써야 하므로 공개한다.
     */
    public const LABEL_STYLE = 'font-weight:700;font-size:1.25em;margin:1em 0 .25em';

    /**
     * 있는 문서로 가는 링크.
     */
    public static function link(string $url, string $label): string
    {
        return '<a class="'.self::CLASS_LINK.'" href="'.self::e($url).'">'.self::e($label).'</a>';
    }

    /**
     * 자리표시 머리글을 감싸는 링크 — 지금은 둘러보기의 단 제목이 쓴다.
     *
     * 문서 링크(`g7lw-link`)와 **class 를 나눈다.** 이것은 문서가 아니라 목록 화면으로 가는
     * 길이고, 둘을 같은 class 로 두면 "문서 링크 수" 를 세는 구조 측정이 제목까지 함께 센다.
     */
    public static function sectionLink(string $url, string $label): string
    {
        return '<a class="g7lw-section-link" href="'.self::e($url).'">'.self::e($label).'</a>';
    }

    /**
     * 없는 문서 — 글쓰기 권한이 있는 요청자에게는 작성 화면으로 가는 빨간 링크.
     */
    public static function newLink(string $url, string $label): string
    {
        return '<a class="'.self::CLASS_LINK.' '.self::CLASS_NEW.'" href="'.self::e($url).'">'
            .self::e($label).'</a>';
    }

    /**
     * 없는 문서 — 글쓰기 권한이 없는 요청자에게는 링크 없이 빨간 글자만.
     */
    public static function newText(string $label): string
    {
        return '<span class="'.self::CLASS_NEW.'">'.self::e($label).'</span>';
    }

    /**
     * 랜덤 문서로 가는 링크.
     *
     * 대상은 **치환 시점에** 고른다. 플러그인 주소로 보내 서버가 302 로 고르게 하면,
     * 브라우저 전체 이동에는 Bearer 토큰이 실리지 않아 로그인한 사람도 비회원으로 보인다
     * (비공개 위키에서 401 로 떨어진다). 치환은 토큰이 실린 글 상세 응답 안에서 일어나므로
     * 요청자 기준으로 고를 수 있다.
     *
     * 그래서 결과물은 그냥 문서 링크다 — 새로고침할 때마다 대상이 다시 뽑힌다.
     */
    public static function randomLink(string $slug, int $postId, string $label): string
    {
        return '<a class="g7lw-random '.self::CLASS_LINK.'" href="'
            .self::e(WikiUrl::post($slug, $postId)).'">'.self::e($label).'</a>';
    }

    /**
     * 고를 문서가 없을 때 — 링크 대신 안내 글자.
     */
    public static function randomEmpty(string $label): string
    {
        return '<span class="g7lw-random g7lw-empty">'.self::e($label).'</span>';
    }

    /**
     * 보여 줄 것이 없을 때의 안내 한 줄.
     *
     * 목록 자리에 아무것도 그리지 않으면 사람이 적은 자리표시가 사라진 것처럼 보인다.
     */
    public static function emptyNotice(string $class, string $label): string
    {
        return '<p class="'.$class.' g7lw-empty">'.self::e($label).'</p>';
    }

    /**
     * 본문 안에 남는 사건 글자 — `[[연표:키|설명]]` 이 있던 자리.
     *
     * 키는 **보이지 않는다**. 사람이 읽는 것은 설명이고, 키는 순서를 정하는 값일 뿐이다.
     */
    public static function eventInline(string $text): string
    {
        return '<span class="g7lw-event">'.self::e($text).'</span>';
    }

    /**
     * HTML 이스케이프 — 속성·텍스트 공통.
     *
     * 나눠진 `Lists`·`Footer` 도 같은 규칙을 써야 하므로 공개한다. 태그 문자열을 만드는
     * 곳은 이 셋뿐이고, 그 셋은 모두 이 메서드를 통과시킨다.
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
