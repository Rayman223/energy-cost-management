<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Routeur minimal : associe (méthode HTTP, action) à un handler, puis dispatche.
 *
 * Comportement d'erreur identique à l'ancien api.php :
 *  - méthode non gérée (ni GET ni POST enregistrée)         -> 405 "Method not allowed"
 *  - action inconnue en GET                                  -> 400 "Unknown action"
 *  - action inconnue en POST                                 -> 400 "Unknown POST action"
 *  - ValidationException levée par un handler                -> 422
 *  - toute autre exception                                   -> 500
 */
final class Router
{
    /** @var array<string, array<string, callable(Request): JsonResponse>> */
    private array $routes = [];

    /** @param callable(Request): JsonResponse $handler */
    public function add(string $method, string $action, callable $handler): void
    {
        $this->routes[$method][$action] = $handler;
    }

    public function dispatch(Request $request): JsonResponse
    {
        $method = $request->method();
        $action = $request->action();

        if (!isset($this->routes[$method])) {
            return JsonResponse::error('Method not allowed', 405);
        }

        $handler = $this->routes[$method][$action] ?? null;
        if ($handler === null) {
            $message = $method === 'POST' ? 'Unknown POST action' : 'Unknown action';

            return JsonResponse::error($message, 400);
        }

        try {
            return $handler($request);
        } catch (ValidationException $e) {
            return JsonResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage(), 500);
        }
    }
}
