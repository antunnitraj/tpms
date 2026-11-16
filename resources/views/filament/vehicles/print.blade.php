{{-- The printed report. Hidden on screen; when printing it replaces the whole page (see theme.css). --}}
@php
    /** @var \App\Models\Vehicle $vehicle */
    $summary = $printout['summary'];
    $history = $printout['history'];
    $fuel = $printout['fuel'];
    $distanceUnit = $vehicle->distanceUnit();
    $names = \App\Services\VehicleStats::costCategories();
    $included = array_map(fn ($key) => $names[$key], $categories);
    $hasRecordCategories = array_diff($categories, ['fuel']) !== [];
    $costTotal = $breakdown['total']['total'];

    $ownership = match (true) {
        $vehicle->purchase_date && $vehicle->sold_date => __('Owned :from – :to', ['from' => $vehicle->purchase_date->toFormattedDateString(), 'to' => $vehicle->sold_date->toFormattedDateString()]),
        (bool) $vehicle->purchase_date => __('Owned since :date', ['date' => $vehicle->purchase_date->toFormattedDateString()]),
        default => null,
    };
@endphp

<article
    class="tpms-print"
    data-title="{{ $vehicle->display_name }} — {{ __('Vehicle report') }}{{ $year ? ' '.$year : '' }}"
    x-data="{ screenTitle: null }"
    x-on:beforeprint.window="screenTitle = document.title; document.title = $el.dataset.title"
    x-on:afterprint.window="if (screenTitle) document.title = screenTitle"
