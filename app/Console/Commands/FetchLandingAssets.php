<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchLandingAssets extends Command
{
    protected $signature = 'bloodlink:fetch-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the landing page images/icons exported from Figma into public/images/landing';

    /**
     * Temporary Figma export URL (expires ~7 days after it was generated).
     * If downloads fail with 403/404, ask for a fresh prefix and paste it here.
     */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/32a77039-8ed2-4065-9fd2-c22f6039998f';

    /** local file name => remote file name */
    private const ASSETS = [
        'hero-image.png'        => '200cf.png',
        'avatar-1.png'          => '58dd7.png',
        'avatar-2.png'          => '57658.png',
        'heart-pulse.svg'       => '0d0c5.svg',
        'pulse-dot.svg'         => '4755a.svg',
        'arrow-right.svg'       => 'c68c4.svg',
        'doh-dot.svg'           => '683ec.svg',
        'line.svg'              => 'f7c52.svg',
        'chevron-down.svg'      => 'e2407.svg',
        'map-pin.svg'           => '4b74a.svg',
        'search.svg'            => '59368.svg',
        'impact-dot.svg'        => '5bee3.svg',
        'user-check.svg'        => 'd40dd.svg',
        'calendar.svg'          => 'dd436.svg',
        'heart.svg'             => 'fe75b.svg',
        'check.svg'             => 'ab182.svg',
        'check-sm.svg'          => '3e632.svg',
        'alert-circle.svg'      => '6d610.svg',
        'alert-circle-sm.svg'   => '9dca8.svg',
        'building.svg'          => 'e4a1a.svg',
        'sync-dot.svg'          => 'cacc3.svg',
        'bg-glow.svg'           => '12a4d.svg',
        'heart-pulse-white.svg' => '644f4.svg',
        'line-footer.svg'       => '584ee.svg',
        'facebook.svg'          => '6efbf.svg',
        'twitter.svg'           => '5affc.svg',
        'linkedin.svg'          => 'd1c41.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/landing');
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
            $this->warn("{$failed} file(s) failed. The Figma links expire after about 7 days - ask for refreshed links and update PREFIX in this file.");
            return self::FAILURE;
        }

        $this->info('All landing page assets are ready.');
        return self::SUCCESS;
    }
}
