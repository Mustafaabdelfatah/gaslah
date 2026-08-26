<?php

namespace App\Models;

/**
 * Per-organization bank reconciliation state: which journal lines are cleared and the
 * entered statement balance.
 */
class BankReconciliation extends BaseModel
{
    protected $fillable = [
        'organization_id',
        'cleared_line_ids',
        'statement_balance',
    ];

    protected $casts = [
        'cleared_line_ids' => 'array',
        'statement_balance' => 'decimal:2',
    ];
}
