<?php

namespace App\Import\LubeLogger;

use App\Enums\RecordCategory;
use App\Models\User;
use Illuminate\Support\Str;

/** A JSON-friendly summary of mapped data for the confirmation screen. */
class PreviewBuilder
{
    public function __construct(private BackupArchive $archive) {}

    public function build(array $mapped, array $options): array
    {
        $vehicles = collect($mapped['vehicles']);

        return [
            'vehicles' => $vehicles->map(fn (array $v) => $this->vehicle($v, $mapped['users']))->values()->all(),
            'users' => $this->users($mapped['users'], $vehicles),
            'summary' => $this->summary($mapped),
            'warnings' => $mapped['warnings'],
            'detected' => [
                'timezone' => $options['timezone'],
                'date_order' => $options['date_order'],
                'date_samples' => collect((new Analyzer($this->archive))->cultureDateSamples())->take(5)
                    ->map(fn ($raw) => ['raw' => $raw, 'parsed' => DateParser::parse($raw, $options['date_order'])])->all(),
            ],
            'skipped_vehicles' => $mapped['skipped_vehicles'],
            'excluded_vehicles' => $this->excludedVehicles($mapped),
        ];
    }

    /** Vehicles in the backup the admin switched off, so they can be switched back on. */
    private function excludedVehicles(array $mapped): array
    {
        $included = collect($mapped['vehicles'])->keys()->map(fn ($id) => (int) $id)->all();

        return collect($this->archive->collection('vehicles'))
            ->reject(fn (array $v) => in_array((int) $v['_id'], $included, true))
            ->map(function (array $v) {
                $photo = filled($v['ImageLocation'] ?? null) ? $this->archive->file((string) $v['ImageLocation']) : null;

                return [
                    'source_id' => (int) $v['_id'],
                    'title' => trim(($v['Year'] ?? '').' '.($v['Make'] ?? '').' '.($v['Model'] ?? '')) ?: __('Vehicle'),
                    'license_plate' => filled($v['LicensePlate'] ?? null) ? (string) $v['LicensePlate'] : null,
                    'photo' => $photo ? Str::after($photo, rtrim($this->archive->root, '/').'/') : null,
                ];
            })->values()->all();
    }

    private function vehicle(array $v, array $users): array
    {
        $a = $v['attributes'];
        $entries = collect([...$v['records'], ...$v['fuel'], ...$v['odometer']]);
        $dates = $entries->pluck('attributes.date')->filter()->sort()->values();
        $files = collect([...$v['records'], ...$v['fuel'], ...$v['plans'], ...$v['notes'], ...$v['supplies'], ...$v['equipment']])
            ->sum(fn ($e) => count($e['files'] ?? []));

        $recordsByCategory = collect($v['records'])->countBy('attributes.category');

        return [
            'key' => $v['key'],
            'source_id' => (int) Str::after($v['key'], ':'),
            'title' => trim(($a['year'] ?? '').' '.$a['make'].' '.$a['model']),
            'license_plate' => $a['license_plate'],
            'fuel_type' => $a['fuel_type'],
            'distance_unit' => $a['distance_unit'],
            'fuel_unit' => $a['fuel_unit'],
            'purchase_date' => $a['purchase_date'],
            'sold_date' => $a['sold_date'],
            'photo' => $v['photo'] ? Str::after($v['photo']['path'], rtrim($this->archive->root, '/').'/') : null,
            'counts' => [
                'service' => $recordsByCategory[RecordCategory::Service->value] ?? 0,
                'repair' => $recordsByCategory[RecordCategory::Repair->value] ?? 0,
                'upgrade' => $recordsByCategory[RecordCategory::Upgrade->value] ?? 0,
                'tax_fee' => $recordsByCategory[RecordCategory::TaxFee->value] ?? 0,
                'fuel' => count($v['fuel']),
                'odometer' => count($v['odometer']),
                'odometer_skipped' => $v['skipped']['odometer_duplicates'],
                'reminders' => count($v['reminders']),
                'plans' => count($v['plans']),
                'supplies' => count($v['supplies']),
                'notes' => count($v['notes']),
                'equipment' => count($v['equipment']),
                'files' => $files + ($v['photo'] ? 1 : 0),
            ],
            'first_date' => $dates->first(),
            'last_date' => $dates->last(),
            'total_cost' => (float) collect($v['records'])->sum(fn ($r) => (float) $r['attributes']['cost'])
                + (float) collect($v['fuel'])->sum(fn ($f) => (float) $f['attributes']['cost']),
            'users' => collect($v['users'])->map(fn ($ref) => $this->userLabel($ref, $users))->all(),
            'samples' => [
                'records' => collect($v['records'])->sortByDesc('attributes.date')->take(8)->map(fn ($r) => [
                    'date' => $r['attributes']['date'], 'category' => $r['attributes']['category'],
                    'description' => $r['attributes']['description'], 'mileage' => $r['attributes']['mileage'],
                    'cost' => (float) $r['attributes']['cost'],
                ])->values()->all(),
                'fuel' => collect($v['fuel'])->sortByDesc('attributes.date')->take(5)->map(fn ($f) => [
                    'date' => $f['attributes']['date'], 'mileage' => $f['attributes']['mileage'],
                    'quantity' => (float) $f['attributes']['quantity'], 'cost' => (float) $f['attributes']['cost'],
                ])->values()->all(),
                'reminders' => collect($v['reminders'])->take(8)->map(fn ($r) => [
                    'description' => $r['attributes']['description'], 'due_date' => $r['attributes']['due_date'],
                    'due_mileage' => $r['attributes']['due_mileage'],
                ])->values()->all(),
            ],
        ];
    }

