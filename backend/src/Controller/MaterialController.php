<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Material;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class MaterialController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

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
