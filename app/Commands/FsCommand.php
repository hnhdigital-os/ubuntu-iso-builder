<?php

namespace App\Commands;

use HnhDigital\CliHelper\FileSystemTrait;
use HnhDigital\CliHelper\OperatingTrait;
use LaravelZero\Framework\Commands\Command;

class FsCommand extends Command
{
    use FileSystemTrait, OperatingTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'fs
                            {mode : open|run-action|close}
                            {--cwd= : Current working directory}
                            {--mount-path= : Mount path}
                            {--source-path= : Source path}
                            {--fs-path= : File System path}
                            {--action= : Run a specific action}
                            {--data= : Data needed for action}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Modify the file system';

    /**
     * Current working directory.
     *
     * @var string
     */
    protected $cwd = '';

    /**
     * Mount path.
     *
     * @var string
     */
    protected $mount_path = '';

    /**
     * Source path.
     *
     * @var string
     */
    protected $source_path = '';

    /**
     * FS path.
     *
     * @var string
     */
    protected $fs_path = '';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->mount_path = $this->option('mount-path');
        $this->source_path = $this->option('source-path');

        // Current working directory.
        if (empty($this->cwd = $this->option('cwd'))) {
            $this->cwd = dirname($this->mount_path);
        }

        // FS path.
        if (empty($this->fs_path = $this->option('fs-path'))) {
            $basename = basename($this->mount_path);
            $this->fs_path = $this->cwd.'/'.$basename.'.fs';
        }

        if (empty($this->argument('mode'))) {
            return 1;
        }

        $method_name = camel_case($this->argument('mode'));

