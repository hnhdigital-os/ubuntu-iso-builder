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
                        'iso-modify',
                        'iso-create'
                    ];
                    break;
                case 2:
                    $methods = [
                        'iso-copy',
                        'source-open',
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
    private function sourceOpen()
    {
        $this->call('squashfs', [
            'mode'         => 'open',
            '--fs-path'    => $this->fs_path,
            '--mount-path' => $this->mount_path,
        ]);
    }

    /**
     * Close the source file system.
     *
     * @return string
     */
    private function sourceClose()
    {
        $this->call('squashfs', [
            'mode'          => 'close',
            '--fs-path'     => $this->fs_path,
            '--source-path' => $this->source_path,
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