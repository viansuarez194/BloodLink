<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class FetchAuthAssets extends Command
{
    protected $signature = 'bloodlink:fetch-auth-assets {--force : Re-download files that already exist}';

    protected $description = 'Download the login/register images and icons exported from Figma into public/images/auth';

    /**
     * Temporary Figma export links (they expire ~7 days after being generated).
     * Format: prefix => [local file name => remote file name]
     */
    private const GROUPS = [
        // Login screen
        'https://www.figma.com/api/mcp/asset/552a82dd-1a6d-4b41-8fb6-7cfa8ea9973c' => [
            'login-left.png'    => '9225b.png',
            'logo-icon-dark.svg'=> 'e1483.svg',
            'blood-pulse.svg'   => 'bf8b6.svg',
            'line.svg'          => '74f4d.svg',
            'circle-x.svg'      => 'fc7c5.svg',
        ],
        // Registration screen
        'https://www.figma.com/api/mcp/asset/5a5755e5-75cb-40bf-8807-3f8452b55b7b' => [
            'register-right.png'  => 'be0a6.png',
            'author.png'          => 'b5e63.png',
            'step-line.svg'       => '4e4f6.svg',
            'role-donor.svg'      => 'eb83b.svg',
            'role-selected.svg'   => '45576.svg',
            'role-receiver.svg'   => '7bf56.svg',
            'role-hospital.svg'   => 'ca70c.svg',
            'arrow-right.svg'     => 'dc431.svg',
            'status-dot.svg'      => '54f1f.svg',
            'metric-line.svg'     => '860a3.svg',
        ],
    ];

    public function handle(): int
    {
        $dir = public_path('images/auth');
        File::ensureDirectoryExists($dir);

        $failed = 0;

        foreach (self::GROUPS as $prefix => $files) {
            foreach ($files as $local => $remote) {
                $path = $dir . DIRECTORY_SEPARATOR . $local;

                if (File::exists($path) && File::size($path) > 0 && ! $this->option('force')) {
                    $this->line("skip       {$local}");
                    continue;
                }

                $response = Http::timeout(30)->get($prefix . '/' . $remote);

                if (! $response->successful() || $response->body() === '') {
                    $this->error("FAILED     {$local} (HTTP {$response->status()})");
                    $failed++;
                    continue;
                }

                File::put($path, $response->body());
                $this->info("downloaded {$local}");
            }
        }

        if ($failed > 0) {
            $this->newLine();
            $this->warn("{$failed} file(s) failed. The Figma links expire after about 7 days - ask for refreshed links.");
            return self::FAILURE;
        }

        $this->info('All login/register assets are ready.');
        return self::SUCCESS;
    }
}