        if (method_exists($this, $method_name)) {
            if (!$this->$method_name()) {

                return;
            }
        }
    }

    /**
     * Open the File System.
     *
     * @return void
     */
    private function open()
    {
        // Cleanup existing connections.
        $this->cleanup(true);

        $check_mount = true;
        $squashfs_path = $this->mount_path;

        if (!empty($this->source_path) && file_exists(sprintf('%s/casper/filesystem.squashfs', $this->source_path))) {
            $check_mount = false;
            $squashfs_path = $this->source_path;
        }

        if ($check_mount && !$this->isMounted($squashfs_path)) {
            $this->line(sprintf('[<info>fs open</info>] <error>%s is not mounted.</error>', $squashfs_path));

            return 1;
        }

        if (!file_exists(sprintf('%s/casper/filesystem.squashfs', $squashfs_path))) {
            $this->line(sprintf('[<info>fs open</info>] <error>%s/casper/filesystem.squashfs is missing.</error>', $squashfs_path));

            return 1;
        }

        $this->line(sprintf('[<info>fs open</info>] Unsquashing FS @ %s/casper/filesystem.squashfs', $squashfs_path));

        $exit_code = $this->exec('sudo unsquashfs -d "%s" "%s/casper/filesystem.squashfs"', $this->fs_path, $squashfs_path, [
            'return' => 'exit_code',
        ]);

        if ($exit_code) {
            $this->line('[<info>fs open</info>] <error>Failed to unsquashfs. Error code: %s</error>', $exit_code);

            return 1;
        }

        $this->exec('sudo chown 1777 "%s"', $this->fs_path);
        $this->exec('sudo chmod 1777 "%s/tmp"', $this->fs_path);

        // Chroot the filesystem.
        $this->exec('cp "/etc/resolv.conf" "%s/run/systemd/resolve/stub-resolv.conf"', $this->fs_path);
        $this->mount('/dev', $this->fs_path.'/dev', ['sudo' => true]);

        if (!empty($this->cwd)) {
            $this->mount($this->cwd, $this->fs_path.'/host', ['sudo' => true]);
        }

        $this->init();
    }

    /**
     * Run an action.
     *
     * @return void
     */
    private function runAction()
    {
        if (empty($this->option('action'))) {
            return 1;
        }

        $method_name = camel_case($this->option('action'));

        if ($method_name != 'init' && !file_exists($this->fs_path.'/chroot-init')) {
            $this->line('[<info>fs init</info>] Not initialized.');

            return;
        }

        if (method_exists($this, $method_name)) {
            if (!$this->$method_name()) {

                return;
            }
        }
    }

    /**
     * Init chroot.
     *
     * @return void
     */
    private function init()
    {
        if (file_exists($this->fs_path.'/chroot-init')) {
            $this->line('[<info>fs init</info>] Already initialized.');
            return;
        }

        $commands = [
            'mount -t "proc" none "/proc" >/dev/null 2>&1',
            'mount -t "sysfs" none "/sys" >/dev/null 2>&1',
            'mount -t "devpts" none "/dev/pts" >/dev/null 2>&1',
            'export HOME=/root',
            'export LC_ALL=C.UTF-8',
            'systemd-machine-id-setup >/dev/null 2>&1',
            'dpkg-divert --local --rename --add "/sbin/initctl" >/dev/null',
            'test -f "/sbin/initctl" || ln -s "/bin/true" "/sbin/initctl"',
        ];

        $this->multiExec($commands);

        $this->exec('echo "%s" | sudo tee "%s/chroot-init"', date('Y-m-d H:i:s'), $this->fs_path);

        return 0;
    }

    /**
     * Upgrade software in fs.
     *
     * @return void
     */
    private function upgradeSoftware()
    {
        $commands = [
            'apt-get update',
            'apt-get -y upgrade --download-only --allow-unauthenticated',
        ];

        $this->multiExec($commands);

        return 0;
    }

    /**
     * Add APT repository.
     *
     * @return void
     */
    private function addAptRepo()
    {
        if (empty($repos = $this->option('data'))) {
            $this->error('No apt repositories provided.');

            return 1;
        }

        $repos = explode(',', $repos);
        $commands = [];

        foreach ($repos as $repo) {
            $commands[] = sprintf('add-apt-repository -y %s', $repo);
        }

        $this->multiExec($commands);

        return 0;
    }

    /**
     * Install package.
     *
     * @return void
     */
    private function installPackage()
    {
        if (empty($packages = $this->option('data'))) {
            $this->error('No packages provided.');

            return 1;
        }

        $packages = str_replace(',', ' ', $packages);

        $commands = [
            'apt-get update',
            sprintf('apt-get -y -f install %s', $packages),
        ];

        $this->multiExec($commands);

        return 0;
    }

    /**
     * Purge packages.
     *
     * @return void
     */
    private function purgePackage()
    {
        if (empty($package = $this->option('data'))) {
            $this->error('No package provided.');

            return 1;
        }

        $this->exec('apt-get -y purge %s', $package, ['chroot' => $this->fs_path]);

        return 0;
    }

    /**
     * Replace files.
     *
     * @return void
     */
    private function replaceFile()
    {
       if (empty($path = $this->option('data'))) {
            $this->error('No path provided.');

            return 1;
        }

        if (!file_exists($this->fs_path.'/host/replace/'.$path)) {
            $this->error(sprintf('%s not found.', $path));

            return 1;
        }

        $output = $this->exec('mkdir -p "%s"', dirname('/'.$path), ['chroot' => $this->fs_path]);
        $output = $this->exec('cp -f "/host/replace/%s" "/%s"', $path, $path, ['chroot' => $this->fs_path]);

        return 0;
    }

    /**
     * Debian install.
     *
     * @return void
     */
    private function debInstall()
    {
        if (empty($packages = $this->option('data'))) {
            $this->error('No file path provided.');

            return 1;
        }

        $this->exec('cp "/host/install/%s" "/usr/src/%s"', $package, ['chroot' => $this->fs_path]);
        $this->exec('gdebi -n "/usr/src/%s"', $package, ['chroot' => $this->fs_path]);

        return 0;
    }

    /**
     * Run bash scripts in chroot.
     *
     * @return void
     */
    private function runScripts()
    {
        if (empty($scripts = $this->option('data'))) {
            $this->error('No bash scripts provided.');

            return 1;
        }

        $scripts = explode(',', $scripts);

        $commands = [];

        foreach ($scripts as $script) {
            $fs_script_path = sprintf('%s/scripts/%s', $this->cwd, $script);
            $chroot_script_path = sprintf('/host/scripts/%s', $script);

            $this->exec('chmod +x "%s"', $fs_script_path);
            $commands[] = $chroot_script_path;
        }

        $this->multiExec($commands);

        return 0;
    }

    /**
     * Text replace in files.
     *
     * @return void
     */
    private function textReplace()
    {
        if (empty($find_replace = $this->option('data'))) {
            $this->error('No find replace provided.');

            return 1;
        }

        if (is_null($find_replace = json_decode($find_replace, true))) {
            $this->error(json_last_error_msg());

            return 1;
        }

        $path =  sprintf('%s/%s', $this->fs_path, array_get($find_replace, 'path'));

        if (empty(array_get($find_replace, 'path', false)) || !file_exists($path)) {
            $this->error(sprintf('%s does not exist.', $path));

            return 1;
        }

        if (empty(array_get($find_replace, 'find', false))) {
            $this->error('Find value must not be empty.');

            return 1;
        }

        $contents = file_get_contents($path);
        $updated_contents = str_replace('%%'.array_get($find_replace, 'find').'%%', array_get($find_replace, 'replace'), $contents);

        // No change.
        if ($contents === $updated_contents) {
            return 0;
        }

        $this->replaceFileContents($path, $contents);

        return 0;
    }

    /**
     * Execute array of commands.
     *
     * @param array $commands
     *
     * @return void
     */
    private function multiExec($commands)
    {
        foreach ($commands as $command) {
            $output = $this->exec($command, ['chroot' => $this->fs_path]);
        }
    }

    /**
     * Uninit chroot.
     *
     * @return void
     */
    private function uninit()
    {
        $commands = [
            'apt-get -y -qq autoremove >/dev/null 2>&1',
            'apt-get -y -qq autoclean >/dev/null 2>&1',
            'rm -rf "/tmp/"* ~/.bash_history',
            'echo "" | tee "/etc/machine-id"',
            'rm "/sbin/initctl"',
            'dpkg-divert --rename --remove "/sbin/initctl" >/dev/null',
            'umount "/proc" >/dev/null || umount -lf "/proc" >/dev/null 2>&1',
            'umount "/sys" >/dev/null 2>&1',
            'umount "/dev/pts" >/dev/null 2>&1',
        ];

        foreach ($commands as $command) {
            $this->line(sprintf('[<info>fs uninit</info>] %s', $command));
            $this->exec($command, ['chroot' => $this->fs_path]);
        }

        if (file_exists($this->fs_path.'/chroot-init')) {
            $this->exec('sudo unlink "%s/chroot-init"', $this->fs_path);
        }
    }

    /**
     * Close the File System.
     *
     * @return void
     */
    private function close()
    {
        $this->fs_path = $this->option('fs-path');
        $this->source_path = $this->option('source-path');

        if (!file_exists($this->fs_path)) {
            $this->error(sprintf('%s [FS] does not exist.', $this->fs_path));

            return 1;
        }

        if (!file_exists($this->source_path)) {
            $this->error(sprintf('%s [SRC] does not exist.', $this->source_path));

            return 1;
        }

        $this->cleanup();

        if (file_exists($this->fs_path.'/casper/filesystem.manifest')) {
            $this->exec('sudo chmod +w "%s/casper/filesystem.manifest"', $this->fs_path);
        }

        $this->exec('sudo chroot "%s" dpkg-query -W --showformat=\'${Package} ${Version}\n\' | sudo tee "%s/casper/filesystem.manifest"', $this->fs_path, $this->source_path);

        if (file_exists($this->source_path.'/casper/filesystem.squashfs')) {
            $this->exec('unlink "%s/casper/filesystem.squashfs"', $this->source_path);
        }

        $this->exec('sudo mksquashfs "%s" "%s/casper/filesystem.squashfs" -b 1048576 >/dev/null', $this->fs_path, $this->source_path);

        $this->exec('sudo rm -rf "%s"', $this->fs_path);
    }

    /**
     * Check if the filesystem already exists.
     *
     * @param bool $delete
     *
     * @return string
     */
    private function cleanup($delete = false)
    {
        $this->uninit();

        if (empty($this->fs_path)) {
            throw new \Exception('Path for File System is empty!');
        }

        if (!file_exists($this->fs_path)) {
            return;
        }

        $this->unmount($this->fs_path.'/dev', ['sudo' => true]);
        $this->unmount($this->fs_path.'/host', ['sudo' => true]);

        if (file_exists($this->fs_path.'/host')) {
            $this->exec('sudo rmdir "%s"', $this->fs_path.'/host');
        }

        if ($delete && file_exists($this->fs_path)) {
            $this->exec('sudo rm -rf "%s"', $this->fs_path);
        }
    }
}
