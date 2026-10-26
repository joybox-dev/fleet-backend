<?php

namespace Tests\Concerns;

use App\Models\Employee;
use App\Models\OperationalAdvance;

/**
 * Every expense, fine paid and maintenance approved names the custody it was paid from (owner,
 * 2026-10-26). Tests about something else pay from one large custody, held by an administrative
 * employee and handed over long before anything they record.
 */
trait PaysFromCustody
{
    private ?int $custodyId = null;

    protected function custody(int $companyId, float $amount = 100000): int
    {
        if ($this->custodyId) {
            return $this->custodyId;
        }

        $holder = Employee::withoutGlobalScopes()->forceCreate([
            'company_id' => $companyId,
            'name' => 'Custody holder',
            'date_of_joining' => '2020-01-01',
            'pay_type' => 'fixed',
            'official_salary' => 0,
            'actual_salary' => 0,
            'role_category' => 'admin',
        ]);

        return $this->custodyId = OperationalAdvance::withoutGlobalScopes()->forceCreate([
            'company_id' => $companyId,
            'employee_id' => $holder->id,
            'amount' => $amount,
            'date' => '2020-01-01',
            'reason' => 'عهدة الاختبار',
            'status' => 'active',
        ])->id;
    }
}
