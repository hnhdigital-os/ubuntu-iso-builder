<?php

namespace App\Commands;

use HnhDigital\CliHelper\FileSystemTrait;
use HnhDigital\CliHelper\OperatingTrait;
use LaravelZero\Framework\Commands\Command;

class IsoMountCommand extends Command
{
    use FileSystemTrait, OperatingTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'iso:mount
                            {path : ISO path}
                            {--mount-path= : Mount path}
                            {--force : Force mount.}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Copy contents of an ISO.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $iso_path = $this->argument('path');

        if (!file_exists($iso_path)) {
            $this->error(sprintf('%s does not exist.', $iso_path));

            return 1;
        }

        if (empty($iso_mount_path = $this->option('mount-path'))) {
            $cwd = getcwd();
            $basename = basename($iso_path);
            $iso_mount_path = $cwd.'/'.$basename.'.mount';
        }

        if (file_exists($iso_mount_path)) {
            $is_mounted = $this->isMounted($iso_mount_path);

            if ($is_mounted && $this->option('force')) {
                $this->unmount($iso_mount_path);
                $is_mounted = false;
            }

            if ($is_mounted) {
                $this->error('Already mounted. Use --force option or iso:close');

                return 1;
            }
        } else {
            mkdir($iso_mount_path, 0755, true);
        }

        $this->exec('sudo mount -o loop -r "%s" "%s"', $iso_path, $iso_mount_path);

        $this->line($iso_mount_path);

        return 0;
    }
}
