<?php

namespace App\Services\Orders;

use App\Models\Branch;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Generates the human-readable order number and its scan barcode.
 *
 * The sequence is the highest number used today for the branch prefix plus one, with
 * an attempt offset so a caller retrying after a unique-index collision jumps clear of
 * the number that raced it.
 */
class OrderNumberService
{
    /**
     * @return array{order_no: string, barcode: string}
     */
    public function generate(Branch $branch, int $attempt = 0): array
    {
        $datePart = Carbon::now()->format('Ymd');
        $prefix = $this->branchPrefix($branch);
        $numberPrefix = "{$prefix}-{$datePart}-";

        $sequence = $this->highestSequenceToday($branch, $numberPrefix) + 1 + $attempt;
        $pad = str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

        $orderNo = "{$numberPrefix}{$pad}";
        $barcode = preg_replace('/[^A-Za-z0-9]/', '', "{$datePart}{$prefix}{$pad}");

        return ['order_no' => $orderNo, 'barcode' => $barcode];
    }

    private function branchPrefix(Branch $branch): string
    {
        $code = preg_replace('/[^A-Za-z0-9]/', '', Str::upper((string) $branch->code));

        return $code !== '' ? $code : Str::upper(substr((string) $branch->getKey(), -4));
    }

    /**
     * Highest sequence already used today for this prefix — read from the max order
     * number, not a row count, so a deleted order never lets a number repeat.
     */
    private function highestSequenceToday(Branch $branch, string $numberPrefix): int
    {
        $latest = Order::query()
            ->where('branch_id', $branch->getKey())
            ->where('order_no', 'like', $numberPrefix.'%')
            ->orderByDesc('order_no')
            ->value('order_no');

        if ($latest === null) {
            return 0;
        }

        return (int) Str::afterLast($latest, '-');
    }
}
