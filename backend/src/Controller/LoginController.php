<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class LoginController
{
    // The security firewall intercepts this route and returns the JWT token.
    // This method is never actually called on successful auth.
    #[Route('/api/login', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['error' => 'Invalid credentials.'], 401);
    }
}
