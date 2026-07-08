<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Material;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class MaterialController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('/materials', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return new JsonResponse(['error' => 'name must not be empty.'], 400);
        }

        $normalized = Material::normalize($name);
        $existing   = $this->em->getRepository(Material::class)->findOneBy(['normalizedName' => $normalized]);

        if ($existing !== null) {
            return new JsonResponse(['id' => $existing->getId(), 'name' => $existing->getName(), 'created' => false]);
        }

        $material = new Material($name);
        $this->em->persist($material);
        $this->em->flush();

        return new JsonResponse(['id' => $material->getId(), 'name' => $material->getName(), 'created' => true], 201);
    }

    #[Route('/materials/{id}', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rename(int $id, Request $request): JsonResponse
    {
        $material = $this->em->find(Material::class, $id);
        if (!$material) {
            return new JsonResponse(['error' => 'Material not found.'], 404);
        }

        $body = json_decode($request->getContent(), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') {
            return new JsonResponse(['error' => 'name must not be empty.'], 400);
        }

        $normalized = Material::normalize($name);
        $duplicate  = $this->em->createQuery(
            'SELECT m FROM App\Entity\Material m WHERE m.normalizedName = :normalized AND m.id != :id'
        )
        ->setParameter('normalized', $normalized)
        ->setParameter('id', $material->getId())
        ->getOneOrNullResult();

        if ($duplicate !== null) {
            return new JsonResponse(['error' => 'Another material with this name already exists.'], 409);
        }

        $material->setName($name);
        $this->em->flush();

        return new JsonResponse(['id' => $material->getId(), 'name' => $material->getName()]);
    }

    #[Route('/materials', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));

        if (strlen($q) < 2) {
            return new JsonResponse([]);
        }

        $results = $this->em->createQuery(
            'SELECT m FROM App\Entity\Material m WHERE m.normalizedName LIKE :q ORDER BY m.name ASC'
        )
        ->setParameter('q', '%' . Material::normalize($q) . '%')
        ->setMaxResults(20)
        ->getResult();

        return new JsonResponse(array_map(fn(Material $m) => [
            'id'   => $m->getId(),
            'name' => $m->getName(),
        ], $results));
    }
}
