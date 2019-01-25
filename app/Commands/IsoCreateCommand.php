<?php

namespace App\Commands;

use HnhDigital\CliHelper\FileSystemTrait;
use HnhDigital\CliHelper\OperatingTrait;
use LaravelZero\Framework\Commands\Command;

class IsoCreateCommand extends Command
{
    use FileSystemTrait, OperatingTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'iso:create
                            {source-path : Source path}
                            {iso-path : ISO output path}
                            {iso-label : ISO label}
                            {--force : Force ignore ISO existing}
                            {--keep : Keep the source path}';
    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Create an ISO.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $source_path = $this->argument('source-path');

        if (!file_exists($source_path)) {
            $this->error(sprintf('%s does not exist', $source_path));

            return 1;
        }

        $iso_path = $this->argument('iso-path');
        $iso_label = $this->argument('iso-label');

        if (stripos($iso_path, '/') === false) {
            $iso_path = getcwd().'/build/'.$iso_path;
        }

        $iso_path_dir = dirname($iso_path);
        $this->createDirectory($iso_path_dir);

        if (file_exists($iso_path)) {
            if (!$this->option('force')) {
                $this->error(sprintf('%s already exists. Use --force to ignore.', $iso_path));

                return 1;
            }

            unlink($iso_path);
        }

        $source_folder = basename($source_path);

        // Remove existing filesystem.size file.
        $this->removeFile(sprintf('%s/casper/filesystem.size', $source_path));

        // Generate filesystem size file.
        $this->exec('du -sx --block-size=1 "%s" | cut -f1 | tee "%s/casper/filesystem.size" >/dev/null', $source_path, $source_path);

        // Generate MD5SUM file.
        $md5sum = $this->exec('find "%s" -type f \
          -not \( -path "*/isolinux/*" -prune \) \
          -not \( -path "*/EFI/*" -prune \) \
          -not \( -path "*/md5sum.txt" -prune \) \
          -print0 | xargs -0 md5sum', $source_path);

        // Convert to text.
        $md5sum = implode("\n", $md5sum);

        // Fix path structure by removing the folder name.
        $md5sum = str_replace($source_folder.'/', '', $md5sum);

        // Add to ISO.
        $this->replaceFileContents($source_path.'/md5sum.txt', $md5sum);

        // Create the ISO.
        list($exit_code, $output) = $this->exec('mkisofs -r -quiet -V "%s" \
            -cache-inodes \
            -J -l -b isolinux/isolinux.bin \
            -c isolinux/boot.cat -no-emul-boot \
            -boot-load-size 4 -boot-info-table \
            -input-charset utf-8 \
            -o "%s" "%s"', $iso_label, $iso_path, $source_path, [
                'return' => 'all'
            ]);

        if ($exit_code) {
            return 1;
        }

        if (!$this->option('keep')) {
            $this->removeDirectory($source_path);
        }

        $this->info(sprintf('%s created.', $iso_path));
    }
}
