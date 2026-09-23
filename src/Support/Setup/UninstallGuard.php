<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

/**
 * 제거(uninstall)를 막을지 — 순수 판정 (DB·HTTP·설정 접근 없음).
 *
 * 세트 설치로 마련한 위키 게시판이 하나라도 남아 있으면 제거를 거부한다. 게시판·글은 이
 * 플러그인이 어떤 경우에도 지우지 않는다 — 운영자가 설정 화면에서 해제한 뒤 제거한다.
 * 수동으로 등록한 위키 게시판은 관리 대상이 아니므로 제거를 막지 않는다.
 *
 * 표·설정 삭제는 코어가 `--delete-data` 일 때 한다. `uninstall()` 은 트랜잭션 안에서
 * 불리므로 거기서 DROP 하면 암시적 커밋이 난다 — 그래서 여기서는 판정만 한다.
 */
final class UninstallGuard
{
    /**
     * 거부 문구 (허용이면 null).
     *
     * @param  int  $managedBoards  실제로 있는 관리 게시판 수
     * @param  callable(int): string  $message  개수를 받아 번역된 문구를 돌려준다
     */
    public static function refusal(int $managedBoards, callable $message): ?string
    {
        return $managedBoards > 0 ? $message($managedBoards) : null;
    }
}
