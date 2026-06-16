<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\Model\Allocation;
use App\Domain\Service\AllocationValidator;
use PHPUnit\Framework\TestCase;

class AllocationValidatorTest extends TestCase
{
    private AllocationValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AllocationValidator();
    }

    public function testValidAllocationPasses(): void
    {
        $balances    = [1 => '-500.00', 2 => '300.00', 3 => '200.00'];
        $allocations = [new Allocation(2, '300.00'), new Allocation(3, '200.00')];

        $this->expectNotToPerformAssertions();
        $this->validator->validate($allocations, $balances, 1, '500.00');
    }

    public function testRejectsWhenSumDoesNotEqualAmount(): void
    {
        $balances    = [1 => '-500.00', 2 => '300.00', 3 => '200.00'];
        $allocations = [new Allocation(2, '200.00'), new Allocation(3, '200.00')]; // sum=400, not 500

        $this->expectException(\DomainException::class);
        $this->validator->validate($allocations, $balances, 1, '500.00');
    }

    public function testRejectsWhenAllocationExceedsReceiverCredit(): void
    {
        $balances    = [1 => '-500.00', 2 => '100.00'];
        $allocations = [new Allocation(2, '200.00')]; // 200 > 100

        $this->expectException(\DomainException::class);
        $this->validator->validate($allocations, $balances, 1, '200.00');
    }

    public function testRejectsWhenAmountExceedsPayerDebt(): void
    {
        $balances    = [1 => '-100.00', 2 => '500.00'];
        $allocations = [new Allocation(2, '200.00')]; // payer debt is only 100

        $this->expectException(\DomainException::class);
        $this->validator->validate($allocations, $balances, 1, '200.00');
    }

    public function testRejectsWhenPayerIsNotADebtor(): void
    {
        $balances    = [1 => '100.00', 2 => '-100.00'];
        $allocations = [new Allocation(2, '50.00')];

        $this->expectException(\DomainException::class);
        $this->validator->validate($allocations, $balances, 1, '50.00');
    }

    public function testRejectsAllocationToNonCreditor(): void
    {
        $balances    = [1 => '-200.00', 2 => '-50.00'];
        $allocations = [new Allocation(2, '200.00')]; // member 2 is a debtor, not creditor

        $this->expectException(\DomainException::class);
        $this->validator->validate($allocations, $balances, 1, '200.00');
    }
}
