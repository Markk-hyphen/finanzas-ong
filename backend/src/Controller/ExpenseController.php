<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\MovementLoader;
use App\Domain\Service\AnomalyDetector;
use App\Domain\Service\DecimalParser;
use App\Domain\Service\ProjectGuard;
use App\Entity\Material;
use App\Entity\Member;
use App\Entity\Movement;
use App\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ExpenseController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MovementLoader $movementLoader,
        private readonly AnomalyDetector $anomalyDetector,
        private readonly ProjectGuard $projectGuard,
        private readonly DecimalParser $decimals,
    ) {}

    #[Route('/projects/{id}/expenses', methods: ['POST'])]
    public function create(int $id, Request $request): JsonResponse
    {
        // Same per-project lock as payment creation: ledger writes are serialized.
        return $this->em->wrapInTransaction(fn() => $this->persistExpense($id, $request));
    }

    private function persistExpense(int $id, Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $project = $this->em->find(Project::class, $id, LockMode::PESSIMISTIC_WRITE);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        $membership = $this->em->createQuery(
            'SELECT mp FROM App\Entity\MemberProject mp WHERE mp.member = :member AND mp.project = :project'
        )
        ->setParameter('member', $member)
        ->setParameter('project', $project)
        ->getOneOrNullResult();

        if (!$membership) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }

        if (!$this->projectGuard->isActive($project)) {
            return new JsonResponse(
                ['error' => 'El proyecto no está activo: los porcentajes de los socios deben sumar 100% antes de registrar movimientos.'],
                409
            );
        }

        $body   = json_decode($request->getContent(), true) ?? [];
        $amount = $this->decimals->parse($body['amount'] ?? null, 16, 2);

        if ($amount === null || bccomp($amount, '0', 2) <= 0) {
            return new JsonResponse(['error' => 'amount must be a positive number with at most 2 decimals.'], 400);
        }

        $quantity = null;
        if (isset($body['quantity']) && $body['quantity'] !== '') {
            $quantity = $this->decimals->parse($body['quantity'], 15, 3);
            if ($quantity === null) {
                return new JsonResponse(['error' => 'quantity must be a non-negative number with at most 3 decimals.'], 400);
            }
        }

        $material = null;
        if (!empty($body['materialId'])) {
            $material = $this->em->find(Material::class, (int) $body['materialId']);
            if (!$material) {
                return new JsonResponse(['error' => 'Material not found.'], 404);
            }
        }

        $movements = $this->movementLoader->forProject($project);
        $warning   = $this->anomalyDetector->isAnomalous($amount, $movements)
            ? 'El monto es inusualmente alto. Verificá que no haya un cero de más.'
            : null;

        $expense = new Movement($project, Movement::TYPE_EXPENSE, $member);
        $expense->setFromMember($member);
        $expense->setAmount($amount);

        if ($material !== null) {
            $expense->setMaterial($material);
        }
        if ($quantity !== null) {
            $expense->setQuantity($quantity);
        }

        $this->em->persist($expense);
        $this->em->flush();

        return new JsonResponse(['id' => $expense->getId(), 'warning' => $warning], 201);
    }
}
