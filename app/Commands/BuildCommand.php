<?php

namespace App\Commands;

use Artisan;
use HnhDigital\CliHelper\FileSystemTrait;
use HnhDigital\CliHelper\OperatingTrait;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\BufferedOutput;

class BuildCommand extends Command
{
    use FileSystemTrait, OperatingTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build
                            {config-path : Config path for building ISO}
                            {--override= : Config override path}
                            {--action= : Run a specific action}
                            {--sub-action= : Action to run on sub-processs}
                            {--data= : Data if needed by action}
                            {--level=1 : Level of build}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Build ISO using config file.';

    /**
     * Config.
     *
     * @var array
     */
    protected $config = [];

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
        if (!$this->loadConfig()) {
            return 1;
        }

        $this->cwd = getcwd();

        $methods = [];

        if ($this->option('action')) {
            $methods = [$this->option('action')];
        }

        if (empty($methods)) {

            $this->line(sprintf('Level: %s', $this->option('level')));

            switch ($this->option('level')) {
                case 1:
                    $methods = [
                        'iso-copy',
                        'fs-open',
                        'fs-modify',
                        'create-mirror',
                        'fs-close',
                        'iso-create'
                    ];
                    break;
                case 2:
                    $methods = [
                        'iso-copy',
                        'fs-open',
                    ];
                    break;
                case 3:
                    $methods = [
                        'fs-close',
                        'iso-create'
                    ];
                    break;
            }
        }

        $this->generateSourcePath();
        $this->generateMountPath();
        $this->generateFsPath();

