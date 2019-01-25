<?php

namespace App\Commands;

use Artisan;
use HnhDigital\CliHelper\FileSystemTrait;
use HnhDigital\CliHelper\OperatingTrait;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;

class IsoCopyCommand extends Command
{
    use FileSystemTrait, OperatingTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'iso:copy
                            {path : ISO path}
                            {--source-path= : Source path}
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
        $output = new BufferedOutput();

        $exit_code = Artisan::call('iso:mount', [
            'path'         => $this->argument('path'),
            '--mount-path' => $this->option('mount-path'),
            '--force'      => true,
        ], $output);

        if (empty($this->iso_mount_path = $this->option('mount-path'))) {
            $this->iso_mount_path = trim($output->fetch());
        }

        if ($exit_code || !file_exists($this->iso_mount_path)) {
            $this->error('Error in iso:mount');
            $this->line($output->fetch());

            return 1;
        }

        // Source path has not been provided.
        if (empty($iso_src_path = $this->option('source-path'))) {
            $cwd = getcwd();
            $basename = basename($this->argument('path'));
            $iso_src_path = $cwd.'/'.$basename.'.src';
        }

        // Clear existing directory.
        $this->removeDirectory($iso_src_path);

        // Create new directory.
        $this->createDirectory($iso_src_path);

        // Copy contents of the ISO.
        $this->exec('rsync -avq --exclude=/casper/filesystem.squashfs "%s/" "%s"', $this->iso_mount_path, $iso_src_path);

        // Set chmod on all the files.
        $this->chmod($iso_src_path, '755');

        $this->line($iso_src_path);

        return 0;
    }
}
