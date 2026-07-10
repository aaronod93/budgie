<?php

namespace App\Services;

use App\Models\ScheduledTransaction;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EnterScheduledTransaction
{
    public function __construct(private RecordTransaction $recorder) {}

    /**
     * Materialize a scheduled transaction as a real one on its next date, then
     * advance the schedule (or retire a one-off).
     */
    public function __invoke(ScheduledTransaction $scheduled): Transaction
    {
        return DB::transaction(function () use ($scheduled): Transaction {
            $transaction = $this->recorder->create($scheduled->budget, [
                'account_id' => $scheduled->account_id,
                'date' => $scheduled->next_date->toDateString(),
                'amount' => $scheduled->amount,
                'payee_id' => $scheduled->payee_id,
                'category_id' => $scheduled->transfer_account_id ? null : $scheduled->category_id,
                'transfer_account_id' => $scheduled->transfer_account_id,
                'memo' => $scheduled->memo,
            ]);

            if ($scheduled->frequency === 'once') {
                $scheduled->delete();
            } else {
                $scheduled->next_date = self::advance($scheduled->next_date, $scheduled->frequency);
                $scheduled->save();
            }

            return $transaction;
        });
    }

    public static function advance(CarbonImmutable $date, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $date->addWeek(),
            'fortnightly' => $date->addWeeks(2),
            'monthly' => $date->addMonthNoOverflow(),
            'yearly' => $date->addYearNoOverflow(),
        };
    }
}
