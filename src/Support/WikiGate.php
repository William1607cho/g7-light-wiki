<?php

namespace Plugins\G7\Light\Wiki\Support;

use App\Helpers\PermissionHelper;

/**
 * 게시판 권한 판정 — 코어 `PermissionHelper` 와 같은 결론을 낸다.
 *
 * 판정 결과는 요청 단위로 캐시되므로(회원=인스턴스 캐시, 비회원=guest 역할 캐시) 호출
 * 횟수가 질의 수로 이어지지 않는다.
 *
 * ## 어디서 부르는지가 중요하다
 *
 * 이 게이트는 **요청에 토큰이 실려 오는 곳에서만** 쓴다 — 글 상세 응답 가공, 작성 폼 데이터
 * 응답 가공. 브라우저 전체 이동으로 들어오는 주소(`…/new`)에는 SPA 가 붙이는
 * `Authorization` 헤더가 없어 로그인한 사람도 비회원으로 보이므로, 거기서는 권한을 보지
 * 않고 코어의 권한 미들웨어에 맡긴다.
 */
final class WikiGate
{
    /**
     * 글 읽기 권한.
     */
    public static function canRead(string $slug, ?object $user): bool
    {
        return PermissionHelper::check("sirsoft-board.{$slug}.posts.read", $user);
    }

    /**
     * 글쓰기 권한.
     */
    public static function canWrite(string $slug, ?object $user): bool
    {
        return PermissionHelper::check("sirsoft-board.{$slug}.posts.write", $user);
    }
}
