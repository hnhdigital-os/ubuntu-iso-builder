<?php

namespace App\Commands;

use HnhDigital\CliHelper\FileSystemTrait;
use HnhDigital\CliHelper\OperatingTrait;
use LaravelZero\Framework\Commands\Command;

class MirrorCommand extends Command
{
    use FileSystemTrait, OperatingTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'mirror
                            {action : download|compile|configure}
                            {--cwd= : Current working directory}
                            {--mirror-path= : Mirror path}
                            {--source-path= : Source path}
                            {--fs-path= : File System path}
                            {--data= : Data needed for action}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create mirror.';

    /**
     * Current working directory.
     *
     * @var string
     */
    protected $cwd = '';

    /**
     * Mirror path.
     *
     * @var string
     */
    protected $mirror_path = '';

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
        $this->source_path = $this->option('source-path');

        // Current working directory.
        if (empty($this->cwd = $this->option('cwd'))) {
            $this->cwd = dirname($this->source_path);
        }

        // FS path.
        if (empty($this->fs_path = $this->option('fs-path'))) {
            $basename = basename(str_replace('.src', '', $this->source_path));
            $this->fs_path = $this->cwd.'/'.$basename.'.fs';
        }

        // Mirror path.
        if (empty($this->mirror_path = $this->option('mirror-path'))) {
            $basename = basename(str_replace('.src', '', $this->source_path));
            $this->mirror_path = $this->cwd.'/'.$basename.'.mirror';
        }

        // Create mirror.
        $this->createDirectory($this->mirror_path);
        $this->createDirectory($this->fs_path.'/mirror-files');
        $this->exec('chmod 1777 "%s"', $this->mirror_path);

        // Mount.
        $this->mount($this->mirror_path, $this->fs_path.'/mirror-files', ['sudo' => true]);

        $method_name = camel_case($this->argument('action'));

