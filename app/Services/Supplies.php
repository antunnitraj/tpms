<?php

namespace App\Services;

use App\Enums\SupplyMovementType;
use App\Models\Supply;
use App\Models\SupplyMovement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Stock is a ledger: purchases add, usage subtracts at the current average unit cost. */
class Supplies
{
    public static function purchase(Supply $supply, float|string $quantity, float|string $cost, CarbonInterface $date, ?string $note = null): SupplyMovement
    {
        return $supply->movements()->create([
            'date' => $date->toDateString(),
            'type' => SupplyMovementType::Purchase,
            'quantity' => static::decimal($quantity),
            'cost' => static::decimal($cost),
            'note' => $note,
        ]);
    }

    public static function adjust(Supply $supply, float|string $quantity, float|string $cost, CarbonInterface $date, ?string $note = null): SupplyMovement
    {
        return $supply->movements()->create([
            'date' => $date->toDateString(),
            'type' => SupplyMovementType::Adjust,
            'quantity' => static::decimal($quantity),
            'cost' => static::decimal($cost),
            'note' => $note,
        ]);
    }

    /** Use part of a supply for an entry; returns the (negative) movement. */
    public static function use(Supply $supply, float|string $quantity, Model $consumer, CarbonInterface $date, ?SupplyMovement $replacing = null): SupplyMovement
    {
        $quantity = static::decimal($quantity);
        $available = bcadd(static::decimal($supply->movements()->sum('quantity')), $replacing ? static::decimal(-$replacing->quantity) : '0', 3);

        if (bccomp($quantity, '0', 3) <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Quantity must be greater than zero.')]);
        }

        if (bccomp($quantity, $available, 3) > 0) {
            throw ValidationException::withMessages(['quantity' => __('Only :available in stock.', ['available' => (float) $available])]);
        }

        return $supply->movements()->create([
            'date' => $date->toDateString(),
            'type' => SupplyMovementType::Use,
            'quantity' => bcmul($quantity, '-1', 3),
            'cost' => bcmul(static::costFor($supply, $quantity), '-1', 3),
            'consumer_type' => $consumer->getMorphClass(),
            'consumer_id' => $consumer->getKey(),
        ]);
    }

    /** Cost of using a quantity at the supply's current average unit cost. */
    public static function costFor(Supply $supply, float|string $quantity): string
    {
        $stock = static::decimal($supply->movements()->sum('quantity'));
        $value = static::decimal($supply->movements()->sum('cost'));

        if (bccomp($stock, '0', 3) <= 0) {
            return '0.000';
        }

        return static::round(bcdiv(bcmul($value, static::decimal($quantity), 6), $stock, 6));
    }

    public static function decimal(float|int|string|null $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private static function round(string $value): string
    {
        return number_format(round((float) $value, 3), 3, '.', '');
    }
}
