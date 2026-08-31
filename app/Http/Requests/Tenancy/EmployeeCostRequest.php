<?php

namespace App\Http\Requests\Tenancy;

/**
 * Declare a staff member's monthly salary. Nothing is paid from this and no journal
 * entry comes out of it — it is the figure their output is weighed against.
 */
class EmployeeCostRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'monthly_salary' => ['required', 'numeric', 'min:0', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function monthlySalary(): float
    {
        return (float) $this->input('monthly_salary');
    }

    public function note(): ?string
    {
        return $this->input('note');
    }
}
