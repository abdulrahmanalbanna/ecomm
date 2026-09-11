<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateInventoryPartitionsCommand extends Command
{
    protected $signature = 'inventory:create-partitions {--months=6 : Number of months ahead to pre-create partitions}';

    protected $description = 'Pre-creates monthly RANGE partitions for inventory_movements table';

    public function handle(): int
    {
        $monthsAhead = (int) $this->option('months');
        $now = Carbon::now('UTC')->startOfMonth();

        $this->info("Ensuring inventory_movements partitions for the next {$monthsAhead} months...");

        for ($i = 0; $i <= $monthsAhead; $i++) {
            $startDate = $now->copy()->addMonths($i);
            $endDate = $startDate->copy()->addMonth();

            $tableName = sprintf(
                'inventory_movements_%s_%s',
                $startDate->year,
                str_pad((string) $startDate->month, 2, '0', STR_PAD_LEFT)
            );

            $startStr = $startDate->toIso8601String();
            $endStr = $endDate->toIso8601String();

            try {
                DB::statement(sprintf(
                    "CREATE TABLE IF NOT EXISTS %s PARTITION OF inventory_movements FOR VALUES FROM ('%s') TO ('%s')",
                    $tableName,
                    $startStr,
                    $endStr
                ));

                $this->line("  Partition {$tableName} checked/created.");
            } catch (\Throwable $e) {
                $this->error("Failed creating partition {$tableName}: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        $this->info('Inventory movements partition creation completed successfully.');

        return Command::SUCCESS;
    }
}
