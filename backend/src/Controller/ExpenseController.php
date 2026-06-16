<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\MovementLoader;
use App\Domain\Service\AnomalyDetector;
use App\Entity\Material;
use App\Entity\Member;
use App\Entity\Movement;
use App\Entity\Project;
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
    ) {}

    #[Route('/projects/{id}/expenses', methods: ['POST'])]
    public function create(int $id, Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $this->getUser();

        $project = $this->em->find(Project::class, $id);
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

        $body   = json_decode($request->getContent(), true) ?? [];
        $amount = trim((string) ($body['amount'] ?? ''));

        if ($amount === '' || !is_numeric($amount) || bccomp($amount, '0', 2) <= 0) {
            return new JsonResponse(['error' => 'amount must be a positive number.'], 400);
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
        $expense->setAmount(bcadd($amount, '0', 2));

        if ($material !== null) {
            $expense->setMaterial($material);
        }
        if (!empty($body['quantity'])) {
            $expense->setQuantity((string) $body['quantity']);
        }

        $this->em->persist($expense);
        $this->em->flush();

        return new JsonResponse(['id' => $expense->getId(), 'warning' => $warning], 201);
    }
}
