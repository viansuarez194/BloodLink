<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchRequestReviewAssets extends Command
{
    protected $signature = 'bloodlink:fetch-requestreview-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the Incoming Requests review page icons exported from Figma into public/images/requestreview';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/05769925-7d13-44fb-99a8-4401e96a3159';

    /** local file name => remote file name */
    private const ASSETS = [
        'nav-requests-active.svg' => 'a94a8.svg',
        'circle-check.svg'        => 'e6d94.svg',
        'circle.svg'              => '0f6be.svg',
        'info.svg'                => '10878.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/requestreview');
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

        $this->info('All Incoming Requests review assets are ready.');
        return self::SUCCESS;
    }
}
