<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class MemberController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

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

    #[Route('/members', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $members = $this->em->createQuery(
            'SELECT m FROM App\Entity\Member m ORDER BY m.name ASC'
        )->getResult();

        return new JsonResponse(array_map(fn(Member $m) => [
            'id'       => $m->getId(),
            'name'     => $m->getName(),
            'username' => $m->getUsername(),
            'email'    => $m->getEmail(),
        ], $members));
    }
}
