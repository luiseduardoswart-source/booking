<?php

namespace App\Console\Commands;

use App\Support\Backups\Backups;
use Illuminate\Console\Command;
use Throwable;

class MakeBackup extends Command
{
    protected $signature = 'backups:make';

    protected $description = 'Backup of the database and the uploaded files; removes backups older than the days to keep';

    public function handle(): int
    {
        try {
            $name = Backups::run();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup {$name} made.");

        return self::SUCCESS;
    }
}
