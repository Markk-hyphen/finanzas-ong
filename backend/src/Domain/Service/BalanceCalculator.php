<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\MemberShare;
use App\Domain\Model\MovementRecord;

class BalanceCalculator
{
    private const SCALE     = 2;
    private const RAW_SCALE = 8; // extra precision for obligation calculation

    /**
     * With $includePending, active pending payments count as if confirmed: the
     * "projected" balance used to validate new payments, so debt or credit already
     * covered by an unconfirmed payment cannot be paid twice. Displayed balances
     * keep the default (confirmed only).
     *
     * @param MovementRecord[] $movements  all movements of the project (including cancellations)
     * @param MemberShare[]    $shares
     * @return array<int, string>  memberId => balance (positive = creditor, negative = debtor)
     */
    public function calculate(array $movements, array $shares, bool $includePending = false): array
    {
        $active    = $this->activeMovements($movements);
        $totalCost = $this->totalCost($active);

        $balances = [];
        foreach ($shares as $share) {
            $balances[$share->memberId] = '0.00';
        }

        foreach ($active as $m) {
            if ($m->type === 'expense') {
                $balances[$m->fromMemberId] = bcadd(
                    $balances[$m->fromMemberId] ?? '0.00',
                    $m->amount,
                    self::SCALE,
                );
            }

            if ($m->type === 'payment' && ($m->status === 'confirmed' || $includePending)) {
                $balances[$m->fromMemberId] = bcadd(
                    $balances[$m->fromMemberId] ?? '0.00',
                    $m->amount,
                    self::SCALE,
                );
                $balances[$m->toMemberId] = bcsub(
                    $balances[$m->toMemberId] ?? '0.00',
                    $m->amount,
                    self::SCALE,
                );
            }
        }

        $obligations = $this->distributeObligations($totalCost, $shares);
        foreach ($obligations as $memberId => $obligation) {
            $balances[$memberId] = bcsub($balances[$memberId], $obligation, self::SCALE);
        }

        return $balances;
    }

    /**
     * Distributes totalCost among members proportional to their percentage using
     * largest-remainder rounding so that Σ obligations == totalCost exactly.
     *
     * @param  MemberShare[]        $shares
     * @return array<int, string>   memberId => obligation
     */
    private function distributeObligations(string $totalCost, array $shares): array
    {
        if (bccomp($totalCost, '0', self::SCALE) === 0) {
            $result = [];
            foreach ($shares as $share) {
                $result[$share->memberId] = '0.00';
            }
            return $result;
        }

        $raw   = [];
        $floor = [];
        $floorSum = '0.00';

        foreach ($shares as $share) {
            $r = bcdiv(bcmul($share->percentage, $totalCost, self::RAW_SCALE), '100', self::RAW_SCALE);
            $f = $this->floorToScale($r, self::SCALE);
            $raw[$share->memberId]   = $r;
            $floor[$share->memberId] = $f;
            $floorSum = bcadd($floorSum, $f, self::SCALE);
        }

        $remainderCents = $this->remainderInCents($totalCost, $floorSum);

        if ($remainderCents > 0) {
            $fractionals = [];
            foreach ($shares as $share) {
                $fractionals[$share->memberId] = bcsub($raw[$share->memberId], $floor[$share->memberId], self::RAW_SCALE);
            }
            arsort($fractionals);

            $cent = bcdiv('1', bcpow('10', (string) self::SCALE, 0), self::SCALE);
            foreach (array_keys($fractionals) as $memberId) {
                if ($remainderCents <= 0) {
                    break;
                }
                $floor[$memberId] = bcadd($floor[$memberId], $cent, self::SCALE);
                $remainderCents--;
            }
        }

        return $floor;
    }

    /**
     * Filters out movements cancelled by a cancellation row, and the cancellations themselves.
     *
     * @param  MovementRecord[] $movements
     * @return MovementRecord[]
     */
    private function activeMovements(array $movements): array
    {
        $cancelledIds = [];
        foreach ($movements as $m) {
            if ($m->type === 'cancellation' && $m->cancelsMovementId !== null) {
                $cancelledIds[$m->cancelsMovementId] = true;
            }
        }

        return array_values(array_filter(
            $movements,
            fn(MovementRecord $m) => $m->type !== 'cancellation' && !isset($cancelledIds[$m->id]),
        ));
    }

    /** @param MovementRecord[] $activeMovements */
    private function totalCost(array $activeMovements): string
    {
        $total = '0.00';
        foreach ($activeMovements as $m) {
            if ($m->type === 'expense') {
                $total = bcadd($total, $m->amount, self::SCALE);
            }
        }
        return $total;
    }

    /** @param MovementRecord[] $movements all project movements (including cancellations) */
    public function computeTotalCost(array $movements): string
    {
        return $this->totalCost($this->activeMovements($movements));
    }

    private function floorToScale(string $value, int $scale): string
    {
        if (str_contains($value, '.')) {
            [$int, $dec] = explode('.', $value);
            $dec = substr(str_pad($dec, $scale, '0'), 0, $scale);
            return $int . '.' . $dec;
        }
        return $value . '.' . str_repeat('0', $scale);
    }

    private function remainderInCents(string $total, string $distributed): int
    {
        $factor      = bcpow('10', (string) self::SCALE, 0);
        $totalCents  = (int) bcmul($total, $factor, 0);
        $distCents   = (int) bcmul($distributed, $factor, 0);
        return $totalCents - $distCents;
    }
}