        foreach ($methods as $method_name) {
            $this->line(sprintf('Processing <info>%s</info>', $method_name));
            
            $method_name = camel_case($method_name);

            if (method_exists($this, $method_name)) {
                if (!$this->$method_name()) {

                    return;
                }
            }
        }
    }

    /**
     * Copy from ISO.
     *
     * @return string
     */
    private function isoCopy()
    {
        $output = new BufferedOutput();

        $exit_code = Artisan::call('iso:copy', [
            'path'          => array_get($this->config, 'iso.source'),
            '--source-path' => $this->source_path,
            '--mount-path'  => $this->mount_path,
            '--force'       => true,
        ], $output);

        if ($exit_code || !file_exists($this->source_path)) {
            $this->error('Error in iso:copy');
            $this->line($output->fetch());

            return false;
        }

        return true;
    }

    /**
     * Generate mount path.
     *
     * @return string
     */
    private function generateMountPath()
    {
        $basename = basename(array_get($this->config, 'iso.source'));

        return $this->mount_path = $this->cwd.'/'.$basename.'.mount';
    }

    /**
     * Generate source path.
     *
     * @return string
     */
    private function generateSourcePath()
    {
        $basename = basename(array_get($this->config, 'iso.source'));

        return $this->source_path = $this->cwd.'/'.$basename.'.src';
    }

    /**
     * Generate FS path.
     *
     * @return string
     */
    private function generateFsPath()
    {
        $basename = basename(array_get($this->config, 'iso.source'));

        return $this->fs_path = $this->cwd.'/'.$basename.'.fs';
    }

    /**
     * Open the source file system.
     *
     * @return string
     */
    private function fsOpen()
    {
        $this->call('fs', [
            'mode'          => 'open',
            '--cwd'         => $this->cwd,
            '--fs-path'     => $this->fs_path,
            '--mount-path'  => $this->mount_path,
            '--source-path' => $this->source_path,
        ]);
    }

    /**
     * Modify the file system.
     *
     * @return void
     */
    private function fsModify()
    {
        $this->fsUpgradeSoftware();
        $this->fsInstallPackages();
        $this->fsDebInstall();
        $this->fsPurgePackages();
        $this->fsScripts();
        $this->fsReplaceFiles();
        $this->fsTextReplace();
    }

    /**
     * Install packages.
     *
     * @return void
     */
    private function fsUpgradeSoftware()
    {
        $this->fsAction('upgrade-software');
    }

    /**
     * Install packages.
     *
     * @return void
     */
    private function fsAddAptRepo()
    {
        $repos = implode(',', (array) array_get($this->config, 'packages.repo', []));

        if (empty($repos)) {
            if ($this->option('action') == 'fs-add-apt-repo') {
                $this->error('No apt repositories configured for addition');
            }

            return;
        }

        $this->fsAction('add-apt-repo', $repos);
    }

    /**
     * Install packages.
     *
     * @return void
     */
    private function fsInstallPackages()
    {
        // Check that the repo's have been added.
        $this->fsAddAptRepo();

        $packages = implode(',', (array) array_get($this->config, 'packages.install', []));

        if (empty($packages)) {
            if ($this->option('action') == 'fs-install-packages') {
                $this->error('No packages configured for install');
            }

            return;
        }

        $this->fsAction('install-package', $packages);
    }

    /**
     * Install packages.
     *
     * @return void
     */
    private function fsDebInstall()
    {
        $packages = implode(',', (array) array_get($this->config, 'packages.deb-install', []));

        if (empty($packages)) {
            $this->error('No packages configured for deb-install');

            return;
        }

        foreach ($packages as $package) {
            if (!file_exists(sprintf('%s/install/%s', $this->cwd, $package))) {
                $this->error(sprintf('%s not found in /install', $package));

                return;
            }
        }

        $this->fsAction('deb-install', $packages);
    }

    /**
     * Purge packages.
     *
     * @return void
     */
    private function fsPurgePackages()
    {
        $packages = implode(',', (array) array_get($this->config, 'packages.purge', []));

        if (empty($packages)) {
            if ($this->option('action') == 'fs-purge-packages') {
                $this->error('No packages configured for purge');
            }

            return;
        }

        $this->fsAction('purge-package', $packages);
    }

    /**
     * Run bash scripts.
     *
     * @return void
     */
    private function fsScripts()
    {
        $chroot_scripts = implode(',', (array) array_get($this->config, 'chroot-scripts', []));

        if (empty($chroot_scripts)) {
            if ($this->option('action') == 'fs-scripts') {
                $this->error('No bash scripts provided');
            }

            return;
        }

        $this->fsAction('run-scripts', $chroot_scripts);
    }

    /**
     * Modify the file system.
     *
     * @return void
     */
    private function fsReplaceFiles()
    {
        $dir_iterator = new \RecursiveDirectoryIterator(
            $this->cwd.'/replace',
            \FilesystemIterator::SKIP_DOTS
        );

        $iterator = new \RecursiveIteratorIterator(
            $dir_iterator,
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }

            $path = str_replace($this->cwd.'/replace/', '', $file->getPathname());
            $this->fsAction('replace-file', $path);
        }
    }

    /**
     * Run bash scripts.
     *
     * @return void
     */
    private function fsTextReplace()
    {
        $text_replace = (array) array_get($this->config, 'text-replace', []);

        if (empty($text_replace)) {
            if ($this->option('action') == 'fs-text-replace') {
                $this->error('No text replace entries provided');
            }

            return;
        }

        foreach ($text_replace as $path => $replacements) {
            foreach ($replacements as $find => $replace) {
                $this->fsAction('text-replace', json_encode([
                    'path'    => $path,
                    'find'    => $find,
                    'replace' => $replace,
                ]));
            }
        }
    }

    /**
     * Create mirror.
     *
     * @return void
     */
    private function createMirror()
    {
        $create_mirror = (bool) array_get($this->config, 'mirror.create', false);

        if (!$create_mirror) {
            return;
        }

        $this->mirrorDownload();
        $this->mirrorCopy();
        $this->mirrorCompile();
        $this->mirrorConfigure();
    }

    /**
     * Download packages for mirror.
     *
     * @return void
     */
    private function mirrorDownload()
    {
        $packages = implode(',', (array) array_get($this->config, 'packages.mirror', []));

        if (empty($packages)) {
            if ($this->option('action') == 'mirror-download') {
                $this->error('No packages configured for mirror');
            }

            return;
        }

        $this->mirrorAction('download', $packages);
    }

    /**
     * Transfer packages to the mirror.
     *
     * @return void
     */
    private function mirrorCopy()
    {
        $this->mirrorAction('copy-mirror');
    }

    /**
     * Compile packages for mirror.
     *
     * @return void
     */
    private function mirrorCompile()
    {
        $config = [
            'gpg'     => array_get($this->config, 'gpg', []),
            'release' => array_get($this->config, 'release', []),
        ];

        $this->mirrorAction('compile-mirror', json_encode($config));
    }

    /**
     * Create local mirror.
     *
     * @return void
     */
    private function mirrorConfigure()
    {
        $mirror_key = array_get($this->config, 'mirror.key');

        if (!file_exists($this->cwd.'/'.$mirror_key)) {
            $this->error(sprintf('%s does not exist.', $mirror_key));

            return 1;
        }

        $this->mirrorAction('configure-mirror', $mirror_key);
    }

    /**
     * Run a FS action.
     *
     * @param  boolean|string $sub_action 
     * @param  boolean|string $data
     *
     * @return string
     */
    private function fsAction($sub_action = false, $data = false)
    {
        if (empty($sub_action)) {
            $sub_action = $this->option('sub-action');

        }
        if (empty($data)) {
            $data = $this->option('data');
        }

        $this->call('fs', [
            'mode'          => 'runAction',
            '--cwd'         => $this->cwd,
            '--fs-path'     => $this->fs_path,
            '--mount-path'  => $this->mount_path,
            '--source-path' => $this->source_path,
            '--action'      => $sub_action,
            '--data'        => $data,
        ]);        
    }

    /**
     * Close the source file system.
     *
     * @return string
     */
    private function fsClose()
    {
        $this->call('fs', [
            'mode'          => 'close',
            '--fs-path'     => $this->fs_path,
            '--source-path' => $this->source_path,
        ]);
    }

    /**
     * Run a mirror action.
     *
     * @param  boolean|string $action
     * @param  boolean|string $data
     *
     * @return string
     */
    private function mirrorAction($action = false, $data = false)
    {
        if (empty($action)) {
            $action = $this->option('sub-action');

        }
        if (empty($data)) {
            $data = $this->option('data');
        }

        $this->call('mirror', [
            'action'        => $action,
            '--cwd'         => $this->cwd,
            '--fs-path'     => $this->fs_path,
            '--source-path' => $this->source_path,
            '--data'        => $data,
        ]);
    }

    /**
     * Create ISO.
     *
     * @return string
     */
    private function isoCreate()
    {
        $this->call('iso:create', [
            'source-path' => $this->source_path,
            'iso-path'    => array_get($this->config, 'iso.output'),
            'iso-label'   => array_get($this->config, 'iso.label'),
            '--force'     => true,
        ]);
    }

    /**
     * Load config.
     *
     * @return bool
     */
    private function loadConfig()
    {
        $this->config_path = $this->argument('config-path');
        $override_config_path = $this->option('override');

        if (!file_exists($this->config_path)) {
            $this->error(sprintf('%s does not exist.', $this->config_path));

            return false;
        }

        $this->config = $this->loadYamlFile($this->config_path);

        if (!empty($override_config_path)) {
            if (!file_exists($override_config_path)) {
                $this->error(sprintf('%s does not exist.', $override_config_path));

                return false;
            }

            $override_config = $this->loadYamlFile($override_config_path);
            $this->config = array_replace_recursive($this->config, $override_config);
        }

        return true;
    }
}