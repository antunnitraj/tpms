<?php

use App\Import\LubeLogger\LiteDb\BsonReader;
use App\Import\LubeLogger\LiteDb\LiteDbReader;
use Carbon\CarbonImmutable;
use Tests\Support\LiteDbFixtureWriter;
use Tests\Support\NetDecimal;

it('decodes LiteDB BSON values', function () {
    $doc = BsonReader::decode(LiteDbFixtureWriter::bson([
        '_id' => 7,
        'Name' => 'Citroën',
        'Big' => 5_000_000_000,
        'Ratio' => 1.5,
        'Missing' => null,
        'Full' => true,
        'Cost' => new NetDecimal('1015.20'),
        'Refund' => new NetDecimal('-0.005'),
        'Huge' => new NetDecimal('79228162514264337593543950335'),
        'Date' => CarbonImmutable::parse('2025-08-29 00:00:00', 'UTC'),
        'Tags' => ['a', 'b'],
        'Nested' => ['_id' => ['UserId' => 1, 'VehicleId' => 2]],
    ]));

    expect($doc)->toMatchArray([
        '_id' => 7, 'Name' => 'Citroën', 'Big' => 5_000_000_000, 'Ratio' => 1.5, 'Missing' => null, 'Full' => true,
        'Cost' => '1015.20', 'Refund' => '-0.005', 'Huge' => '79228162514264337593543950335',
        'Tags' => ['a', 'b'], 'Nested' => ['_id' => ['UserId' => 1, 'VehicleId' => 2]],
    ])->and($doc['Date']->toIso8601String())->toBe('2025-08-29T00:00:00+00:00');
});

it('reads documents across pages, chained blocks and deleted slots', function () {
    $path = tempnam(sys_get_temp_dir(), 'litedb');
    $writer = (new LiteDbFixtureWriter)
        ->add('vehicles', ['_id' => 1, 'Make' => 'Toyota'])
        ->addDeleted('vehicles')
        ->add('vehicles', ['_id' => 2, 'Make' => 'Citroën', 'Notes' => str_repeat('long text ', 3000)])
        ->add('gasrecords', ['_id' => 1, 'VehicleId' => 2, 'Gallons' => new NetDecimal('41.5')]);

    for ($i = 3; $i < 400; $i++) {
        $writer->add('vehicles', ['_id' => $i, 'Make' => 'Fleet', 'Model' => "#{$i}"]);
    }
    $writer->write($path, maxBlockData: 5000);

    $reader = new LiteDbReader($path);
    $vehicles = collect($reader->all('vehicles'))->keyBy('_id');

    expect(array_keys($reader->collections()))->toBe(['vehicles', 'gasrecords'])
        ->and($vehicles)->toHaveCount(399)
        ->and($vehicles[2]['Notes'])->toBe(str_repeat('long text ', 3000))
        ->and($reader->all('gasrecords')[0]['Gallons'])->toBe('41.5')
        ->and($reader->all('missing'))->toBe([]);

    unlink($path);
});

it('rejects files that are not LiteDB v5', function () {
    $path = tempnam(sys_get_temp_dir(), 'litedb');
    file_put_contents($path, str_repeat("\x01", 8192));

    expect(fn () => new LiteDbReader($path))->toThrow(RuntimeException::class);
    unlink($path);
});
