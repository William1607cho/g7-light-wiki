<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

/**
 * 새 위키 게시판의 생성 데이터를 조립하는 순수 클래스 (DB·HTTP·설정 접근 없음).
 *
 * ## 왜 모듈 기본값을 먼저 까는가
 *
 * 관리자 화면에서 게시판을 만들면 요청 검증기가 모듈 설정 `basic_defaults` 로 빈 칸을
 * 채운다. 서비스(`BoardService::createBoard`)를 바로 부르면 그 단계가 없어 **DB 기본값**이
 * 들어가는데, 둘이 다르다(스테이징 실측: `show_view_count` 0↔true, `max_content_length`
 * 10000↔100000 등). 그러면 긴 문서를 에디터로 다시 저장할 때 422 가 난다. 그래서 관리자
 * 화면과 같이 `basic_defaults` 의 **스칼라 값만** 깔고(배열 값 — 기본 권한·금지어 등 — 은
 * 모듈이 스스로 처리한다) 그 위에 위키 값을 덮는다.
 *
 * ## 게시판 관리자
 *
 * `board_manager_ids` 를 비우면 관리자 0명 게시판이 되고, 나중에 관리자 화면에서 그
 * 게시판 설정을 저장할 때 "관리자 1명 이상" 규칙에 걸려 422 가 난다. 설정을 저장한
 * 관리자를 넣는다 — 관리자 화면 생성 폼의 기본값과 같다.
 */
final class NewBoardData
{
    /**
     * 빈 게시판을 위키로 바꿀 때 보내는 설정 — 세 칸.
     *
     * 댓글(`use_comment`)은 보내지 않는다. 위키화는 이미 있는 게시판이라, 운영자가 켜 둔 댓글을
     * 위키로 바꾸면서 끄면 뜻밖의 변경이 된다.
     */
    public const CONVERT_SETTINGS = [
        'show_view_count' => false,
        'use_reply' => false,
        'notify_admin_on_post' => false,
    ];

    /** 새 위키 게시판에 고정으로 적용하는 설정 — 위키화의 세 칸 + 댓글 끔 */
    public const WIKI_SETTINGS = self::CONVERT_SETTINGS + [
        'use_comment' => false,
    ];

    /** 위키 게시판의 유형 */
    public const BOARD_TYPE = 'basic';

    /**
     * @param  array<string, mixed>  $moduleDefaults  모듈 설정 `basic_defaults`
     * @param  string  $name  게시판 이름 (기본 로캘)
     * @param  string  $slug  게시판 slug (검증을 마친 값)
     * @param  string  $managerUuid  게시판 관리자로 넣을 사용자 uuid
     * @return array<string, mixed> `BoardService::createBoard()` 에 넘길 값
     */
    public static function build(array $moduleDefaults, string $name, string $slug, string $managerUuid): array
    {
        $scalars = array_filter(
            $moduleDefaults,
            static fn ($value): bool => ! is_array($value) && $value !== null,
        );

        return array_merge($scalars, self::WIKI_SETTINGS, [
            'name' => $name,
            'slug' => $slug,
            'type' => self::BOARD_TYPE,
            'is_active' => true,
            'categories' => [],
            'board_manager_ids' => [$managerUuid],
            // 비워 보내면 모듈이 `default_board_permissions` 로 채운다 — 관리자 화면과 같은 기본 권한(공개).
            'permissions' => [],
        ]);
    }
}
