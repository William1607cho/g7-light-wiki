<?php

namespace Plugins\G7\Light\Wiki\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\G7\Light\Wiki\Support\Doc\RandomDrawCache;

/**
 * 무작위 목록 쿨다운의 **순수한 부분** — 키 조립과 "되쓸 수 있는 값인가" 판정.
 *
 * 실제 저장·만료는 코어 캐시가 하므로 여기서 시험하지 않는다(DB·프레임워크를 타지 않는
 * 시험만 둔다는 이 폴더의 방침). 쿨다운이 실제로 걸리는지는 스테이징 측정에서 잰다 —
 * 3초 안 연속 두 호출의 응답 해시가 같은지로 본다.
 */
class RandomDrawCacheTest extends TestCase
{
    public function test_쿨다운은_3초다(): void
    {
        $this->assertSame(3, RandomDrawCache::COOLDOWN_SECONDS);
    }

    public function test_회원과_비회원의_키가_다르다(): void
    {
        $member = RandomDrawCache::key(8, 7, '10.0.0.1', 20);
        $guest = RandomDrawCache::key(8, null, '10.0.0.1', 20);

        $this->assertNotSame($member, $guest);
    }

    public function test_게시판마다_키가_갈린다(): void
    {
        $this->assertNotSame(
            RandomDrawCache::key(8, 7, null, 20),
            RandomDrawCache::key(9, 7, null, 20)
        );
        $this->assertNotSame(
            RandomDrawCache::key(8, null, '10.0.0.1', 20),
            RandomDrawCache::key(9, null, '10.0.0.1', 20)
        );
    }

    public function test_뽑는_개수마다_키가_갈린다(): void
    {
        // PC 한 쪽 20건 / 모바일 15건. 키를 나누지 않으면 PC 로 뽑은 직후 연 모바일이
        // 20건짜리 직전 결과를 되써서 뜻 없는 2쪽 페이저가 다시 생긴다.
        $this->assertNotSame(
            RandomDrawCache::key(8, 7, null, 20),
            RandomDrawCache::key(8, 7, null, 15)
        );
        $this->assertNotSame(
            RandomDrawCache::key(8, null, '10.0.0.1', 20),
            RandomDrawCache::key(8, null, '10.0.0.1', 15)
        );
    }

    public function test_요청자마다_키가_갈린다(): void
    {
        $this->assertNotSame(
            RandomDrawCache::key(8, 7, null, 20),
            RandomDrawCache::key(8, 8, null, 20)
        );
        $this->assertNotSame(
            RandomDrawCache::key(8, null, '10.0.0.1', 20),
            RandomDrawCache::key(8, null, '10.0.0.2', 20)
        );
    }

    public function test_같은_요청자는_같은_키를_받는다(): void
    {
        $this->assertSame(
            RandomDrawCache::key(8, null, '10.0.0.1', 20),
            RandomDrawCache::key(8, null, '10.0.0.1', 20)
        );
    }

    public function test_키에_방문자_주소_원문이_남지_않는다(): void
    {
        $key = RandomDrawCache::key(8, null, '203.0.113.9', 20);

        $this->assertStringNotContainsString('203.0.113.9', $key);
        $this->assertStringContainsString(sha1('203.0.113.9'), $key);
    }

    public function test_사용자_id가_0이하면_비회원으로_본다(): void
    {
        // 인증 식별자가 0 이나 음수로 들어오는 경로는 없어야 하지만, 그때 회원 키를
        // 만들면 서로 다른 비회원이 한 칸을 나눠 쓰게 된다.
        $this->assertSame(
            RandomDrawCache::key(8, null, '10.0.0.1', 20),
            RandomDrawCache::key(8, 0, '10.0.0.1', 20)
        );
    }

    public function test_캐시가_비면_새로_뽑는다(): void
    {
        // 만료·미스 둘 다 캐시에서 null 로 온다 → 되쓰지 않는다.
        $this->assertNull(RandomDrawCache::reuse(null));
    }

    public function test_배열이_아닌_값은_버린다(): void
    {
        foreach (['38,52', 38, 1.5, true, new \stdClass] as $cached) {
            $this->assertNull(RandomDrawCache::reuse($cached));
        }
    }

    public function test_직전_결과는_그대로_되쓴다(): void
    {
        $this->assertSame([38, 52, 63], RandomDrawCache::reuse([38, 52, 63]));
    }

    public function test_빈_목록도_직전_결과다(): void
    {
        // 후보가 없는 게시판. null(미스)과 구분해야 쿨다운이 걸린다.
        $this->assertSame([], RandomDrawCache::reuse([]));
    }

    public function test_되쓸_때_정수로_맞추고_쓸모없는_값은_뺀다(): void
    {
        $this->assertSame([38, 52], RandomDrawCache::reuse(['38', 52]));
        $this->assertSame([38], RandomDrawCache::reuse([38, 0, -1, null, 'x', []]));
    }

    public function test_되쓴_목록의_키는_0부터_이어진다(): void
    {
        // 중간이 걸러져 빈 자리가 생기면 JSON 이 배열이 아니라 객체로 나간다.
        $this->assertSame([0, 1], array_keys(RandomDrawCache::reuse([38, 'x', 52])));
    }
}
