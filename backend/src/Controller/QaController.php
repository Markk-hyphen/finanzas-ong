<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

// Manual testing helper: lets an already-authenticated member get a JWT for
// any other member without knowing their password. Disabled in prod.
#[Route('/api/qa')]
class QaController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    #[Route('/login-as/{memberId}', methods: ['POST'])]
    public function loginAs(int $memberId): JsonResponse
    {
        if ($this->getParameter('kernel.environment') === 'prod') {
            throw $this->createNotFoundException();
        }

        $member = $this->em->find(Member::class, $memberId);
        if (!$member) {
            return new JsonResponse(['error' => 'Member not found.'], 404);
        }

        return new JsonResponse(['token' => $this->jwtManager->create($member)]);
    }
}
