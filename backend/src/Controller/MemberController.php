<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class MemberController extends AbstractController
{
    #[Route('/me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        return new JsonResponse([
            'id'    => $member->getId(),
            'name'  => $member->getName(),
            'email' => $member->getEmail(),
            'roles' => $member->getRoles(),
        ]);
    }
}