        if (method_exists($this, $method_name)) {
            if (!$this->$method_name()) {

                return;
            }
        }
    }

    /**
     * Download packages.
     *
     * @return void
     */
    public function download()
    {
        if (empty($packages = $this->option('data'))) {
            $this->error('No packages provided.');

            return 1;
        }

        $this->exec('apt-get update', ['chroot' => $this->fs_path]);

        $packages = str_replace(',', ' ', $packages);

        $depends_list = $this->exec('apt-rdepends %s | grep -v "^ "', $packages, [
            'return' => 'output',
        ]);

        foreach ($depends_list as $package) {
            $latest_package_version = trim($this->exec('apt-cache policy %s  | grep "Candidate: " | awk \'{print $2}\'', $package, [
                'return' => 'output_string'
            ]));

            $current_package_path = array_get(glob(sprintf('%s/%s_*.deb', $this->mirror_path, $package)), 0, false);
            $current_package_version = array_get(explode('_', str_replace('%3a', ':', basename($current_package_path))), 1, false);

            if (!empty($current_package_path)
                && $latest_package_version != $current_package_version) {
                $this->removeFile($current_package_path);

                if ($this->hasVerbose('v')) {
                    $this->line(sprintf('Deleted <info>%s</info>', $current_package_path));
                }
            }

            $this->exec('cd "/mirror-files" && apt-get download %s', $package, [
                'chroot'       => $this->fs_path,
                'timeout'      => null,
                'idle-timeout' => null,
            ]);
        }
        
    }

    /**
     * Transfer the packages to the local mirror folder.
     *
     * @return void
     */
    public function copyMirror()
    {
        $local_mirror_path = sprintf('%s/local-mirror', $this->fs_path);

        // Reset the folder.
        $this->removeDirectory($local_mirror_path);

        // Create folder.
        $this->createDirectory($local_mirror_path);

        $package_paths = glob(sprintf('%s/*', $this->mirror_path));

        foreach ($package_paths as $package_path) {
            $basename = basename($package_path);
            $folder = substr($basename, 0, 1);

            if (substr($basename, 0, 3) === 'lib') {
                $folder = substr($basename, 0, 4);
            }

            $package_name = array_get(explode('_', $basename), 0);

            $dest_path = sprintf(
                '%s/%s/%s',
                $local_mirror_path,
                $folder,
                $package_name
            );

            $this->createDirectory($dest_path);

            $this->exec('cp -R "%s" "%s"', $package_path, $dest_path);
        }
    }

    /**
     * Compile the mirror.
     *
     * @return void
     */
    public function compileMirror()
    {
        if (empty($config = $this->option('data'))) {
            $this->error('No config provided.');

            return 1;
        }

        if (is_null($config = json_decode($config, true))) {
            $this->error(json_last_error_msg());

            return 1;
        }

        $local_mirror_path = sprintf('%s/local-mirror', $this->fs_path);

        $release_config_path = tempnam('/tmp', 'release-conf');

        file_put_contents($release_config_path, $this->generateReleaseConf($config));

        // Remove duplicate files in local mirror that already exist in the pool.
        $this->exec('fdupes -rdNq "%s/pool/main" "%s"', $this->source_path, $local_mirror_path);

        // Remove any empty folders.
        $this->exec('find "%s" -type d -empty -delete', $local_mirror_path);

        // Create a Packages file.
        $this->exec('apt-ftparchive packages "%s" > "%s/Packages"', $local_mirror_path, $local_mirror_path);

        // Make file paths relative.
        $this->sed($local_mirror_path.'/', '', $local_mirror_path.'/Packages');

        // Gzip the Packages file.
        $this->exec('gzip -c "%s/Packages" | tee "%s/Packages".gz  > /dev/null', $local_mirror_path, $local_mirror_path);

        // Create a Release file.
        $this->exec('apt-ftparchive -c "%s" release "%s" >"%s/Release"', $release_config_path, $local_mirror_path, $local_mirror_path);

        // Just in case it exists.
        $this->removeFile($local_mirror_path.'/Release.gpg');

        // Sign the Release file.
        $this->exec('gpg --default-key "%s" --no-tty --no-use-agent --batch --passphrase "%s" --output "%s/Release.gpg" -ba "%s/Release"', array_get($config, 'gpg.key'), array_get($config, 'gpg.password'), $local_mirror_path, $local_mirror_path);

        // Remove temp release config file.
        unlink($release_config_path);
    }

    /**
     * Generate release conf.
     *
     * @param array $config
     *
     * @return string
     */
    private function generateReleaseConf($config)
    {
        $text = sprintf('APT::FTPArchive::Release::Origin "%s";', array_get($config, 'release.origin', ''));
        $text .= sprintf('APT::FTPArchive::Release::Label "%s";', array_get($config, 'release.label', ''));
        $text .= sprintf('APT::FTPArchive::Release::Suite "%s";', array_get($config, 'release.suite', ''));
        $text .= sprintf('APT::FTPArchive::Release::Version "%s";', array_get($config, 'release.version', ''));
        $text .= sprintf('APT::FTPArchive::Release::Codename "%s";', array_get($config, 'release.codename', ''));
        $text .= sprintf('APT::FTPArchive::Release::Architectures "%s";', array_get($config, 'release.architectures', ''));
        $text .= sprintf('APT::FTPArchive::Release::Components "%s";', array_get($config, 'release.components', ''));
        $text .= sprintf('APT::FTPArchive::Release::Description "%s";', array_get($config, 'release.description', ''));

        return $text;
    }

    /**
     * Create mirror.
     */
    private function configureMirror()
    {
        if (empty($mirror_key = $this->option('data'))) {
            $this->error('Mirror key not provided.');

            return 1;
        }

        $sources_list_path = sprintf('%s/etc/apt/sources.list', $this->fs_path);

        $contents = file_get_contents($sources_list_path);

        if (stripos($contents, 'local-mirror') === false) {
            $contents .= "\ndeb file:///local-mirror ./";
            $this->replaceFileContents($sources_list_path, $contents);
        }

        $this->exec('apt-key add "/host/%s"', $mirror_key, ['chroot' => $this->fs_path]);

        return 0;
    }
}