    private function users(array $users, $vehicles): array
    {
        return collect($users)->except('root')->map(fn (array $u, $ref) => [
            'ref' => (string) $ref,
            'username' => $u['label'],
            'email' => $u['attributes']['email'] ?? null,
            'is_admin' => (bool) ($u['attributes']['is_admin'] ?? false),
            'has_password' => ($u['attributes']['legacy_sha256'] ?? null) !== null,
            'action' => $u['action'],
            'user_id' => $u['user_id'],
            'vehicles' => $vehicles->filter(fn ($v) => in_array((string) $ref, $v['users'], true))
                ->map(fn ($v) => trim(($v['attributes']['year'] ?? '').' '.$v['attributes']['make'].' '.$v['attributes']['model']))
                ->values()->all(),
        ])->values()->all();
    }

    private function summary(array $mapped): array
    {
        $vehicles = collect($mapped['vehicles']);
        $sum = fn (string $key) => $vehicles->sum(fn ($v) => count($v[$key]));
        $counts = $mapped['source_counts'];
        $source = fn (string ...$collections) => array_sum(array_map(fn ($c) => $counts[$c] ?? 0, $collections));

        return [
            ['label' => __('Vehicles'), 'source' => $source('vehicles'), 'imported' => $vehicles->count()],
            ['label' => __('Log entries (service, repair, upgrade, tax)'), 'source' => $source('servicerecords', 'collisionrecords', 'upgraderecords', 'taxrecords'), 'imported' => $sum('records'),
                'note' => $source('inspectionrecords') ? __(':count inspections are added to their log entries\' notes', ['count' => $source('inspectionrecords')]) : null],
            ['label' => __('Fuel-ups'), 'source' => $source('gasrecords'), 'imported' => $sum('fuel')],
            ['label' => __('Odometer readings'), 'source' => $source('odometerrecords'), 'imported' => $sum('odometer'),
                'note' => __(':count automatic copies of other entries skipped', ['count' => $vehicles->sum('skipped.odometer_duplicates')])],
            ['label' => __('Reminders'), 'source' => $source('reminderrecords'), 'imported' => $sum('reminders'),
                'note' => __('includes renewal reminders for recurring taxes')],
            ['label' => __('Plans'), 'source' => $source('planrecords'), 'imported' => $sum('plans')],
            ['label' => __('Supplies'), 'source' => $source('supplyrecords'), 'imported' => $sum('supplies'),
                'note' => $sum('supplies') < $source('supplyrecords') ? __('only supplies that belong to a vehicle') : null],
            ['label' => __('Notes'), 'source' => $source('notes'), 'imported' => $sum('notes')],
            ['label' => __('Equipment'), 'source' => $source('equipmentrecords'), 'imported' => $sum('equipment')],
            ['label' => __('Photos & files'), 'source' => $this->archive->filesInBackup(), 'imported' => $this->fileCount($mapped),
                'note' => $this->archive->unusedFiles() ? __(':count unused files left out', ['count' => $this->archive->unusedFiles()]) : null],
            ['label' => __('Users'), 'source' => $source('userrecords'), 'imported' => collect($mapped['users'])->except('root')->where('action', 'create')->count(),
                'note' => __('new accounts; others are mapped or skipped')],
        ];
    }

    private function userLabel(string $ref, array $users): string
    {
        $user = $users[$ref] ?? null;

        if ($user === null) {
            return $ref;
        }

        if ($user['action'] === 'map' && $user['user_id']) {
            return User::query()->find($user['user_id'])?->username ?? $user['label'];
        }

        return $user['label'];
    }

    /** Files that will be attached: vehicle photos plus attachments on entries. */
    private function fileCount(array $mapped): int
    {
        $entries = collect($mapped['vehicles'])
            ->flatMap(fn (array $v) => [...$v['records'], ...$v['fuel'], ...$v['plans'], ...$v['notes'], ...$v['supplies'], ...$v['equipment']]);

        return $entries->sum(fn (array $entry) => count($entry['files'] ?? []))
            + collect($mapped['vehicles'])->filter(fn (array $v) => $v['photo'] !== null)->count();
    }
}
