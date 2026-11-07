<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchFindBloodAssets extends Command
{
    protected $signature = 'bloodlink:fetch-findblood-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the receiver Find Blood page icons exported from Figma into public/images/findblood';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/9224a1f7-cde0-4ab4-943d-0b5cbf3998e2';

    /** local file name => remote file name */
    private const ASSETS = [
        'nav-grid-dark.svg'   => '7fc6b.svg',
        'nav-find-active.svg' => 'f0978.svg',
        'info.svg'            => '9de89.svg',
        'info-red.svg'        => '10878.svg',
        'hospital.svg'        => 'd7ffc.svg',
        'chevron-down.svg'    => '0ed9a.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/findblood');
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

        $this->info('All Find Blood assets are ready.');
        return self::SUCCESS;
    }
}
