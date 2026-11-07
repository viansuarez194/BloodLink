<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchInventoryAssets extends Command
{
    protected $signature = 'bloodlink:fetch-inventory-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the Blood Inventory page icons exported from Figma into public/images/inventory';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/6d90f247-35ea-4e90-ba16-7e2f053c3ed0';

    /** local file name => remote file name */
    private const ASSETS = [
        'nav-grid-dark.svg'         => '7fc6b.svg',
        'nav-inventory-active.svg'  => '86d91.svg',
        'info-amber.svg'            => '9de89.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/inventory');
        File::ensureDirectoryExists($dir);

        $failed = 0;

        foreach (self::ASSETS as $local => $remote) {
            $path = $dir . DIRECTORY_SEPARATOR . $local;

            if (File::exists($path) && File::size($path) > 0 && ! $this->option('force')) {
                $this->line("skip       {$local}");
                continue;
            }

            $response = Http::timeout(30)->get(self::PREFIX . '/' . $remote);

            if (! $response->successful() || $response->body() === '') {
                $this->error("FAILED     {$local} (HTTP {$response->status()})");
                $failed++;
                continue;
            }

            File::put($path, $response->body());
            $this->info("downloaded {$local}");
        }

        if ($failed > 0) {
            $this->newLine();
            $this->warn("{$failed} file(s) failed. The Figma links expire after about 7 days - ask for refreshed links.");
            return self::FAILURE;
        }

        $this->info('All Blood Inventory assets are ready.');
        return self::SUCCESS;
    }
}
