<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchReceiverAssets extends Command
{
    protected $signature = 'bloodlink:fetch-receiver-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the receiver dashboard icons exported from Figma into public/images/receiver';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/63745805-bf9a-45dc-af60-b01d3c0bfbd2';

    /** local file name => remote file name */
    private const ASSETS = [
        'brand-mark.svg'          => '3d192.svg',
        'bell.svg'                => '74f9b.svg',
        'divider.svg'             => '78784.svg',
        'online-status.svg'       => '683ec.svg',
        'chevron-down.svg'        => 'e76dc.svg',
        'nav-grid.svg'            => '97781.svg',
        'nav-requests.svg'        => '3899e.svg',
        'nav-find.svg'            => '54b60.svg',
        'nav-appointments.svg'    => '24c8d.svg',
        'nav-bookmark.svg'        => 'c3ada.svg',
        'nav-medical.svg'         => '77ed1.svg',
        'phone-call.svg'          => 'e8959.svg',
        'sidebar-divider.svg'     => '2c7b0.svg',
        'status.svg'              => '5bee3.svg',
        'banner-glow.svg'         => 'dd095.svg',
        'live-dot.svg'            => '8e362.svg',
        'request-divider.svg'     => '63094.svg',
        'check.svg'               => 'b1e8d.svg',
        'progress-connector.svg'  => '2e4e7.svg',
        'step-marker.svg'         => '09605.svg',
        'button-icon.svg'         => 'a3255.svg',
        'phone.svg'               => 'f4aab.svg',
        'appointment-divider.svg' => '4053c.svg',
        'calendar-check.svg'      => '3d055.svg',
        'metric-circle-x.svg'     => '4335e.svg',
        'metric-hospital.svg'     => '43e18.svg',
        'metric-timer.svg'        => '56050.svg',
        'metric-bookmark.svg'     => 'b3d35.svg',
        'action-arrow.svg'        => 'de371.svg',
        'facility-hospital.svg'   => '830d4.svg',
        'bookmark-check.svg'      => '91299.svg',
        'map-pin.svg'             => '4b74a.svg',
        'availability-dot.svg'    => 'bef80.svg',
        'bookmark.svg'            => '7a725.svg',
        'status-dot.svg'          => '744c6.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/receiver');
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

        $this->info('All receiver dashboard assets are ready.');
        return self::SUCCESS;
    }
}
