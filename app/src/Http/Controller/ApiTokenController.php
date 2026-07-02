<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\ValidationException;
use App\Repository\ApiTokenRepository;

/**
 * Gestion des jetons API de l'utilisateur connecté. Ces actions ne sont
 * accessibles qu'en session (navigateur) — jamais via un jeton Bearer
 * (un jeton ne doit pas pouvoir en créer d'autres) : cf. câblage api.php.
 */
final class ApiTokenController
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly int $userId,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return JsonResponse::ok(['tokens' => $this->tokens->listForUser($this->userId)]);
    }

    /**
     * Crée un jeton. Le secret n'est retourné QU'ICI, une seule fois.
     */
    public function create(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new ValidationException('name requis (120 caractères max)');
        }

        $created = $this->tokens->create($this->userId, $name);

        return JsonResponse::ok([
            'ok'     => true,
            'id'     => $created['id'],
            'prefix' => $created['prefix'],
            'token'  => $created['token'],
            'notice' => 'Conservez ce jeton : il ne sera plus jamais affiché.',
        ]);
    }

    public function revoke(Request $request): JsonResponse
    {
        $id = filter_var($request->input('id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ValidationException('id invalide');
        }

        if ($this->tokens->revoke($id, $this->userId) === false) {
            throw new ValidationException('Jeton introuvable ou déjà révoqué');
        }

        return JsonResponse::ok(['ok' => true]);
    }
}
