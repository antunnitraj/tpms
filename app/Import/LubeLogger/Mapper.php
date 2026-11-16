<?php

namespace App\Import\LubeLogger;

use App\Enums\FuelType;
use App\Enums\PlanStatus;
use App\Enums\RecordCategory;
use App\Enums\RepeatUnit;
use App\Services\Reminders;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Translates a LubeLogger backup into TPMS rows without writing anything.
 * The preview is built from this output and the persister writes exactly it,
 * so what the admin confirms is what gets imported.
 *
 * Cross references use keys "{collection}:{sourceId}" (e.g. "servicerecords:12")
 * and are resolved to new ids by the persister.
 */
class Mapper
{
    private const MAINTENANCE = [
        'servicerecords' => RecordCategory::Service,
        'collisionrecords' => RecordCategory::Repair,
        'upgraderecords' => RecordCategory::Upgrade,
    ];

    private string $timezone;

    private string $dateOrder;

    private array $warnings = [];

    /** @var Collection<int, array> vehicles being imported, keyed by source id */
    private Collection $vehicles;

    public function __construct(private BackupArchive $archive, private array $options)
    {
        $this->timezone = $options['timezone'] ?? 'UTC';
        $this->dateOrder = $options['date_order'] ?? 'mdy';
    }

    public function map(): array
    {
        $this->vehicles = collect($this->archive->collection('vehicles'))
            ->keyBy(fn ($v) => (int) $v['_id'])
            ->filter(fn ($v) => (bool) Arr::get($this->options, "vehicles.{$v['_id']}.import", true));

        $usage = $this->supplyUsage();
        $vehicles = [];

        foreach ($this->vehicles as $id => $vehicle) {
            $vehicles[$id] = $this->mapVehicle($vehicle) + [
                'records' => [], 'fuel' => [], 'odometer' => [], 'reminders' => [], 'plans' => [],
                'supplies' => [], 'notes' => [], 'equipment' => [], 'skipped' => ['odometer_duplicates' => 0],
            ];
        }

        $this->mapRecords($vehicles, $usage);
        $this->mapFuel($vehicles, $usage);
        $this->mapReminders($vehicles);
        $this->mapTaxes($vehicles);
        $aliases = $this->mapInspections($vehicles);
        $this->mapPlans($vehicles, $usage);
        $this->mapOdometerAndEquipment($vehicles);
        $this->mapNotes($vehicles);
        $this->mapSupplies($vehicles, $usage);

        $users = $this->mapUsers();
        $this->assignAccess($vehicles, $users);

        return [
            'users' => $users,
            'root_settings' => $this->settingsFrom($this->archive->userConfig(), true),
            'vehicles' => $vehicles,
            'aliases' => $aliases,
            'source_counts' => $this->sourceCounts(),
            'skipped_vehicles' => collect($this->archive->collection('vehicles'))
                ->reject(fn ($v) => $this->vehicles->has((int) $v['_id']))->count(),
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    // ---------------------------------------------------------------- vehicles

    private function mapVehicle(array $v): array
    {
        $units = Arr::get($this->options, "vehicles.{$v['_id']}") ?? [];
        $extra = $this->extraFields($v['ExtraFields'] ?? []);
        $identifier = $v['VehicleIdentifier'] ?? 'LicensePlate';
        $plate = $this->str($v['LicensePlate'] ?? null)
            ?? ($identifier !== 'LicensePlate' ? $this->str($extra[(string) $identifier] ?? null) : null);

        $name = trim(($v['Year'] ?? '').' '.($v['Make'] ?? '').' '.($v['Model'] ?? ''));
        $purchaseDate = $this->cultureDate($v['PurchaseDate'] ?? null, "{$name}: purchase date");
        $soldDate = $this->cultureDate($v['SoldDate'] ?? null, "{$name}: sold date");

        $photo = null;
        $image = (string) ($v['ImageLocation'] ?? '');
        if ($image !== '' && ! str_starts_with($image, '/defaults/')) {
            $path = $this->archive->file($image);
            $path
                ? $photo = ['path' => $path, 'name' => basename($image)]
                : $this->warn(__(':vehicle: photo :file is not in the backup.', ['vehicle' => $name, 'file' => $image]));
        }

        return [
            'key' => "vehicles:{$v['_id']}",
            'attributes' => [
                'year' => ($v['Year'] ?? 0) > 0 ? (int) $v['Year'] : null,
                'make' => $this->str($v['Make'] ?? null) ?? __('Unknown'),
                'model' => $this->str($v['Model'] ?? null) ?? '',
                'license_plate' => $plate,
                'fuel_type' => match (true) {
                    (bool) ($v['IsElectric'] ?? false) => FuelType::Electric->value,
                    (bool) ($v['IsDiesel'] ?? false) => FuelType::Diesel->value,
                    default => FuelType::Gasoline->value,
                },
                'distance_unit' => $units['distance_unit'] ?? 'km',
                'fuel_unit' => $units['fuel_unit'] ?? 'l',
                'purchase_date' => $purchaseDate,
                'purchase_price' => $this->positive($v['PurchasePrice'] ?? null),
                'sold_date' => $soldDate,
                'sold_price' => $soldDate ? $this->dec($v['SoldPrice'] ?? null) : null,
                'tags' => $this->tags($v['Tags'] ?? []),
                'notes' => $this->notesWithExtras(null, $extra),
            ],
            'photo' => $photo,
            'users' => [],
        ];
    }

    // ----------------------------------------------------------------- records

    private function mapRecords(array &$vehicles, array $usage): void
    {
        foreach (self::MAINTENANCE as $collection => $category) {
            foreach ($this->archive->collection($collection) as $doc) {
                if (! $this->belongs($doc, $vehicles, $collection)) {
                    continue;
                }

                $key = "{$collection}:{$doc['_id']}";
                [$files, $links] = $this->files($doc['Files'] ?? [], $key);

                $vehicles[(int) $doc['VehicleId']]['records'][] = [
                    'key' => $key,
                    'attributes' => [
                        'category' => $category->value,
                        'date' => $this->date($doc['Date'] ?? null),
                        'mileage' => $this->mileage($doc['Mileage'] ?? null),
                        'description' => $this->str($doc['Description'] ?? null) ?? $category->getLabel(),
                        'cost' => $this->dec($doc['Cost'] ?? null) ?? '0',
                        'notes' => $this->notesWithExtras($this->str($doc['Notes'] ?? null), $this->extraFields($doc['ExtraFields'] ?? [])),
                        'tags' => $this->tags($doc['Tags'] ?? []),
                    ],
                    'files' => $files,
                    'links' => $links,
                    'supply_usage' => $usage['by_consumer'][$key] ?? [],
                ];
            }
        }
    }

    private function mapTaxes(array &$vehicles): void
    {
        foreach ($this->archive->collection('taxrecords') as $doc) {
            if (! $this->belongs($doc, $vehicles, 'taxrecords')) {
                continue;
            }

            $key = "taxrecords:{$doc['_id']}";
            [$files, $links] = $this->files($doc['Files'] ?? [], $key);
            $date = $this->date($doc['Date'] ?? null);
            $description = $this->str($doc['Description'] ?? null) ?? RecordCategory::TaxFee->getLabel();

            $vehicles[(int) $doc['VehicleId']]['records'][] = [
                'key' => $key,
                'attributes' => [
                    'category' => RecordCategory::TaxFee->value,
                    'date' => $date,
                    'mileage' => null,
                    'description' => $description,
                    'cost' => $this->dec($doc['Cost'] ?? null) ?? '0',
                    'notes' => $this->notesWithExtras($this->str($doc['Notes'] ?? null), $this->extraFields($doc['ExtraFields'] ?? [])),
                    'tags' => $this->tags($doc['Tags'] ?? []),
                ],
                'files' => $files,
                'links' => $links,
                'supply_usage' => [],
            ];

            // LubeLogger copied recurring taxes forward by itself; TPMS uses a renewal reminder instead.
            if (($doc['IsRecurring'] ?? false) && $date) {
                [$every, $unit] = $this->monthInterval($doc['RecurringInterval'] ?? 'OneYear', $doc['CustomMonthInterval'] ?? 0, $doc['CustomMonthIntervalUnit'] ?? 'Months');

                $vehicles[(int) $doc['VehicleId']]['reminders'][] = [
                    'key' => "{$key}:renewal",
                    'attributes' => [
                        'description' => __('Renew: :description', ['description' => $description]),
                        'notes' => null,
                        'due_date' => $every ? $this->addInterval($date, $every, $unit) : null,
                        'due_mileage' => null,
                        'repeat_every' => $every ?: null,
                        'repeat_unit' => $every ? $unit->value : null,
                        'repeat_distance' => null,
                        'fixed_schedule' => true,
                        'tags' => $this->tags($doc['Tags'] ?? []),
                    ],
                ];
            }
        }
    }

    private function mapFuel(array &$vehicles, array $usage): void
    {
        foreach ($this->archive->collection('gasrecords') as $doc) {
            if (! $this->belongs($doc, $vehicles, 'gasrecords')) {
                continue;
            }

            $key = "gasrecords:{$doc['_id']}";
            [$files, $links] = $this->files($doc['Files'] ?? [], $key);
            $electric = $vehicles[(int) $doc['VehicleId']]['attributes']['fuel_unit'] === 'kwh';

            $vehicles[(int) $doc['VehicleId']]['fuel'][] = [
                'key' => $key,
                'attributes' => [
                    'date' => $this->date($doc['Date'] ?? null),
                    'mileage' => $this->mileage($doc['Mileage'] ?? null),
                    'quantity' => $this->dec($doc['Gallons'] ?? null) ?? '0',
                    'cost' => $this->dec($doc['Cost'] ?? null) ?? '0',
                    'is_full' => (bool) ($doc['IsFillToFull'] ?? true),
                    'missed_previous' => (bool) ($doc['MissedFuelUp'] ?? false),
                    'soc_start' => $electric ? (int) ($doc['StartingSoc'] ?? 20) : null,
                    'soc_end' => $electric ? (int) ($doc['EndingSoc'] ?? 80) : null,
                    'notes' => $this->notesWithExtras($this->str($doc['Notes'] ?? null), $this->extraFields($doc['ExtraFields'] ?? [])),
                    'tags' => $this->tags($doc['Tags'] ?? []),
                ],
                'files' => $files,
                'links' => $links,
                'supply_usage' => $usage['by_consumer'][$key] ?? [],
            ];
        }
    }

    private function mapReminders(array &$vehicles): void
    {
        foreach ($this->archive->collection('reminderrecords') as $doc) {
            if (! $this->belongs($doc, $vehicles, 'reminderrecords')) {
                continue;
            }

            $metric = LubeLoggerEnums::metric($doc['Metric'] ?? 'Date');
            $byDate = $metric !== 'odometer';
            $byMileage = $metric !== 'date';
            $recurring = (bool) ($doc['IsRecurring'] ?? false);

            [$every, $unit] = $recurring && $byDate
                ? $this->monthInterval($doc['ReminderMonthInterval'] ?? 'OneYear', $doc['CustomMonthInterval'] ?? 0, $doc['CustomMonthIntervalUnit'] ?? 'Months')
                : [0, RepeatUnit::Month];

            $distance = 0;
            if ($recurring && $byMileage) {
                $distance = LubeLoggerEnums::mileageInterval($doc['ReminderMileageInterval'] ?? 'FiveThousandMiles')
                    ?: (int) ($doc['CustomMileageInterval'] ?? 0);
            }

            if ($doc['UseCustomThresholds'] ?? false) {
                $this->warn(__('Reminder ":reminder" had custom urgency thresholds; The Pocket Mechanic uses your personal thresholds instead.', ['reminder' => $doc['Description'] ?? '']));
            }

            $vehicles[(int) $doc['VehicleId']]['reminders'][] = [
                'key' => "reminderrecords:{$doc['_id']}",
                'attributes' => [
                    'description' => $this->str($doc['Description'] ?? null) ?? __('Reminder'),
                    'notes' => $this->str($doc['Notes'] ?? null),
                    'due_date' => $byDate ? $this->date($doc['Date'] ?? null) : null,
                    'due_mileage' => $byMileage ? $this->mileage($doc['Mileage'] ?? null) : null,
                    'repeat_every' => $every ?: null,
                    'repeat_unit' => $every ? $unit->value : null,
                    'repeat_distance' => $distance ?: null,
                    'fixed_schedule' => (bool) ($doc['FixedIntervals'] ?? false),
                    'tags' => $this->tags($doc['Tags'] ?? []),
                ],
            ];
        }
    }

    /**
     * LubeLogger saved an inspection *and* an auto-created service record that
     * links back to it. TPMS writes the inspection results into that record's notes.
     *
     * @return array<string, string> alias key => record key
     */
    private function mapInspections(array &$vehicles): array
    {
        $aliases = [];

        foreach ($this->archive->collection('inspectionrecords') as $doc) {
            if (! $this->belongs($doc, $vehicles, 'inspectionrecords')) {
                continue;
            }

            $key = "inspectionrecords:{$doc['_id']}";
            $results = $this->inspectionNotes($doc);

            $records = &$vehicles[(int) $doc['VehicleId']]['records'];
            $linked = null;
            foreach ($records as $index => $record) {
                if (collect($record['links'])->contains(fn ($link) => ($link['ref'] ?? null) === $key)) {
                    $linked = $index;
                    break;
                }
            }

            if ($linked !== null) {
                $records[$linked]['attributes']['notes'] = implode("\n\n", array_filter([$records[$linked]['attributes']['notes'], $results]));
                $records[$linked]['links'] = array_values(array_filter($records[$linked]['links'], fn ($l) => ($l['ref'] ?? null) !== $key));
                $aliases[$key] = $records[$linked]['key'];
            } else {
                [$files, $links] = $this->files($doc['Files'] ?? [], $key);
                $records[] = [
                    'key' => $key,
                    'attributes' => [
                        'category' => RecordCategory::Service->value,
                        'date' => $this->date($doc['Date'] ?? null),
                        'mileage' => $this->mileage($doc['Mileage'] ?? null),
                        'description' => $this->str($doc['Description'] ?? null) ?? __('Inspection'),
                        'cost' => $this->dec($doc['Cost'] ?? null) ?? '0',
                        'notes' => $results,
                        'tags' => $this->tags($doc['Tags'] ?? []),
                    ],
                    'files' => $files,
                    'links' => $links,
                    'supply_usage' => [],
                ];
            }
            unset($records);
        }

        return $aliases;
    }

    private function mapPlans(array &$vehicles, array $usage): void
    {
        foreach ($this->archive->collection('planrecords') as $doc) {
            if (! $this->belongs($doc, $vehicles, 'planrecords')) {
                continue;
            }

            $key = "planrecords:{$doc['_id']}";
            [$files, $links] = $this->files($doc['Files'] ?? [], $key);
            $status = LubeLoggerEnums::planStatus($doc['Progress'] ?? 'Backlog');
            $category = LubeLoggerEnums::recordCategory($doc['ImportMode'] ?? 'ServiceRecord');
            $description = $this->str($doc['Description'] ?? null) ?? __('Plan');
            $modified = $doc['DateModified'] ?? null;

            $reminders = collect($doc['ReminderRecordIds'] ?? [])->push($doc['ReminderRecordId'] ?? 0)
                ->map(fn ($id) => (int) $id)->filter()->unique()
                ->map(fn ($id) => "reminderrecords:{$id}")->values()->all();

            $vehicles[(int) $doc['VehicleId']]['plans'][] = [
                'key' => $key,
                'attributes' => [
                    'description' => $description,
                    'notes' => $this->notesWithExtras($this->str($doc['Notes'] ?? null), $this->extraFields($doc['ExtraFields'] ?? [])),
                    'category' => $category->value,
                    'priority' => LubeLoggerEnums::planPriority($doc['Priority'] ?? 'Normal')->value,
                    'status' => $status->value,
                    'cost' => $this->dec($doc['Cost'] ?? null),
                    'completed_at' => $status === PlanStatus::Done && $modified instanceof CarbonImmutable ? $modified->toIso8601String() : null,
                    'created_at' => ($doc['DateCreated'] ?? null) instanceof CarbonImmutable ? $doc['DateCreated']->toIso8601String() : null,
                    'updated_at' => $modified instanceof CarbonImmutable ? $modified->toIso8601String() : null,
                ],
                'files' => $files,
                'links' => $links,
                'reminders' => $reminders,
                // Done plans handed their supply usage to the record they created.
                'supply_usage' => $status === PlanStatus::Done ? [] : ($usage['by_consumer'][$key] ?? []),
                'completed_record' => $status === PlanStatus::Done
                    ? $this->findCompletedRecord($vehicles[(int) $doc['VehicleId']]['records'], $category, $description, $modified)
                    : null,
            ];
        }
    }

    /** LubeLogger kept no link from a done plan to its record; match by type, description and date. */
    private function findCompletedRecord(array $records, RecordCategory $category, string $description, mixed $modified): ?string
    {
        $date = $modified instanceof CarbonImmutable ? CarbonImmutable::parse($this->date($modified)) : null;

        foreach ($records as $record) {
            $attributes = $record['attributes'];
            if ($attributes['category'] !== $category->value || $attributes['description'] !== $description) {
                continue;
            }

            if ($date === null || ($attributes['date'] && abs(CarbonImmutable::parse($attributes['date'])->diffInDays($date)) <= 1)) {
                return $record['key'];
            }
        }

        return null;
    }

    /**
     * Odometer records become readings, minus the ones LubeLogger auto-inserted
     * from other entries (same mileage, same date give or take a day: a backup whose
     * server timezone isn't known exactly can put the copy a day off). Equipment
     * links on readings become install/remove periods.
     */
    private function mapOdometerAndEquipment(array &$vehicles): void
    {
        $odometer = collect($this->archive->collection('odometerrecords'))
            ->filter(fn ($doc) => $this->belongs($doc, $vehicles, 'odometerrecords'))
            ->groupBy(fn ($doc) => (int) $doc['VehicleId']);

        $equipment = collect($this->archive->collection('equipmentrecords'))
            ->filter(fn ($doc) => $this->belongs($doc, $vehicles, 'equipmentrecords'))
            ->groupBy(fn ($doc) => (int) $doc['VehicleId']);

        foreach ($vehicles as $vehicleId => &$vehicle) {
            $docs = ($odometer[$vehicleId] ?? collect())
                ->map(fn ($doc) => $doc + ['_date' => $this->date($doc['Date'] ?? null)])
                ->sortBy([['_date', 'asc'], ['Mileage', 'asc']])
                ->values();

            // Mileage => days (as day numbers) with an entry at that mileage.
            $known = collect([...$vehicle['records'], ...$vehicle['fuel']])
                ->filter(fn ($e) => $e['attributes']['mileage'] !== null)
                ->groupBy(fn ($e) => (int) $e['attributes']['mileage'])
                ->map(fn ($entries) => $entries->map(fn ($e) => intdiv(strtotime($e['attributes']['date'].' UTC'), 86400)));

            $lowest = collect([...$vehicle['records'], ...$vehicle['fuel']])->pluck('attributes.mileage')->filter()
                ->merge($docs->pluck('Mileage')->filter())->min();

            foreach ($docs as $index => $doc) {
                $mileage = $this->mileage($doc['Mileage'] ?? null);
                $notes = $this->str($doc['Notes'] ?? null);

                if ($mileage === null || $doc['_date'] === null) {
                    continue;
                }

                $autoInserted = $notes === null || str_starts_with($notes, 'Auto Insert');
                $day = intdiv(strtotime($doc['_date'].' UTC'), 86400);
                if ($autoInserted && ($known[$mileage] ?? collect())->contains(fn (int $known) => abs($known - $day) <= 1)) {
                    $vehicle['skipped']['odometer_duplicates']++;

                    continue;
                }

                // Keep the very first "initial odometer" so distance totals start at the right place.
                $initial = (int) ($doc['InitialMileage'] ?? 0);
                if ($index === 0 && $initial > 0 && $initial < $mileage && $initial <= (int) $lowest) {
                    $vehicle['odometer'][] = [
                        'key' => "odometerrecords:{$doc['_id']}:initial",
                        'attributes' => ['date' => $doc['_date'], 'mileage' => $initial, 'notes' => __('Initial odometer'), 'tags' => []],
                    ];
                }

                $vehicle['odometer'][] = [
                    'key' => "odometerrecords:{$doc['_id']}",
                    'attributes' => [
                        'date' => $doc['_date'],
                        'mileage' => $mileage,
                        'notes' => $this->notesWithExtras($notes, $this->extraFields($doc['ExtraFields'] ?? [])),
                        'tags' => $this->tags($doc['Tags'] ?? []),
                    ],
                ];
            }

            foreach ($equipment[$vehicleId] ?? [] as $doc) {
                $key = "equipmentrecords:{$doc['_id']}";
                [$files, $links] = $this->files($doc['Files'] ?? [], $key);

                $vehicle['equipment'][] = [
                    'key' => $key,
                    'attributes' => [
                        'name' => $this->str($doc['Description'] ?? null) ?? __('Equipment'),
                        'notes' => $this->notesWithExtras($this->str($doc['Notes'] ?? null), $this->extraFields($doc['ExtraFields'] ?? [])),
                        'tags' => $this->tags($doc['Tags'] ?? []),
                    ],
                    'periods' => $this->equipmentPeriods((int) $doc['_id'], (bool) ($doc['IsEquipped'] ?? false), $docs, $lowest),
                    'files' => $files,
                    'links' => $links,
                ];
            }
        }
        unset($vehicle);
    }

    private function equipmentPeriods(int $equipmentId, bool $equipped, Collection $odometer, ?int $fallbackMileage): array
    {
        $periods = [];
        $open = null;
        $last = null;

        foreach ($odometer as $doc) {
            $linked = in_array($equipmentId, array_map('intval', $doc['EquipmentRecordId'] ?? []), true);

            if ($linked && $open === null) {
                $open = ['installed_on' => $doc['_date'], 'installed_mileage' => (int) ($doc['InitialMileage'] ?? 0) ?: (int) $doc['Mileage']];
            }

            if ($linked) {
                $last = $doc;
            } elseif ($open !== null) {
                $periods[] = $open + ['removed_on' => $last['_date'], 'removed_mileage' => (int) $last['Mileage']];
                $open = null;
            }
        }

        if ($open !== null) {
            $periods[] = $equipped
                ? $open + ['removed_on' => null, 'removed_mileage' => null]
                : $open + ['removed_on' => $last['_date'], 'removed_mileage' => (int) $last['Mileage']];
        } elseif ($periods === [] && $equipped) {
            $periods[] = ['installed_on' => Clock::today()->toDateString(), 'installed_mileage' => $fallbackMileage, 'removed_on' => null, 'removed_mileage' => null];
        }

        return $periods;
    }

    private function mapNotes(array &$vehicles): void
    {
        foreach ($this->archive->collection('notes') as $doc) {
            if (! $this->belongs($doc, $vehicles, 'notes')) {
                continue;
            }

            $key = "notes:{$doc['_id']}";
            [$files, $links] = $this->files($doc['Files'] ?? [], $key);

            $vehicles[(int) $doc['VehicleId']]['notes'][] = [
                'key' => $key,
                'attributes' => [
                    'title' => $this->str($doc['Description'] ?? null) ?? __('Note'),
                    'body' => $this->notesWithExtras($this->str($doc['NoteText'] ?? null), $this->extraFields($doc['ExtraFields'] ?? [])),
                    'pinned' => (bool) ($doc['Pinned'] ?? false),
                    'tags' => $this->tags($doc['Tags'] ?? []),
                ],
                'files' => $files,
                'links' => $links,
            ];
        }
    }

    // ---------------------------------------------------------------- supplies

    /**
     * LubeLogger kept the remaining quantity on the supply and copied each usage
     * onto the consuming record. TPMS rebuilds a ledger: an opening purchase of
     * (remaining + used), then one usage per consuming entry.
     */
    private function supplyUsage(): array
    {
        $byConsumer = [];
        $bySupply = [];

        $consumers = ['servicerecords', 'collisionrecords', 'upgraderecords', 'gasrecords', 'planrecords'];
        foreach ($consumers as $collection) {
            foreach ($this->archive->collection($collection) as $doc) {
                if ($collection === 'planrecords' && LubeLoggerEnums::planStatus($doc['Progress'] ?? 'Backlog') === PlanStatus::Done) {
                    continue;
                }

                foreach ($doc['RequisitionHistory'] ?? [] as $entry) {
                    $supplyId = (int) ($entry['_id'] ?? $entry['Id'] ?? 0);
                    if ($supplyId === 0) {
                        continue;
                    }

                    $line = [
                        'supply' => "supplyrecords:{$supplyId}",
                        'quantity' => $this->dec($entry['Quantity'] ?? null) ?? '0',
                        'cost' => $this->dec($entry['Cost'] ?? null) ?? '0',
                        'date' => $this->date($entry['Date'] ?? null) ?? $this->date($doc['Date'] ?? $doc['DateModified'] ?? null),
                    ];

                    $byConsumer["{$collection}:{$doc['_id']}"][] = $line;
                    $bySupply[$supplyId][] = $line;
                }
            }
        }

        return ['by_consumer' => $byConsumer, 'by_supply' => $bySupply];
    }

    private function mapSupplies(array &$vehicles, array $usage): void
    {
        $shop = [];

        foreach ($this->archive->collection('supplyrecords') as $doc) {
            $vehicleId = (int) ($doc['VehicleId'] ?? 0);
            $key = "supplyrecords:{$doc['_id']}";

            if ($vehicleId === 0) {
                $shop[$key] = true;

                continue;
            }

            if (! isset($vehicles[$vehicleId])) {
                continue;
            }

            [$files, $links] = $this->files($doc['Files'] ?? [], $key);
            $used = $usage['by_supply'][(int) $doc['_id']] ?? [];

            $supply = [
                'key' => $key,
                'attributes' => [
                    'description' => $this->str($doc['Description'] ?? null) ?? __('Supply'),
                    'part_number' => $this->str($doc['PartNumber'] ?? null),
                    'supplier' => $this->str($doc['PartSupplier'] ?? null),
                    'notes' => $this->notesWithExtras($this->str($doc['Notes'] ?? null), $this->extraFields($doc['ExtraFields'] ?? [])),
                    'tags' => $this->tags($doc['Tags'] ?? []),
                ],
                'opening' => [
                    'date' => $this->date($doc['Date'] ?? null) ?? Clock::today()->toDateString(),
                    'quantity' => $this->sum([$doc['Quantity'] ?? '0', ...array_column($used, 'quantity')]),
                    'cost' => $this->sum([$doc['Cost'] ?? '0', ...array_column($used, 'cost')]),
                ],
                'files' => $files,
                'links' => $links,
            ];

            $vehicles[$vehicleId]['supplies'][] = $supply;
        }

        if ($shop === []) {
            return;
        }

        $this->warn(trans_choice(':count shop supply isn\'t tied to a vehicle and was left out.|:count shop supplies aren\'t tied to a vehicle and were left out.', count($shop), ['count' => count($shop)]));

        foreach ($vehicles as &$vehicle) {
            foreach (['records', 'fuel', 'plans'] as $type) {
                foreach ($vehicle[$type] as &$entry) {
                    $entry['supply_usage'] = array_values(array_filter($entry['supply_usage'] ?? [], fn (array $line) => ! isset($shop[$line['supply']])));
                }
                unset($entry);
            }
        }
        unset($vehicle);
    }

    // ------------------------------------------------------------- inspections

    /** An inspection's results as readable lines, e.g. "✗ Tyres: Worn (front left)". */
    private function inspectionNotes(array $doc): string
    {
        $lines = [__('Inspection: :name', ['name' => $this->str($doc['Description'] ?? null) ?? __('Inspection')])];

        foreach ($doc['Results'] ?? [] as $result) {
            $item = $this->inspectionResult($result);
            $value = is_array($item['value']) ? implode(', ', $item['value']) : $item['value'];

            $lines[] = ($item['failed'] ? '✗ ' : '✓ ').$item['label']
                .(filled($value) ? ': '.$value : '')
                .(filled($item['notes']) ? ' ('.$item['notes'].')' : '');
        }

        return implode("\n", $lines);
    }

    private function inspectionResult(array $result): array
    {
        $type = $this->inspectionFieldType($result['FieldType'] ?? 'Text');
        $values = $result['Values'] ?? [];
        $selected = array_values(array_map(fn ($v) => (string) ($v['Description'] ?? ''), array_filter($values, fn ($v) => (bool) ($v['IsSelected'] ?? false))));

        return [
            'label' => (string) ($result['Description'] ?? ''),
            'value' => match ($type) {
                'text' => $this->str($result['Notes'] ?? null),
                'radio' => $selected[0] ?? null,
                default => $selected,
            },
            'notes' => $type === 'text' ? null : $this->str($result['Notes'] ?? null),
            'failed' => (bool) ($result['Failed'] ?? false),
        ];
    }

    private function inspectionFieldType(mixed $type): string
    {
        return match ($type) {
            'Check', 1, '1' => 'check',
            'Radio', 2, '2' => 'radio',
            default => 'text',
        };
    }

    // ------------------------------------------------------------------- users

    /** @return array<string, array> keyed "root" and LubeLogger user ids */
    private function mapUsers(): array
    {
        $configs = collect($this->archive->collection('userconfigrecords'))
            ->mapWithKeys(fn ($doc) => [(int) $doc['_id'] => $doc['UserConfig'] ?? []]);

        $users = ['root' => ['action' => 'map', 'user_id' => (int) ($this->options['target_user_id'] ?? 0), 'label' => __('LubeLogger root user')]];

        foreach ($this->archive->collection('userrecords') as $doc) {
            $id = (int) $doc['_id'];
            $choice = Arr::get($this->options, "users.{$id}", ['action' => 'create', 'user_id' => null]);
            $password = strtolower((string) ($doc['Password'] ?? ''));

            $users[(string) $id] = [
                'action' => $choice['action'] ?? 'create',
                'user_id' => isset($choice['user_id']) ? (int) $choice['user_id'] : null,
                'label' => (string) ($doc['UserName'] ?? "#{$id}"),
                'attributes' => [
                    'name' => (string) ($doc['UserName'] ?? "user{$id}"),
                    'username' => (string) ($doc['UserName'] ?? "user{$id}"),
                    'email' => $this->str($doc['EmailAddress'] ?? null),
                    'is_admin' => (bool) ($doc['IsAdmin'] ?? false),
                    'legacy_sha256' => preg_match('/^[0-9a-f]{64}$/', $password) ? $password : null,
                ],
                'settings' => isset($configs[$id]) ? $this->settingsFrom($configs[$id], false) : $this->settingsFrom($this->archive->userConfig(), false),
            ];

            if (($users[(string) $id]['attributes']['legacy_sha256'] ?? null) === null && ($users[(string) $id]['action'] === 'create')) {
                $this->warn(__('User :user has no LubeLogger password (single sign-on?); set one in Users after the import.', ['user' => $users[(string) $id]['label']]));
            }
        }

        return $users;
    }

    /** Root sees every vehicle; others keep their direct access plus their households' vehicles. */
    private function assignAccess(array &$vehicles, array $users): void
    {
        $direct = collect($this->archive->collection('useraccessrecords'))
            ->map(fn ($doc) => $doc['_id'] ?? [])
            ->filter(fn ($id) => isset($id['UserId'], $id['VehicleId']));

        $households = collect($this->archive->collection('userhouseholdrecords'))
            ->map(fn ($doc) => $doc['_id'] ?? [])
            ->filter(fn ($id) => isset($id['ParentUserId'], $id['ChildUserId']));

        foreach ($vehicles as $vehicleId => &$vehicle) {
            $owners = $direct->where('VehicleId', $vehicleId)->pluck('UserId')->map(fn ($id) => (int) $id);
            $children = $households->whereIn('ParentUserId', $owners->all())->pluck('ChildUserId')->map(fn ($id) => (int) $id);

            $vehicle['users'] = collect(['root'])
                ->merge($owners->merge($children)->map(fn ($id) => (string) $id))
                ->unique()
                ->filter(fn ($ref) => isset($users[$ref]) && $users[$ref]['action'] !== 'skip')
                ->values()->all();
        }
        unset($vehicle);
    }

    private function settingsFrom(array $config, bool $root): array
    {
        $server = $this->archive->serverConfig();
        $thresholds = $server['ReminderUrgencyConfig'] ?? $config['ReminderUrgencyConfig'] ?? null;
        $settings = [];

        $economy = strtolower((string) ($config['PreferredGasMileageUnit'] ?? ''));
        $settings['economy_format'] = match ($economy) {
            'km/l' => 'km_per_l',
            'l/100km' => 'l_per_100km',
            default => 'auto',
        };

        if (is_array($thresholds)) {
            $settings['urgent_days'] = (int) ($thresholds['UrgentDays'] ?? 30);
            $settings['very_urgent_days'] = (int) ($thresholds['VeryUrgentDays'] ?? 7);
            $settings['urgent_distance'] = (int) ($thresholds['UrgentDistance'] ?? 100);
            $settings['very_urgent_distance'] = (int) ($thresholds['VeryUrgentDistance'] ?? 50);
        }

        return $settings;
    }

    // ------------------------------------------------------------ extra fields

    /** LubeLogger's custom field values; TPMS keeps them in the entry's notes. @return array<string, string> */
    private function extraFields(array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $name = trim((string) ($field['Name'] ?? ''));
            $value = $this->str(is_scalar($field['Value'] ?? null) ? (string) $field['Value'] : null);

            if ($name !== '' && $value !== null) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /** @param  array<string, string>  $extra */
    private function notesWithExtras(?string $notes, array $extra): ?string
    {
        $lines = array_map(fn ($name, $value) => "{$name}: {$value}", array_keys($extra), $extra);

        return implode("\n\n", array_filter([$notes, implode("\n", $lines)])) ?: null;
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: list<array{path: string, name: string}>, 1: list<array>} */
    private function files(array $files, string $context): array
    {
        $stored = [];
        $links = [];

        foreach ($files as $file) {
            $location = trim((string) ($file['Location'] ?? ''));
            $name = trim((string) ($file['Name'] ?? '')) ?: basename($location);

            if ($location === '' || str_starts_with($location, '/defaults/')) {
                continue;
            }

            if ($record = LubeLoggerEnums::parseRecordLink($location)) {
                $links[] = ['name' => $name, 'ref' => "{$record[0]}:{$record[1]}"];
            } elseif (preg_match('#^/?(images|documents)/#', $location)) {
                $path = $this->archive->file($location);
                $path
                    ? $stored[] = ['path' => $path, 'name' => $name]
                    : $this->warn(__('Attachment ":name" (:context) is not in the backup.', ['name' => $name, 'context' => $context]));
            } elseif (str_starts_with($location, '/temp/')) {
                $this->warn(__('Attachment ":name" (:context) was never saved in LubeLogger and is not in the backup.', ['name' => $name, 'context' => $context]));
            } else {
                $links[] = ['name' => $name, 'url' => $location];
            }
        }

        return [$stored, $links];
    }

    private function belongs(array $doc, array $vehicles, string $collection): bool
    {
        $vehicleId = (int) ($doc['VehicleId'] ?? 0);

        if (isset($vehicles[$vehicleId])) {
            return true;
        }

        if (! $this->vehicles->has($vehicleId) && ! collect($this->archive->collection('vehicles'))->contains(fn ($v) => (int) $v['_id'] === $vehicleId)) {
            $this->warn(__('Skipped :collection entries for vehicle #:id, which no longer exists.', ['collection' => $collection, 'id' => $vehicleId]));
        }

        return false;
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof CarbonImmutable) {
            return $value->setTimezone($this->timezone)->toDateString();
        }

        return is_string($value) ? DateParser::parse($value, $this->dateOrder) : null;
    }

    private function cultureDate(mixed $value, string $context): ?string
    {
        $value = is_string($value) ? trim($value) : null;
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = DateParser::parse($value, $this->dateOrder);
        if ($parsed === null) {
            $this->warn(__(':context ":value" could not be read and was left empty.', ['context' => $context, 'value' => $value]));
        }

        return $parsed;
    }

    /** @return array{0: int, 1: RepeatUnit} */
    private function monthInterval(mixed $interval, mixed $custom, mixed $customUnit): array
    {
        $months = LubeLoggerEnums::monthInterval($interval);

        if ($months === 0) {
            $unit = LubeLoggerEnums::intervalUnit($customUnit);

            return [(int) $custom, $unit];
        }

        return $months % 12 === 0 ? [intdiv($months, 12), RepeatUnit::Year] : [$months, RepeatUnit::Month];
    }

    private function addInterval(string $date, int $every, RepeatUnit $unit): string
    {
        $date = CarbonImmutable::parse($date);

        return Reminders::addInterval($date, $every, $unit)->toDateString();
    }

    private function mileage(mixed $value): ?int
    {
        $value = (int) ($value ?? 0);

        return $value > 0 ? $value : null;
    }

    private function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function dec(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 3, '.', '');
    }

    private function positive(mixed $value): ?string
    {
        $value = $this->dec($value);

        return $value !== null && (float) $value > 0 ? $value : null;
    }

    private function sum(array $values): string
    {
        return array_reduce($values, fn ($carry, $v) => bcadd($carry, $this->dec($v) ?? '0', 3), '0');
    }

    private function tags(mixed $tags): array
    {
        return collect(is_array($tags) ? $tags : [])->map(fn ($t) => trim((string) $t))->filter()->unique()->values()->all();
    }

    private function sourceCounts(): array
    {
        $counts = [];
        foreach (array_keys($this->archive->reader()->collections()) as $collection) {
            $counts[$collection] = $this->archive->count($collection);
        }

        return $counts;
    }

    private function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
