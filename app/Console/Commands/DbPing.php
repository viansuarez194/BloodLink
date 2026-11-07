<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DbPing extends Command
{
    protected $signature = 'bloodlink:db-ping {--times=6 : How many test queries to run}';

    protected $description = 'Measure how long one database query takes (shows if the database is too far away)';

    public function handle(): int
    {
        $name = config('database.default');
        $host = config("database.connections.{$name}.host") ?: '(from DB_URL)';

        $this->line("Database: {$name} @ {$host}");
        $this->line('Sessions: ' . config('session.driver') . ' | Cache: ' . config('cache.default') . ' | Queue: ' . config('queue.default'));
        $this->newLine();

        // 1) Opening the connection (TCP + SSL + login)
        $started = microtime(true);
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Could not connect: ' . $e->getMessage());
            return self::FAILURE;
        }
        $connectMs = (microtime(true) - $started) * 1000;

        // 2) Simple queries on the open connection
        $times = max(2, (int) $this->option('times'));
        $rows  = [];

        for ($i = 1; $i <= $times; $i++) {
            $t = microtime(true);
            DB::select('select 1');
            $rows[] = [$i, round((microtime(true) - $t) * 1000) . ' ms'];
        }

        // The first query can include a cold start, so average the rest.
        $samples = array_map(fn ($r) => (int) $r[1], array_slice($rows, 1));
        $average = (int) round(array_sum($samples) / max(1, count($samples)));

        $this->table(['Query', 'Time'], $rows);
        $this->line('Opening the connection: ' . round($connectMs) . ' ms (happens on every page load)');
        $this->line("Average per query:       {$average} ms");
        $this->line('A typical page makes 8-15 queries, so expect about ' . round($average * 8 / 1000, 1) . ' to ' . round($average * 15 / 1000, 1) . ' seconds just waiting for the database.');
        $this->newLine();

        if ($average < 30) {
            $this->info('Excellent. The database is close. Slowness is coming from somewhere else.');
        } elseif ($average < 100) {
            $this->info('Good enough for development.');
        } else {
            $this->warn('Slow. The database server is far from you. Use a region closer to the Philippines (Singapore) or a local database.');
        }

        if (in_array(config('session.driver'), ['database'], true) && $average >= 50) {
            $this->warn('SESSION_DRIVER=database adds 2 slow queries to every page. Set SESSION_DRIVER=file in .env.');
        }

        if (config('cache.default') === 'database' && $average >= 50) {
            $this->warn('CACHE_STORE=database is also slow here. Set CACHE_STORE=file in .env.');
        }

        return self::SUCCESS;
    }
}
