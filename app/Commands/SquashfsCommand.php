<?php

namespace App\Commands;

use HnhDigital\CliHelper\FileSystemTrait;
use HnhDigital\CliHelper\OperatingTrait;
use LaravelZero\Framework\Commands\Command;

class SquashfsCommand extends Command
{
    use FileSystemTrait, OperatingTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'squashfs
                            {mode : open|close}
                            {--mount-path= : Mount path}
                            {--source-path= : Source path}
                            {--fs-path= : File System path}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Modify the file system';

    /**
     * ISO mount path.
     *
     * @var string
     */
    protected $iso_mount_path;

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        switch ($this->argument('mode')) {
            case 'open':
                return $this->open();
            case 'close':
                return $this->close();
        }
    }

    /**
     * Open the File System.
     *
     * @return void
     */
    private function open()
    {
        $mount_path = $this->option('mount-path');

        if (empty($fs_path = $this->option('fs-path'))) {
            $cwd = dirname($mount_path);
            $basename = basename($mount_path);
            $fs_path = $cwd.'/'.$basename.'.fs';
        }

        $this->cleanup($fs_path, true);

        $this->exec('sudo unsquashfs -d "%s" "%s/casper/filesystem.squashfs"', $fs_path, $mount_path);

        // Chroot the filesystem.
        $this->exec('sudo cp "/etc/resolv.conf" "%s/run/systemd/resolve/stub-resolv.conf"', $fs_path);
        $this->mount('/dev', $fs_path.'/dev', true);
        $this->mount($cwd, $fs_path.'/host', true);
        $this->exec('sudo chmod 1777 "%s/tmp"', $fs_path);
    }

    /**
     * Close the File System.
     *
     * @return void
     */
    private function close()
    {
        $fs_path = $this->option('fs-path');
        $source_path = $this->option('source-path');

        if (!file_exists($fs_path)) {

            return 1;
        }

        if (!file_exists($source_path)) {

            return 1;
        }

        $this->cleanup($fs_path);

        if (file_exists($fs_path.'/casper/filesystem.manifest')) {
            $this->exec('sudo chmod +w "%s/casper/filesystem.manifest"', $fs_path);
        }

        $this->exec('sudo chroot "%s" dpkg-query -W --showformat=\'${Package} ${Version}\n\' | sudo tee "%s/casper/filesystem.manifest"', $fs_path, $source_path);

        if (file_exists($source_path.'/casper/filesystem.squashfs')) {
            $this->exec('unlink "%s/casper/filesystem.squashfs"', $source_path);
        }

        $this->exec('sudo mksquashfs "%s" "%s/casper/filesystem.squashfs" -b 1048576 >/dev/null', $fs_path, $source_path);

        $this->exec('sudo rm -rf "%s"', $fs_path);
    }

    /**
     * Check if the filesystem already exists.
     *
     * @param string $path
     *
     * @return string
     */
    private function cleanup($path, $delete = false)
    {
        if (empty($path)) {
            throw new \Exception('Path for File System is empty!');
        }

        $this->unmount($path.'/dev', true);
        $this->unmount($path.'/host', true);
        if (file_exists($path.'/host')) {
            $this->exec('sudo rmdir "%s"', $path.'/host');
        }

        if ($delete && file_exists($path)) {
            $this->exec('sudo rm -rf "%s"', $path);
        }
    }
}
