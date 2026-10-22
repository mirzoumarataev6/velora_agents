<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class DemoDataCommand extends Command
{
    protected $signature = 'db:demo
        {--user= : Email of the owner the demo agents belong to (defaults to the first user)}
        {--force : Allow running when APP_ENV=production}';

    protected $description = 'Seed (or re-seed) the demo agents, conversations, leads and ratings';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to seed demo data in production without --force.');

            return self::FAILURE;
        }

        $seeder = new DemoSeeder;
        $seeder->ownerEmail = $this->option('user');
        $seeder->setContainer($this->laravel)->setCommand($this);
        $seeder->__invoke();

        return self::SUCCESS;
    }
}
