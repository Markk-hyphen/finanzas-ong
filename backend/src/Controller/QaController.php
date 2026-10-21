<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

// Manual testing helpers, fully public (no auth required, see security.yaml)
// and enabled only in the QA environments below: login-as mints a JWT for any
// member without a password, members lists ids so a caller with no token yet
// can pick one. Allowlist, not denylist: an unexpected APP_ENV keeps them off.
#[Route('/api/qa')]
class QaController extends AbstractController
{
    private const QA_ENVIRONMENTS = ['dev', 'test', 'staging'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    #[Route('/login-as/{memberId}', methods: ['POST'])]
    public function loginAs(int $memberId): JsonResponse
    {
        $this->denyOutsideQa();

        $member = $this->em->find(Member::class, $memberId);
        if (!$member) {
            return new JsonResponse(['error' => 'Member not found.'], 404);
        }

        return new JsonResponse(['token' => $this->jwtManager->create($member)]);
    }

    #[Route('/members', methods: ['GET'])]
    public function members(): JsonResponse
    {
        $this->denyOutsideQa();

        $members = $this->em->createQuery(
            'SELECT m FROM App\Entity\Member m ORDER BY m.name ASC'
        )->getResult();

        return new JsonResponse(array_map(fn(Member $m) => [
            'id'       => $m->getId(),
            'name'     => $m->getName(),
            'username' => $m->getUsername(),
        ], $members));
    }

    private function denyOutsideQa(): void
    {
        if (!in_array($this->getParameter('kernel.environment'), self::QA_ENVIRONMENTS, true)) {
            throw $this->createNotFoundException();
        }
    }
}
