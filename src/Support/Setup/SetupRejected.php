<?php

namespace Plugins\G7\Light\Wiki\Support\Setup;

/**
 * 세트 설치·위키화·해제를 거부할 때 던진다. 트랜잭션 안에서 던지면 그때까지의 쓰기가 되돌아간다.
 *
 * `reason` 은 번역 키 `g7-light-wiki::messages.setup.<reason>` 의 끝부분이다.
 */
final class SetupRejected extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        public readonly array $params = [],
    ) {
        parent::__construct("wiki setup rejected: {$reason}");
    }
}
