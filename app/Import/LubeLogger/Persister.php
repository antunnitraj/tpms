<?php

namespace App\Import\LubeLogger;

use App\Enums\SupplyMovementType;
use App\Models\BackupImport;
use App\Models\Equipment;
use App\Models\FuelRecord;
use App\Models\Note;
use App\Models\OdometerReading;
use App\Models\Plan;
use App\Models\Record;
use App\Models\Reminder;
use App\Models\Supply;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Writes mapped LubeLogger data. Rows go in one transaction; files are copied afterwards. */
class Persister
{
    /** @var array<string, Model> source key => created model */
    private array $models = [];

    /** @var list<array{model: Model, collection: string, path: string, name: string}> */
    private array $files = [];

    private array $warnings = [];

    private array $counts = [];

    public function __construct(private BackupImport $import, private array $mapped) {}

    /** @return array{counts: array<string, int>, warnings: list<string>, vehicle_ids: list<int>} */
    public function persist(): array
    {
        DB::transaction(function () {
            $users = $this->persistUsers();

            foreach ($this->mapped['vehicles'] as $vehicle) {
                $this->persistVehicle($vehicle, $users);
            }

            foreach ($this->mapped['vehicles'] as $vehicle) {
                $this->persistEntries($vehicle);
            }

            $this->persistSupplyUsage();
            $this->resolveLinks();
        });

        $this->copyFiles();

        return [
            'counts' => $this->counts,
            'warnings' => $this->warnings,
            'vehicle_ids' => collect($this->models)->filter(fn ($m) => $m instanceof Vehicle)->map->getKey()->values()->all(),
        ];
    }

    /** @return array<string, User> */
    private function persistUsers(): array
    {
        $users = [];

        foreach ($this->mapped['users'] as $ref => $user) {
            if ($user['action'] === 'skip') {
                continue;
            }

            if ($user['action'] === 'map') {
                $existing = User::query()->find($user['user_id']);
                if ($existing === null) {
                    throw new RuntimeException(__('The user chosen for :user no longer exists.', ['user' => $user['label']]));
                }
                $users[$ref] = $existing;

                if ($ref === 'root') {
                    $this->mergeSettings($existing, $this->mapped['root_settings']);
                }

                continue;
            }

            $attributes = $user['attributes'];
            $created = User::query()->create([
                'name' => $attributes['name'],
                'username' => $this->uniqueUsername($attributes['username']),
                'email' => $attributes['email'] && ! User::query()->whereRaw('lower(email) = ?', [mb_strtolower($attributes['email'])])->exists()
                    ? $attributes['email'] : null,
                // Unusable until the LubeLogger password (legacy hash) is used once.
                'password' => Str::random(64),
                'legacy_sha256' => $attributes['legacy_sha256'],
                'is_admin' => $attributes['is_admin'],
                'settings' => $user['settings'] ?: null,
                'import_id' => $this->import->getKey(),
            ]);

            $users[$ref] = $created;
            $this->count('users');
        }

        if (! isset($users['root'])) {
            throw new RuntimeException(__('Choose which user receives the LubeLogger root user\'s vehicles.'));
        }

        return $users;
    }

    private function mergeSettings(User $user, array $settings): void
    {
        if ($settings === []) {
            return;
        }

        // Never overwrite choices the user already made in TPMS.
        $user->update(['settings' => array_replace_recursive($settings, $user->settings ?? [])]);
    }

    private function uniqueUsername(string $username): string
    {
        $base = Str::of($username)->trim()->limit(50, '')->toString() ?: 'user';
        $candidate = $base;
        $i = 2;

        while (User::query()->whereRaw('lower(username) = ?', [mb_strtolower($candidate)])->exists()) {
            $candidate = $base.$i++;
        }

        return $candidate;
    }

