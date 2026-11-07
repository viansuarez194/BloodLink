<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchNewRequestAssets extends Command
{
    protected $signature = 'bloodlink:fetch-newrequest-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the New Blood Request page icons exported from Figma into public/images/newrequest';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/3f8565c0-6362-4ee5-b90e-8570cebdefe4';

    /** local file name => remote file name */
    private const ASSETS = [
        'nav-clipboard-active.svg' => 'a94a8.svg',
        'chevron-down.svg'         => '0ed9a.svg',
        'info-red.svg'             => '10878.svg',
        'info-amber.svg'           => '9de89.svg',
        'upload.svg'               => '93dfb.svg',
        'file-text.svg'            => '3afd9.svg',
        'circle.svg'               => '0f6be.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/newrequest');
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

        $this->info('All New Blood Request assets are ready.');
        return self::SUCCESS;
    }
}
