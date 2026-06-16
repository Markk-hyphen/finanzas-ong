<?php

declare(strict_types=1);

namespace App\Domain\Strategy;

use App\Domain\Model\Allocation;

class ProportionalToCredit implements AllocationStrategy
{
    private const SCALE     = 2;
    private const REMAINDER_SCALE = 10; // extra precision for remainder comparison

    /**
     * @param array<int, string> $balances
     * @return Allocation[]
     */
    public function allocate(int $payerId, string $amount, array $balances): array
    {
        $creditors = array_filter(
            $balances,
            fn(string $b, int $id) => $id !== $payerId && bccomp($b, '0', self::SCALE) > 0,
            ARRAY_FILTER_USE_BOTH,
        );

        if (empty($creditors)) {
            return [];
        }

        $totalCredit = array_reduce(
            $creditors,
            fn(string $carry, string $b) => bcadd($carry, $b, self::SCALE),
            '0.00',
        );

        // Raw (unrounded) shares and integer floor parts
        $rawShares  = [];
        $floorParts = [];
        $floorSum   = '0.00';

        foreach ($creditors as $memberId => $credit) {
            $raw = bcdiv(bcmul($amount, $credit, self::SCALE + 4), $totalCredit, self::SCALE + 4);
            $rawShares[$memberId]  = $raw;
            $floor                 = $this->floorToScale($raw, self::SCALE);
            $floorParts[$memberId] = $floor;
            $floorSum              = bcadd($floorSum, $floor, self::SCALE);
        }

        // Distribute remainder cents via largest remainder method
        $remainder = $this->remainderInCents($amount, $floorSum);

        if ($remainder > 0) {
            $remainders = [];
            foreach ($creditors as $memberId => $credit) {
                $fractional = bcsub($rawShares[$memberId], $floorParts[$memberId], self::REMAINDER_SCALE);
                $remainders[$memberId] = $fractional;
            }
            arsort($remainders);

            $cent = bcdiv('1', bcpow('10', (string) self::SCALE, 0), self::SCALE);
            foreach (array_keys($remainders) as $memberId) {
                if ($remainder <= 0) {
                    break;
                }
                $floorParts[$memberId] = bcadd($floorParts[$memberId], $cent, self::SCALE);
                $remainder--;
            }
        }

        $allocations = [];
        foreach ($floorParts as $memberId => $part) {
            if (bccomp($part, '0', self::SCALE) > 0) {
                $allocations[] = new Allocation($memberId, $part);
            }
        }

        return $allocations;
    }

    private function floorToScale(string $value, int $scale): string
    {
        // Truncate (floor toward zero) to the given decimal scale
        if (str_contains($value, '.')) {
            [$int, $dec] = explode('.', $value);
            $dec = substr(str_pad($dec, $scale, '0'), 0, $scale);
            return $int . '.' . $dec;
        }
        return $value . '.' . str_repeat('0', $scale);
    }

    /** Returns how many smallest units (cents) are left to distribute */
    private function remainderInCents(string $total, string $distributed): int
    {
        $factor = bcpow('10', (string) self::SCALE, 0);
        $totalCents = (int) bcmul($total, $factor, 0);
        $distCents  = (int) bcmul($distributed, $factor, 0);
        return $totalCents - $distCents;
    }
}
