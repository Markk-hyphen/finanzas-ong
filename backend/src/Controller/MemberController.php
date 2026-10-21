<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class MemberController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

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

    #[Route('/me', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $body = json_decode($request->getContent(), true) ?? [];

        // Changing the login credentials requires the current password, so a
        // leaked token alone is not enough to take over the account.
        if (array_key_exists('email', $body) || array_key_exists('password', $body)) {
            $current = (string) ($body['currentPassword'] ?? '');
            if ($current === '' || !$this->hasher->isPasswordValid($member, $current)) {
                return new JsonResponse(['error' => 'currentPassword is missing or incorrect.'], 403);
            }
        }

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return new JsonResponse(['error' => 'name must not be empty.'], 400);
            }
            $member->setName($name);
        }

        if (array_key_exists('email', $body)) {
            $email = trim((string) $body['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse(['error' => 'email is invalid.'], 400);
            }

            $existing = $this->em->createQuery(
                'SELECT m FROM App\Entity\Member m WHERE m.email = :email AND m.id != :id'
            )
            ->setParameter('email', $email)
            ->setParameter('id', $member->getId())
            ->getOneOrNullResult();

            if ($existing !== null) {
                return new JsonResponse(['error' => 'email is already in use.'], 409);
            }

            $member->setEmail($email);
        }

        if (array_key_exists('password', $body)) {
            $password = (string) $body['password'];
            if (strlen($password) < 6) {
                return new JsonResponse(['error' => 'password must be at least 6 characters.'], 400);
            }
            $member->setPassword($this->hasher->hashPassword($member, $password));
        }

        $this->em->flush();

        return new JsonResponse([
            'id'    => $member->getId(),
            'name'  => $member->getName(),
            'email' => $member->getEmail(),
        ]);
    }

    #[Route('/members', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $body     = json_decode($request->getContent(), true) ?? [];
        $name     = trim((string) ($body['name'] ?? ''));
        $email    = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($name === '' || $email === '' || $password === '') {
            return new JsonResponse(['error' => 'name, email and password are required.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => 'email is invalid.'], 400);
        }

        if (strlen($password) < 6) {
            return new JsonResponse(['error' => 'password must be at least 6 characters.'], 400);
        }

        $existing = $this->em->getRepository(Member::class)->findOneBy(['email' => $email]);
        if ($existing !== null) {
            return new JsonResponse(['error' => 'A member with this email already exists.'], 409);
        }

        $member = new Member($name, $email);
        $member->setPassword($this->hasher->hashPassword($member, $password));

        if (($body['admin'] ?? false) === true) {
            $member->setRoles(['ROLE_ADMIN']);
        }

        $this->em->persist($member);
        $this->em->flush();

        return new JsonResponse([
            'id'    => $member->getId(),
            'name'  => $member->getName(),
            'email' => $member->getEmail(),
            'roles' => $member->getRoles(),
        ], 201);
    }

    #[Route('/members/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $member = $this->em->find(Member::class, $id);
        if (!$member) {
            return new JsonResponse(['error' => 'Member not found.'], 404);
        }

        $body = json_decode($request->getContent(), true) ?? [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return new JsonResponse(['error' => 'name must not be empty.'], 400);
            }
            $member->setName($name);
        }

        if (array_key_exists('email', $body)) {
            $email = trim((string) $body['email']);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse(['error' => 'email is invalid.'], 400);
            }

            $existing = $this->em->createQuery(
                'SELECT m FROM App\Entity\Member m WHERE m.email = :email AND m.id != :id'
            )
            ->setParameter('email', $email)
            ->setParameter('id', $member->getId())
            ->getOneOrNullResult();

            if ($existing !== null) {
                return new JsonResponse(['error' => 'email is already in use.'], 409);
            }

            $member->setEmail($email);
        }

        if (array_key_exists('active', $body)) {
            $member->setActive((bool) $body['active']);
        }

        $this->em->flush();

        return new JsonResponse([
            'id'     => $member->getId(),
            'name'   => $member->getName(),
            'email'  => $member->getEmail(),
            'active' => $member->isActive(),
            'roles'  => $member->getRoles(),
        ]);
    }

    #[Route('/members/{id}/roles', methods: ['PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function setRoles(int $id, Request $request): JsonResponse
    {
        /** @var Member $currentAdmin */
        $currentAdmin = $this->getUser();

        $member = $this->em->find(Member::class, $id);
        if (!$member) {
            return new JsonResponse(['error' => 'Member not found.'], 404);
        }

        $body  = json_decode($request->getContent(), true) ?? [];
        $admin = $body['admin'] ?? null;

        if (!is_bool($admin)) {
            return new JsonResponse(['error' => 'admin must be a boolean.'], 400);
        }

        if ($admin === false && $member->getId() === $currentAdmin->getId()) {
            return new JsonResponse(['error' => 'No podés revocarte tu propio rol de admin.'], 422);
        }

        $member->setRoles($admin ? ['ROLE_ADMIN'] : []);
        $this->em->flush();

        return new JsonResponse(['id' => $member->getId(), 'roles' => $member->getRoles()]);
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
