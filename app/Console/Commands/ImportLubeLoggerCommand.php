<?php

namespace App\Console\Commands;

use App\Import\LubeLogger\LubeLoggerImporter;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class ImportLubeLoggerCommand extends Command
{
    protected $signature = 'lubelogger:import
        {zip : Path to the LubeLogger backup ZIP}
        {--target-user= : Username (or id) that receives the LubeLogger root user\'s vehicles; defaults to the first admin}
        {--timezone= : Timezone the LubeLogger server ran in (detected when omitted)}
        {--date-format= : dmy, mdy or ymd for dates stored as text (detected when omitted)}
        {--dry-run : Show the preview without importing}';

    protected $description = 'Import a LubeLogger backup ZIP (shows a preview first)';

    public function handle(LubeLoggerImporter $importer): int
    {
        $target = $this->targetUser();
        if ($target === null) {
            $this->error('No target user found. Create an admin first (php artisan app:create-admin).');

            return self::FAILURE;
        }

        try {
            $import = $importer->analyze($this->argument('zip'), basename($this->argument('zip')), $target);
            $options = $import->options;

            if ($this->option('timezone')) {
                $options['timezone'] = $this->option('timezone');
            }
            if ($this->option('date-format')) {
                $options['date_order'] = $this->option('date-format');
            }
            $import = $importer->preview($import, $options);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $preview = $import->preview;
        $this->info("Timezone: {$options['timezone']} · Date format: {$options['date_order']} · Root user → {$target->username}");

        $this->table(['Vehicle', 'Plate', 'Units', 'Log', 'Fuel', 'Readings', 'Reminders', 'Plans', 'Files', 'Access'], collect($preview['vehicles'])->map(fn ($v) => [
            $v['title'], $v['license_plate'] ?? '—', "{$v['distance_unit']} / {$v['fuel_unit']}",
            $v['counts']['service'] + $v['counts']['repair'] + $v['counts']['upgrade'] + $v['counts']['tax_fee'],
            $v['counts']['fuel'], $v['counts']['odometer'], $v['counts']['reminders'], $v['counts']['plans'], $v['counts']['files'],
            implode(', ', $v['users']),
        ]));

        if ($preview['users']) {
            $this->table(['LubeLogger user', 'Email', 'Admin', 'Action'], collect($preview['users'])->map(fn ($u) => [
                $u['username'], $u['email'] ?? '—', $u['is_admin'] ? 'yes' : 'no', $u['action'],
            ]));
        }

        $this->table(['Item', 'In backup', 'Imported'], collect($preview['summary'])->map(fn ($r) => [$r['label'], $r['source'] ?? '—', $r['imported']]));

        foreach ($preview['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($this->option('dry-run') || ! $this->confirm('Import this backup?', true)) {
            $importer->discard($import);
            $this->line('Nothing was imported.');

            return self::SUCCESS;
        }

        try {
            $import = $importer->import($import);
        } catch (Throwable $e) {
            $this->error('Import failed — nothing was imported: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Imported: '.collect($import->summary['counts'])->map(fn ($n, $k) => "{$n} {$k}")->implode(', '));

        return self::SUCCESS;
    }

    private function targetUser(): ?User
    {
        $target = $this->option('target-user');

        if ($target === null) {
            return User::query()->where('is_admin', true)->orderBy('id')->first();
        }

        return User::query()->where('username', $target)->orWhere('id', is_numeric($target) ? (int) $target : 0)->first();
    }
}
