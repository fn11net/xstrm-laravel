<?php

namespace Xstrm\Xstrm\Commands;

use Illuminate\Console\Command;
use Xstrm\Xstrm\Config;
use Xstrm\Xstrm\Dsn;

class InstallCommand extends Command
{
    protected $signature = 'xstrm:install';

    protected $description = 'Publish the Xstrm config and print what to put in your .env';

    public function handle(Config $config): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'xstrm-config']);
        $this->components->info('Published config/xstrm.php');

        $this->appendEnvStub();

        $dsn = $config->dsn();

        $this->newLine();

        if ($dsn === null) {
            $this->components->warn('No valid XSTRM_DSN yet — paste the one from your project\'s install screen:');
            $this->line('  <fg=gray>XSTRM_DSN=https://sk_live_...@in.xstrm.net/17</>');
        } else {
            $this->components->info("Reporting to {$dsn->endpoint} as project {$dsn->projectId}.");
        }

        $this->newLine();
        $this->line('  Everything else has working defaults. Switch modules off individually with');
        $this->line('  <fg=gray>XSTRM_ANALYTICS</>, <fg=gray>XSTRM_ERRORS</>, <fg=gray>XSTRM_PERF</>, or all of it with <fg=gray>XSTRM_ENABLED=false</>.');
        $this->newLine();

        return self::SUCCESS;
    }

    protected function appendEnvStub(): void
    {
        $path = base_path('.env');

        if (! is_file($path) || ! is_writable($path)) {
            return;
        }

        $contents = (string) file_get_contents($path);

        if (str_contains($contents, 'XSTRM_DSN')) {
            return;
        }

        file_put_contents($path, rtrim($contents)."\n\nXSTRM_DSN=\n", LOCK_EX);
        $this->components->info('Added an empty XSTRM_DSN to your .env');
    }
}
