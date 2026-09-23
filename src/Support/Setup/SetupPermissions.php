<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

/**
 * 세트 설치 API 가 요구하는 권한 — 순수 클래스.
 *
 * 게시판을 **새로 만들거나** 기존 게시판을 위키로 바꾸는 일은 "플러그인 설정 변경" 이면서
 * "게시판 생성" 이다. 플러그인 설정 권한만 보면 게시판 생성 권한이 없는 사람이 이 API 로
 * 게시판을 만들 수 있게 된다. 그래서 **둘 다** 요구한다.
 *
 * 판정은 코어 `permission` 미들웨어가 한다. `a|b` 는 코어 규칙상 **모두 필요(AND)** 다 —
 * 세 번째 인자로 `false` 를 줄 때만 OR 가 된다. 여기서는 세 번째 인자를 주지 않는다.
 * 새 권한은 만들지 않는다.
 */
final class SetupPermissions
{
    /** 세트 설치·위키화에 모두 필요한 권한 */
    public const SETUP = [
        'core.plugins.update',
        'sirsoft-board.boards.create',
    ];

    /** 상태 조회 */
    public const READ = 'core.plugins.read';

    /** 해제 — 게시판을 만들지 않으므로 플러그인 설정 권한만 */
    public const RELEASE = 'core.plugins.update';

    /**
     * 세트 설치·위키화 라우트 미들웨어.
     */
    public static function setupMiddleware(): string
    {
        return 'permission:admin,'.implode('|', self::SETUP);
    }

    /**
     * 조회 라우트 미들웨어.
     */
    public static function readMiddleware(): string
    {
        return 'permission:admin,'.self::READ;
    }

    /**
     * 해제 라우트 미들웨어.
     */
    public static function releaseMiddleware(): string
    {
        return 'permission:admin,'.self::RELEASE;
    }
}
