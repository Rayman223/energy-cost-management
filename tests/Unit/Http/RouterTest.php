<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Router;
use App\Http\ValidationException;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->add('GET', 'ping', static fn (Request $r): JsonResponse => JsonResponse::ok(['pong' => true]));
        $router->add('POST', 'echo', static fn (Request $r): JsonResponse => JsonResponse::ok(['v' => $r->input('v')]));

        return $router;
    }

    public function testDispatchesRegisteredGetHandler(): void
    {
        $res = $this->router()->dispatch(new Request('GET', ['action' => 'ping'], []));
        self::assertSame(200, $res->status);
        self::assertSame(['pong' => true], $res->data);
    }

    public function testDispatchesRegisteredPostHandler(): void
    {
        $res = $this->router()->dispatch(new Request('POST', ['action' => 'echo'], ['v' => 42]));
        self::assertSame(['v' => 42], $res->data);
    }

    public function testUnknownGetActionReturns400(): void
    {
        $res = $this->router()->dispatch(new Request('GET', ['action' => 'nope'], []));
        self::assertSame(400, $res->status);
        self::assertSame(['ok' => false, 'error' => 'Unknown action'], $res->data);
    }

    public function testUnknownPostActionReturns400WithPostMessage(): void
    {
        $res = $this->router()->dispatch(new Request('POST', ['action' => 'nope'], []));
        self::assertSame(400, $res->status);
        self::assertSame(['ok' => false, 'error' => 'Unknown POST action'], $res->data);
    }

    public function testUnhandledMethodReturns405(): void
    {
        $res = $this->router()->dispatch(new Request('PUT', ['action' => 'ping'], []));
        self::assertSame(405, $res->status);
        self::assertSame(['ok' => false, 'error' => 'Method not allowed'], $res->data);
    }

    public function testValidationExceptionMapsTo422(): void
    {
        $router = new Router();
        $router->add('GET', 'bad', static function (): JsonResponse {
            throw new ValidationException('Invalid year/month');
        });

        $res = $router->dispatch(new Request('GET', ['action' => 'bad'], []));
        self::assertSame(422, $res->status);
        self::assertSame(['ok' => false, 'error' => 'Invalid year/month'], $res->data);
    }

    public function testUncaughtThrowableMapsTo500(): void
    {
        $router = new Router();
        $router->add('GET', 'boom', static function (): JsonResponse {
            throw new \RuntimeException('kaboom');
        });

        $res = $router->dispatch(new Request('GET', ['action' => 'boom'], []));
        self::assertSame(500, $res->status);
        self::assertSame(['ok' => false, 'error' => 'kaboom'], $res->data);
    }
}
