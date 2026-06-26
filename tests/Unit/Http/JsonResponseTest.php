<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\JsonResponse;
use PHPUnit\Framework\TestCase;

final class JsonResponseTest extends TestCase
{
    public function testOkHoldsDataAndStatus(): void
    {
        $r = JsonResponse::ok(['a' => 1]);
        self::assertSame(['a' => 1], $r->data);
        self::assertSame(200, $r->status);
    }

    public function testErrorEnvelope(): void
    {
        $r = JsonResponse::error('boom', 422);
        self::assertSame(['ok' => false, 'error' => 'boom'], $r->data);
        self::assertSame(422, $r->status);
    }

    public function testSendEmitsJsonWithUnescapedFlags(): void
    {
        ob_start();
        JsonResponse::ok(['url' => 'a/b', 'accent' => 'éç'])->send();
        $out = (string) ob_get_clean();

        // UNESCAPED_SLASHES + UNESCAPED_UNICODE
        self::assertSame('{"url":"a/b","accent":"éç"}', $out);
    }
}
