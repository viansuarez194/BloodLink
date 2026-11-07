<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchRequestDetailAssets extends Command
{
    protected $signature = 'bloodlink:fetch-requestdetail-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the Request Details / Tracking page icons exported from Figma into public/images/requestdetail';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/ce6130fe-6e1f-499a-bbd3-624540e396d8';

    /** local file name => remote file name */
    private const ASSETS = [
        'shield-check.svg'   => '825c0.svg',
        'event-check.svg'    => 'd9ce2.svg',
        'event-clock.svg'    => '9c7fc.svg',
        'event-circle.svg'   => '719e2.svg',
        'circle-check.svg'   => 'e6d94.svg',
        'circle.svg'         => '0f6be.svg',
        'file-text.svg'      => '3afd9.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/requestdetail');
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

        $this->info('All Request Details assets are ready.');
        return self::SUCCESS;
    }
}
