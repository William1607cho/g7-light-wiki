<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

use App\Services\PluginSettingsService;
use Plugins\G7\Light\Wiki\Support\WikiBoardSettings;

/**
 * 세트 설치·해제의 설정 입출력.
 *
 * 값 조립은 {@see SetupSettingsPatch}(순수)가 하고, 여기서는 **읽고 쓰기만** 한다.
 * 저장은 코어 `PluginSettingsService::save()` 로 한다 — 파일 쓰기라 DB 트랜잭션에 묶이지
 * 않으므로, 세트 설치는 이것을 트랜잭션의 **마지막 단계**에서 부르고 실패하면 예외를 던져
 * DB 를 되돌린다.
 */
final class SetupSettings
{
    /**
     * 지금 설정 전체.
     *
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $raw = function_exists('plugin_settings') ? plugin_settings(WikiBoardSettings::IDENTIFIER) : [];

        return is_array($raw) ? $raw : [];
    }

    /**
     * 관리 게시판 줄 목록.
     *
     * @return list<array<string, mixed>>
     */
    public function managed(): array
    {
        return ManagedBoardList::normalize($this->current()[ManagedBoardList::KEY] ?? []);
    }

    /**
     * 세 키를 저장한다. 실패하면 예외 — 호출부의 트랜잭션을 되돌리기 위해서다.
     *
     * @param  array<string, mixed>  $patch  {@see SetupSettingsPatch} 가 만든 값
     *
     * @throws \RuntimeException 저장 실패
     */
    public function save(array $patch): void
    {
        $failureReason = null;

        if (! app(PluginSettingsService::class)->save(WikiBoardSettings::IDENTIFIER, $patch, $failureReason)) {
            throw new \RuntimeException('[g7-light-wiki] 위키 설정 저장 실패: '.($failureReason ?? 'unknown'));
        }
    }
}
