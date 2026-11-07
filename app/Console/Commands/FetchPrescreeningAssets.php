<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchPrescreeningAssets extends Command
{
    protected $signature = 'bloodlink:fetch-prescreening-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the pre-screening page icons exported from Figma into public/images/prescreening';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/0dfd1833-fcbf-4d64-9787-c87900ed7ed7';

    /** local file name => remote file name */
    private const ASSETS = [
        'nav-grid-dark.svg'        => '7fc6b.svg',
        'nav-clipboard-active.svg' => 'a94a8.svg',
        'info.svg'                 => '9de89.svg',
        'circle-check.svg'         => 'e6d94.svg',
        'circle.svg'               => '0f6be.svg',
        'info-privacy.svg'         => '10878.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/prescreening');
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

        $this->info('All pre-screening assets are ready.');
        return self::SUCCESS;
    }
}