    private function persistVehicle(array $mapped, array $users): void
    {
        $owner = $users['root'];
        $vehicle = Vehicle::query()->create($mapped['attributes'] + [
            'created_by' => $owner->getKey(),
            'import_id' => $this->import->getKey(),
            'source_id' => (int) Str::after($mapped['key'], ':'),
        ]);

        $vehicle->users()->sync(collect($mapped['users'])->map(fn ($ref) => $users[$ref] ?? null)->filter()->map->getKey()->unique()->all());

        if ($mapped['photo']) {
            $this->files[] = ['model' => $vehicle, 'collection' => 'photos', ...$mapped['photo']];
        }

        $this->models[$mapped['key']] = $vehicle;
        $this->count('vehicles');

        foreach ($mapped['supplies'] as $supply) {
            $this->persistSupply($supply, $owner, $vehicle);
        }
    }

    private function persistEntries(array $mapped): void
    {
        /** @var Vehicle $vehicle */
        $vehicle = $this->models[$mapped['key']];
        $base = ['vehicle_id' => $vehicle->getKey(), 'import_id' => $this->import->getKey()];

        foreach ($mapped['reminders'] as $reminder) {
            $this->models[$reminder['key']] = Reminder::query()->create($reminder['attributes'] + $base + ['source_id' => $this->sourceId($reminder['key'])]);
            $this->count('reminders');
        }

        foreach ($mapped['records'] as $record) {
            $this->models[$record['key']] = $this->entry(Record::class, $record, $base);
            $this->count('records');
        }

        foreach ($mapped['fuel'] as $fuel) {
            $this->models[$fuel['key']] = $this->entry(FuelRecord::class, $fuel, $base);
            $this->count('fuel_records');
        }

        foreach ($mapped['odometer'] as $reading) {
            $this->models[$reading['key']] = OdometerReading::query()->create($reading['attributes'] + $base + ['source_id' => $this->sourceId($reading['key'])]);
            $this->count('odometer_readings');
        }

        foreach ($mapped['plans'] as $plan) {
            $this->entry(Plan::class, $plan, $base);
            $this->count('plans');
        }

        foreach ($mapped['notes'] as $note) {
            $this->entry(Note::class, $note, $base);
            $this->count('notes');
        }

        foreach ($mapped['equipment'] as $equipment) {
            /** @var Equipment $model */
            $model = $this->entry(Equipment::class, $equipment, $base);
            foreach ($equipment['periods'] as $period) {
                $model->periods()->create($period);
            }
            $this->count('equipment');
        }
    }

    private function entry(string $class, array $mapped, array $base): Model
    {
        $attributes = $mapped['attributes'] + $base + ['source_id' => $this->sourceId($mapped['key'])];

        if (in_array($class, [Record::class, FuelRecord::class], true)) {
            $attributes['links'] = null;
        }

        /** @var Model $model */
        $model = new $class;
        $model->forceFill(array_filter($attributes, fn ($v, $k) => ! in_array($k, ['created_at', 'updated_at', 'completed_at'], true) || $v !== null, ARRAY_FILTER_USE_BOTH));
        $model->save();

        foreach ($mapped['files'] ?? [] as $file) {
            $this->files[] = ['model' => $model, 'collection' => 'attachments', ...$file];
        }

        $this->models[$mapped['key']] = $model;

        return $model;
    }

    private function persistSupply(array $mapped, User $owner, Vehicle $vehicle): void
    {
        $supply = Supply::query()->create($mapped['attributes'] + [
            'user_id' => $owner->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'import_id' => $this->import->getKey(),
            'source_id' => $this->sourceId($mapped['key']),
        ]);

        if (bccomp($mapped['opening']['quantity'], '0', 3) !== 0 || bccomp($mapped['opening']['cost'], '0', 3) !== 0) {
            $supply->movements()->create([
                'date' => $mapped['opening']['date'],
                'type' => SupplyMovementType::Purchase,
                'quantity' => $mapped['opening']['quantity'],
                'cost' => $mapped['opening']['cost'],
                'note' => __('Imported from LubeLogger'),
                'import_id' => $this->import->getKey(),
            ]);
        }

        foreach ($mapped['files'] as $file) {
            $this->files[] = ['model' => $supply, 'collection' => 'attachments', ...$file];
        }

        $this->models[$mapped['key']] = $supply;
        $this->count('supplies');
    }

