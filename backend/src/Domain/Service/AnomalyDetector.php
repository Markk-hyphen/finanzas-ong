<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Model\MovementRecord;

class AnomalyDetector
{
    private const SCALE      = 2;
    private const MULTIPLIER = '5';

    /**
     * Returns true if amount is suspiciously large (> 5× median of project expenses).
     * Never blocks — caller decides what to do with the warning.
     *
     * @param MovementRecord[] $projectMovements
     */
    public function isAnomalous(string $amount, array $projectMovements): bool
    {
        $amounts = [];
        foreach ($projectMovements as $m) {
            if ($m->type === 'expense' && $m->amount !== null) {
                $amounts[] = $m->amount;
            }
        }

        if (empty($amounts)) {
            return false;
        }

        $median = $this->median($amounts);

        return bccomp($amount, bcmul(self::MULTIPLIER, $median, self::SCALE), self::SCALE) > 0;
    }

    /** @param string[] $values */
    private function median(array $values): string
    {
        usort($values, fn($a, $b) => bccomp($a, $b, self::SCALE));
        $count = count($values);
        $mid   = intdiv($count, 2);

        if ($count % 2 === 0) {
            return bcdiv(bcadd($values[$mid - 1], $values[$mid], self::SCALE), '2', self::SCALE);
        }

        return $values[$mid];
    }
}
