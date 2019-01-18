<?php

namespace App\Commands;

use HnhDigital\CliHelper\OperatingTrait;
use LaravelZero\Framework\Commands\Command;

class IsoModifyCommand extends Command
{
    use OperatingTrait;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'iso:modify
                            {fs-path : Filesystem path}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Modify the file system';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

    }
}