    private function persistSupplyUsage(): void
    {
        foreach ($this->mapped['vehicles'] as $vehicle) {
            foreach ([...$vehicle['records'], ...$vehicle['fuel'], ...$vehicle['plans']] as $entry) {
                foreach ($entry['supply_usage'] ?? [] as $line) {
                    $supply = $this->models[$line['supply']] ?? null;
                    $consumer = $this->models[$entry['key']] ?? null;

                    if (! $supply instanceof Supply || $consumer === null) {
                        $this->warnings[] = __('A supply used by ":entry" no longer exists in LubeLogger; the usage was skipped.', ['entry' => $consumer?->description ?? $entry['key']]);

                        continue;
                    }

                    $supply->movements()->create([
                        'date' => $line['date'] ?? now()->toDateString(),
                        'type' => SupplyMovementType::Use,
                        'quantity' => bcmul($line['quantity'], '-1', 3),
                        'cost' => bcmul($line['cost'], '-1', 3),
                        'consumer_type' => $consumer->getMorphClass(),
                        'consumer_id' => $consumer->getKey(),
                        'import_id' => $this->import->getKey(),
                    ]);
                }
            }
        }
    }

    /** Links between entries, reminders on plans and done plans' records, now that every id exists. */
    private function resolveLinks(): void
    {
        foreach ($this->mapped['vehicles'] as $vehicle) {
            foreach ([...$vehicle['records'], ...$vehicle['fuel']] as $entry) {
                $links = collect($entry['links'] ?? [])->map(function (array $link) {
                    if (isset($link['url'])) {
                        return ['name' => $link['name'], 'url' => $link['url']];
                    }

                    $target = $this->resolve($link['ref']);

                    return $target ? ['name' => $link['name'], 'type' => $target->getMorphClass(), 'id' => $target->getKey()] : null;
                })->filter()->values()->all();

                if ($links !== []) {
                    $this->models[$entry['key']]->update(['links' => $links]);
                }
            }

            foreach ($vehicle['plans'] as $plan) {
                /** @var Plan $model */
                $model = $this->models[$plan['key']];
                $reminders = collect($plan['reminders'])->map(fn ($ref) => $this->resolve($ref)?->getKey())->filter()->all();
                $model->reminders()->sync($reminders);

                if ($plan['completed_record'] && ($record = $this->resolve($plan['completed_record']))) {
                    $model->update(['completed_record_id' => $record->getKey()]);
                }
            }
        }
    }

    private function copyFiles(): void
    {
        foreach ($this->files as $file) {
            try {
                $name = $file['name'];
                $extension = pathinfo($file['path'], PATHINFO_EXTENSION);
                $fileName = Str::of(pathinfo($name, PATHINFO_FILENAME))->slug()->limit(80, '')->toString() ?: 'file';

                $file['model']->addMedia($file['path'])
                    ->preservingOriginal()
                    ->usingName($name)
                    ->usingFileName($fileName.($extension ? '.'.strtolower($extension) : ''))
                    ->toMediaCollection($file['collection']);

                $this->count('files');
            } catch (Throwable $e) {
                $this->warnings[] = __('Could not copy ":name": :error', ['name' => $file['name'], 'error' => $e->getMessage()]);
            }
        }
    }

    private function resolve(string $ref): ?Model
    {
        $ref = $this->mapped['aliases'][$ref] ?? $ref;

        return $this->models[$ref] ?? null;
    }

    private function sourceId(string $key): ?int
    {
        $id = explode(':', $key)[1] ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function count(string $what): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + 1;
    }
}
