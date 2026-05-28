<?php

namespace Webkul\NativephpRemote\Console\Commands;

use Illuminate\Console\Command;
use Native\Mobile\NativeServiceProvider;
use Webkul\NativephpRemote\Support\Patcher;

class PatchCommand extends Command
{
    protected $signature = 'nativephp:patch-remote {--force : Replace supported NativePHP files with stubs instead of patching}';

    protected $description = 'Apply NativePHP hosted remote mode patches for mobile apps.';

    public function handle(Patcher $patcher): int
    {
        if (! class_exists(NativeServiceProvider::class)) {
            $this->components->warn('nativephp/mobile is not installed.');

            return self::FAILURE;
        }

        $patcher->apply((bool) $this->option('force'));

        $this->components->info(
            $this->option('force')
                ? 'NativePHP remote stubs copied.'
                : 'NativePHP remote patches applied.'
        );

        return self::SUCCESS;
    }
}
