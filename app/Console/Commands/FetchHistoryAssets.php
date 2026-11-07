<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchHistoryAssets extends Command
{
    protected $signature = 'bloodlink:fetch-history-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the donation history page icons exported from Figma into public/images/history';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/0a293f36-2c49-4f3d-9b2b-3ad9b9c09140';

    /** local file name => remote file name */
    private const ASSETS = [
        'nav-clock-active.svg' => 'b1231.svg',
        'info.svg'             => '10878.svg',
        'shield-check.svg'     => '825c0.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/history');
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

        $this->info('All donation history assets are ready.');
        return self::SUCCESS;
    }
}
