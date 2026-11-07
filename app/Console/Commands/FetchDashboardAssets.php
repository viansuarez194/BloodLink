<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchDashboardAssets extends Command
{
    protected $signature = 'bloodlink:fetch-dashboard-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the donor dashboard icons exported from Figma into public/images/dashboard';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/b921cbc8-8c02-4dc1-a842-d99561ca3d5c';

    /** local file name => remote file name */
    private const ASSETS = [
        'logo-icon.svg'      => '7f049.svg',
        'search.svg'         => 'b38eb.svg',
        'bell.svg'           => '74f9b.svg',
        'v-line.svg'         => '4a267.svg',
        'tier-dot.svg'       => '51839.svg',
        'nav-grid.svg'       => 'b96fb.svg',
        'nav-clipboard.svg'  => 'de3d5.svg',
        'nav-calendar.svg'   => '44cfc.svg',
        'nav-clock.svg'      => 'a7894.svg',
        'nav-heart.svg'      => 'df7a5.svg',
        'nav-user.svg'       => '819af.svg',
        'sidebar-line.svg'   => '2c7b0.svg',
        'doh-dot.svg'        => '5bee3.svg',
        'arrow-right.svg'    => 'c68c4.svg',
        'appt-calendar.svg'  => '719fe.svg',
        'card-line.svg'      => 'b2cdd.svg',
        'stat-droplet.svg'   => 'd9c7e.svg',
        'stat-activity.svg'  => '5539c.svg',
        'stat-award.svg'     => 'f6c85.svg',
        'arrow-right-red.svg'=> '77f74.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/dashboard');
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

        $this->info('All donor dashboard assets are ready.');
        return self::SUCCESS;
    }
}
