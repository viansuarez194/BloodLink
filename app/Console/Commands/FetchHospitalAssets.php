<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchHospitalAssets extends Command
{
    protected $signature = 'bloodlink:fetch-hospital-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the hospital dashboard icons exported from Figma into public/images/hospital';

    /** Temporary Figma links (expire ~7 days after being generated). */
    private const PREFIX = 'https://www.figma.com/api/mcp/asset/b5758514-b9e1-4106-9ecb-04fecb67dd17';

    /** local file name => remote file name */
    private const ASSETS = [
        'brand-mark.svg'          => '3d192.svg',
        'bell.svg'                => '74f9b.svg',
        'divider.svg'             => '78784.svg',
        'online-status.svg'       => '683ec.svg',
        'chevron-down.svg'        => 'e76dc.svg',
        'nav-grid.svg'            => '97781.svg',
        'nav-inventory.svg'       => 'd0ca2.svg',
        'nav-requests.svg'        => '3899e.svg',
        'nav-dispatch.svg'        => '7b06d.svg',
        'nav-transfers.svg'       => 'd18c0.svg',
        'nav-donors.svg'          => '77ed1.svg',
        'phone-call.svg'          => 'e8959.svg',
        'sidebar-divider.svg'     => '2c7b0.svg',
        'status.svg'              => '5bee3.svg',
        'banner-accent.svg'       => '90a98.svg',
        'live-dot.svg'            => '893ec.svg',
        'package-check.svg'       => '030dd.svg',
        'low-stock-icon.svg'      => 'c59e6.svg',
        'inbox.svg'               => '3abe9.svg',
        'ambulance.svg'           => '5c4a7.svg',
        'status-dot-amber.svg'    => '98363.svg',
        'status-dot-red.svg'      => '98747.svg',
        'status-dot-blue.svg'     => '00795.svg',
        'status-dot-neutral.svg'  => '744c6.svg',
        'alert-icon.svg'          => '515b0.svg',
        'button-icon.svg'         => 'a3255.svg',
        'action-arrow.svg'        => 'de371.svg',
        'dispatch-divider.svg'    => '60d86.svg',
        'users-round.svg'         => '44f0e.svg',
        'alert-dot.svg'           => 'f9d89.svg',
    ];

    public function handle(): int
    {
        $dir = public_path('images/hospital');
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

        $this->info('All hospital dashboard assets are ready.');
        return self::SUCCESS;
    }
}
