<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TokenData extends Model
{
    use HasFactory;

    protected $table = 'token_data';

    protected $fillable = [
        'user_id',
        'time_slot_id',
        'date',
        'time_slot',
        'entries',
        'counts',
        'saved_at',
    ];

    protected $casts = [
        'date' => 'date',
        'time_slot' => 'string',
        'entries' => 'array',
        'counts' => 'array',
        'saved_at' => 'datetime',
    ];

    /**
     * Get entries for a specific date
     */
    public static function getByDate(string $date)
    {
        return self::where('date', $date)
            ->orderBy('time_slot')
            ->get();
    }

    /**
     * Get entries for a specific date range
     */
    public static function getByDateRange(string $startDate, string $endDate)
    {
        return self::whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->orderBy('time_slot')
            ->get();
    }

    /**
     * Check if time slot already exists
     */
    public static function timeSlotExists(string $timeSlotId): bool
    {
        return self::where('time_slot_id', $timeSlotId)->exists();
    }

    /**
     * Get the user that owns the token data.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter by user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