>
    <header>
        <div class="tpms-print-kicker">
            <span>{{ __('Vehicle report') }}</span>
            <span>{{ now()->toFormattedDateString() }}</span>
        </div>

        <div class="tpms-print-title">
            <div>
                <h1>{{ $vehicle->display_name }}</h1>
                <p>
                    {{ collect([
                        $vehicle->display_name !== $vehicle->full_name ? $vehicle->full_name : null,
                        $vehicle->fuel_type?->getLabel(),
                        $ownership,
                    ])->filter()->implode(' · ') }}
                </p>
            </div>
            @if (filled($vehicle->license_plate))
                <div class="tpms-print-plate">{{ $vehicle->license_plate }}</div>
            @endif
        </div>

        <dl class="tpms-print-meta">
            <div>
                <dt>{{ __('Period') }}</dt>
                <dd>{{ $year ?: __('All time') }}</dd>
            </div>
            <div>
                <dt>{{ __('Includes') }}</dt>
                <dd>{{ count($included) === count($names) ? __('All costs') : (implode(', ', $included) ?: __('No costs')) }}</dd>
            </div>
        </dl>
    </header>

    <section class="tpms-print-stats">
        <div>
            <span>{{ __('Odometer') }}</span>
            <strong>{{ \App\Support\Format::distance($summary['odometer'], $distanceUnit) }}</strong>
        </div>
        <div>
            <span>{{ $year ? __('Driven in :year', ['year' => $year]) : __('Distance tracked') }}</span>
            <strong>{{ \App\Support\Format::distance($summary['distance'], $distanceUnit) }}</strong>
        </div>
        <div>
            <span>{{ __('Total cost') }}</span>
            <strong>{{ \App\Support\Format::money($summary['total_cost']) }}</strong>
            @if ($summary['cost_per_day'])
                <small>{{ __(':amount per day', ['amount' => \App\Support\Format::money($summary['cost_per_day'])]) }}</small>
            @endif
        </div>
        <div>
            <span>{{ __('Cost per :unit', ['unit' => $distanceUnit->short()]) }}</span>
            <strong>{{ $summary['cost_per_distance'] !== null ? \App\Support\Format::money($summary['cost_per_distance']) : '—' }}</strong>
        </div>
    </section>

    @if ($categories !== [])
        <section>
            <h2>{{ __('Costs') }}</h2>
            <table>
                <thead>
                    <tr>
                        <th>{{ __('Category') }}</th>
                        <th class="tpms-print-share-col">{{ __('Share') }}</th>
                        <th class="num">{{ __('Per day') }}</th>
                        <th class="num">{{ __('Per :unit', ['unit' => $distanceUnit->short()]) }}</th>
                        <th class="num">{{ __('Total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($breakdown['rows'] as $row)
                        @php($share = $costTotal > 0 ? $row['total'] / $costTotal * 100 : 0)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="tpms-print-share-col">
                                <span class="tpms-print-share"><span style="width: {{ round($share, 1) }}%"></span></span>
                                {{ \Illuminate\Support\Number::percentage($share) }}
                            </td>
                            <td class="num">{{ $row['per_day'] !== null ? \App\Support\Format::money($row['per_day']) : '—' }}</td>
                            <td class="num">{{ $row['per_distance'] !== null ? \App\Support\Format::money($row['per_distance']) : '—' }}</td>
                            <td class="num">{{ \App\Support\Format::money($row['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2">{{ __('Total') }}</th>
                        <td class="num">{{ $breakdown['total']['per_day'] !== null ? \App\Support\Format::money($breakdown['total']['per_day']) : '—' }}</td>
                        <td class="num">{{ $breakdown['total']['per_distance'] !== null ? \App\Support\Format::money($breakdown['total']['per_distance']) : '—' }}</td>
                        <td class="num">{{ \App\Support\Format::money($costTotal) }}</td>
                    </tr>
                </tfoot>
            </table>
        </section>
    @endif

    @if ($fuel)
        <section>
            <h2>{{ __('Fuel') }}</h2>
            <dl class="tpms-print-figures">
                <div><dt>{{ __('Fill-ups') }}</dt><dd>{{ \Illuminate\Support\Number::format($fuel['count']) }}</dd></div>
                <div><dt>{{ __('Quantity') }}</dt><dd>{{ \App\Support\Format::quantity($fuel['quantity'], $vehicle->fuelUnit()) }}</dd></div>
                <div><dt>{{ __('Average price') }}</dt><dd>{{ $fuel['unit_price'] !== null ? \App\Support\Format::money($fuel['unit_price']).'/'.$vehicle->fuelUnit()->short() : '—' }}</dd></div>
                <div><dt>{{ __('Average economy') }}</dt><dd>{{ \App\Support\Format::economy($fuel['economy']) }}</dd></div>
                <div><dt>{{ __('Fuel cost') }}</dt><dd>{{ \App\Support\Format::money($fuel['cost']) }}</dd></div>
            </dl>
        </section>
    @endif

    @if ($hasRecordCategories)
        <section>
            <h2>
                {{ __('History') }}
                <span>{{ trans_choice(':count entry|:count entries', $history->flatten()->count(), ['count' => $history->flatten()->count()]) }}</span>
            </h2>

            @if ($history->isEmpty())
                <p class="tpms-print-empty">{{ __('No entries for this period.') }}</p>
            @else
                <table class="tpms-print-history">
                    <thead>
                        <tr>
                            <th>{{ __('Date') }}</th>
                            <th>{{ __('Type') }}</th>
                            <th>{{ __('Description') }}</th>
                            <th class="num">{{ __('Odometer') }}</th>
                            <th class="num">{{ __('Cost') }}</th>
                        </tr>
                    </thead>
                    @foreach ($history as $historyYear => $records)
                        <tbody>
                            @if ($history->count() > 1)
                                <tr class="tpms-print-year">
                                    <th colspan="4">{{ $historyYear }}</th>
                                    <td class="num">{{ \App\Support\Format::money($records->sum(fn ($record) => (float) $record->cost)) }}</td>
                                </tr>
                            @endif
                            @foreach ($records as $record)
                                <tr>
                                    <td class="nowrap">{{ $record->date->toFormattedDateString() }}</td>
                                    <td><span class="tpms-print-tag">{{ $record->category->getLabel() }}</span></td>
                                    <td>
                                        <div class="tpms-print-description">{{ $record->description }}</div>
                                        @if ($record->supplyUsages->isNotEmpty())
                                            <div class="tpms-print-note">{{ __('Parts: :parts', ['parts' => $record->supplyUsages->map(fn ($usage) => abs((float) $usage->quantity).' × '.$usage->supply?->description)->implode(', ')]) }}</div>
                                        @endif
                                        @if (filled($record->notes))
                                            <div class="tpms-print-note">{{ $record->notes }}</div>
                                        @endif
                                    </td>
                                    <td class="num">{{ $record->mileage !== null ? \App\Support\Format::distance($record->mileage, $distanceUnit) : '' }}</td>
                                    <td class="num">{{ \App\Support\Format::money($record->cost) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                    <tfoot>
                        <tr>
                            <th colspan="4">{{ __('Total') }}</th>
                            <td class="num">{{ \App\Support\Format::money($history->flatten()->sum(fn ($record) => (float) $record->cost)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endif
        </section>
    @endif

    <footer class="tpms-print-foot">
        <span>{{ $vehicle->display_name }}{{ filled($vehicle->license_plate) ? ' · '.$vehicle->license_plate : '' }}</span>
        <span>{{ __('Generated by The Pocket Mechanic on :date', ['date' => now()->toFormattedDateString()]) }}</span>
    </footer>
</article>
