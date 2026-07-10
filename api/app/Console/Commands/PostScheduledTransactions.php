<?php

namespace App\Console\Commands;

use App\Models\ScheduledTransaction;
use App\Services\EnterScheduledTransaction;
use Illuminate\Console\Command;

class PostScheduledTransactions extends Command
{
    protected $signature = 'budgie:post-scheduled';

    protected $description = 'Post every scheduled transaction that has come due (runs daily)';

    public function handle(EnterScheduledTransaction $enter): int
    {
        $posted = 0;

        // Re-query each pass: entering advances next_date (or retires one-offs),
        // and a schedule that fell far behind may be due several times over.
        for ($pass = 0; $pass < 100; $pass++) {
            $due = ScheduledTransaction::query()
                ->whereDate('next_date', '<=', now()->toDateString())
                ->get();

            if ($due->isEmpty()) {
                break;
            }

            foreach ($due as $scheduled) {
                $enter($scheduled);
                $posted++;
            }
        }

        $this->info("Posted $posted scheduled transaction(s).");

        return self::SUCCESS;
    }
}
