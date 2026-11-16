<?php

namespace App\Import\LubeLogger;

use App\Enums\DistanceUnit;
use App\Enums\FuelUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;

/** Works out sensible import options for a backup; everything can be overridden in the preview. */
class Analyzer
{
    private const DATED_COLLECTIONS = [
        'servicerecords', 'collisionrecords', 'upgraderecords', 'gasrecords', 'taxrecords',
        'odometerrecords', 'reminderrecords', 'supplyrecords', 'inspectionrecords',
    ];

    public function __construct(private BackupArchive $archive) {}

    public function defaultOptions(User $actor): array
    {
        return [
            'timezone' => $this->detectTimezone(),
            'date_order' => $this->detectDateOrder(),
            'target_user_id' => $actor->getKey(),
            'users' => $this->defaultUserActions(),
            'vehicles' => $this->defaultVehicleOptions(),
        ];
    }

    /**
     * LubeLogger saved dates as local midnight converted to UTC, so the zone in
     * which most dates fall on midnight is the server's zone.
     */
    public function detectTimezone(): string
    {
        $dates = [];
        foreach (self::DATED_COLLECTIONS as $collection) {
            foreach ($this->archive->reader()->documents($collection) as $document) {
                if (($document['Date'] ?? null) instanceof CarbonImmutable) {
                    $dates[] = $document['Date'];
                }
            }
        }

        if ($dates === []) {
            return config('app.timezone');
        }

        $midnights = fn (string $zone) => count(array_filter($dates, fn (CarbonImmutable $d) => $d->setTimezone($zone)->format('H:i:s') === '00:00:00'));

        $utc = $midnights('UTC');
        if ($utc >= count($dates) * 0.9) {
            return 'UTC';
        }

        $best = ['UTC', $utc];
        $candidates = array_unique([config('app.timezone'), ...DateTimeZone::listIdentifiers()]);
        foreach ($candidates as $zone) {
            $hits = $midnights($zone);
            if ($hits > $best[1]) {
                $best = [$zone, $hits];
            }
        }

        return $best[0];
    }

    public function detectDateOrder(): string
    {
        $samples = $this->cultureDateSamples();
        $server = $this->archive->serverConfig();
        $locale = $server['LUBELOGGER_LOCALE_DT_OVERRIDE'] ?? $server['LUBELOGGER_LOCALE_OVERRIDE'] ?? null;

        return DateParser::detectOrder($samples, is_string($locale) ? $locale : null);
    }

    /** @return list<string> */
    public function cultureDateSamples(): array
    {
        $samples = [];
        foreach ($this->archive->collection('vehicles') as $vehicle) {
            foreach (['PurchaseDate', 'SoldDate'] as $field) {
                if (is_string($vehicle[$field] ?? null) && trim($vehicle[$field]) !== '') {
                    $samples[] = trim($vehicle[$field]);
                }
            }
        }

        foreach (['planrecordtemplates' => 'DateCreated', 'inspectionrecordtemplates' => 'Date'] as $collection => $field) {
            foreach ($this->archive->reader()->documents($collection) as $document) {
                if (is_string($document[$field] ?? null) && trim($document[$field]) !== '') {
                    $samples[] = trim($document[$field]);
                }
            }
        }

        return array_values(array_unique($samples));
    }

    /** Existing users with the same username or email are mapped; everyone else is created. */
    public function defaultUserActions(): array
    {
        $actions = [];

        foreach ($this->archive->collection('userrecords') as $lubeUser) {
            $existing = User::query()
                ->whereRaw('lower(username) = ?', [mb_strtolower((string) ($lubeUser['UserName'] ?? ''))])
                ->when(filled($lubeUser['EmailAddress'] ?? null), fn ($q) => $q->orWhereRaw('lower(email) = ?', [mb_strtolower($lubeUser['EmailAddress'])]))
                ->first();

            $actions[(string) $lubeUser['_id']] = $existing
                ? ['action' => 'map', 'user_id' => $existing->getKey()]
                : ['action' => 'create', 'user_id' => null];
        }

        return $actions;
    }

    public function defaultVehicleOptions(): array
    {
        $rootConfig = $this->archive->userConfig();
        $userConfigs = collect($this->archive->collection('userconfigrecords'))
            ->mapWithKeys(fn ($doc) => [(int) $doc['_id'] => $doc['UserConfig'] ?? []]);
        $access = collect($this->archive->collection('useraccessrecords'))
            ->map(fn ($doc) => $doc['_id'] ?? [])
            ->groupBy('VehicleId');

        $options = [];
        foreach ($this->archive->collection('vehicles') as $vehicle) {
            $firstUser = $access->get($vehicle['_id'])?->first()['UserId'] ?? null;
            $config = $firstUser !== null ? ($userConfigs[$firstUser] ?? $rootConfig) : $rootConfig;

            $options[(string) $vehicle['_id']] = ['import' => true] + static::unitsFor($vehicle, $config);
        }

        return $options;
    }

    /** LubeLogger stores raw numbers; the units come from the owner's MPG settings. */
    public static function unitsFor(array $vehicle, array $config): array
    {
        $useMpg = (bool) ($config['UseMPG'] ?? true);
        $useUkMpg = (bool) ($config['UseUKMPG'] ?? false);

        $distance = match (true) {
            (bool) ($vehicle['UseHours'] ?? false) => DistanceUnit::Hours,
            $useMpg || $useUkMpg => DistanceUnit::Miles,
            default => DistanceUnit::Kilometers,
        };

        $fuel = match (true) {
            (bool) ($vehicle['IsElectric'] ?? false) => FuelUnit::KilowattHours,
            $useMpg && ! $useUkMpg => FuelUnit::UsGallons,
            default => FuelUnit::Liters,
        };

        return ['distance_unit' => $distance->value, 'fuel_unit' => $fuel->value];
    }
}
