<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class JournalEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'entry_number',
        'entry_date',
        'reference',
        'description',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
    ];

    /**
     * Generate the next sequential journal entry number for a restaurant.
     *
     * Numbers are numeric and zero-padded to two digits (01, 02, ... 100).
     * Callers must lock the restaurant row inside a transaction so two
     * concurrent postings cannot receive the same number.
     */
    public static function nextEntryNumber(int $restaurantId): string
    {
        $query = static::withTrashed()->where('restaurant_id', $restaurantId);
        if (DB::connection()->getDriverName() === 'sqlite') {
            $query->where('entry_number', '<>', '')
                ->whereRaw("entry_number NOT GLOB '*[^0-9]*'")
                ->selectRaw('MAX(CAST(entry_number AS INTEGER)) AS max_number');
        } else {
            $query->whereRaw("entry_number REGEXP '^[0-9]+$'")
                ->selectRaw('MAX(CAST(entry_number AS UNSIGNED)) AS max_number');
        }
        $maxNumber = (int) $query->value('max_number');

        return str_pad((string) ($maxNumber + 1), 2, '0', STR_PAD_LEFT);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }
}
