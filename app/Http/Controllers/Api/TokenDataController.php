<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TokenData;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Carbon\Carbon;

class TokenDataController extends Controller
{
    /**
     * Save token data to database
     */
    public function store(Request $request): JsonResponse
    {
        // Authenticate user - try access token first, then refresh token
        $user = null;
        $authHeader = $request->header('Authorization');
        
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            
            try {
                // Try to authenticate with the token (works for both access and refresh tokens)
                JWTAuth::setToken($token);
                $user = JWTAuth::authenticate();
            } catch (JWTException $e) {
                // Token invalid or expired
                Log::warning('⚠️ Token authentication failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - Invalid or expired token',
            ], 401);
        }
        
        // Debug: Log incoming request
        Log::info('🔵 TokenData API Request Received', [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_id' => $user->id,
            'user_email' => $user->email ?? 'N/A',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'has_keepalive' => $request->header('Keep-Alive') ? 'Yes' : 'No',
            'time_slot_id' => $request->input('timeSlotId'),
            'time_slot' => $request->input('timeSlot'),
            'date' => $request->input('date'),
            'entries_count' => is_array($request->input('entries')) ? count($request->input('entries')) : 0,
            'timestamp' => now()->toDateTimeString(),
        ]);

        $validator = Validator::make($request->all(), [
            'timeSlotId' => 'required|string|max:50',
            'date' => 'required|date',
            'timeSlot' => ['required', 'string', 'regex:/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/'],
            'entries' => 'required|array',
            'entries.*.number' => 'required|integer|min:0|max:9',
            'entries.*.quantity' => 'required|integer|min:1',
            'entries.*.timestamp' => 'required|integer',
            'counts' => 'required|array',
            'counts.*' => 'integer|min:0',
            'timestamp' => 'required|string',
        ]);

        if ($validator->fails()) {
            Log::warning('❌ TokenData Validation Failed', [
                'user_id' => auth()->id(),
                'errors' => $validator->errors()->toArray(),
                'input' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Check if time slot already exists for this user
            $existingTokenData = TokenData::where('time_slot_id', $request->timeSlotId)
                ->where('user_id', $user->id)
                ->first();

            if ($existingTokenData) {
                // Time slot exists - merge new entries with existing entries
                Log::info('🔄 TokenData Time Slot Exists - Merging Entries', [
                    'user_id' => $user->id,
                    'time_slot_id' => $request->timeSlotId,
                    'existing_entries_count' => count($existingTokenData->entries ?? []),
                    'new_entries_count' => count($request->entries),
                ]);

                // Merge entries: combine existing and new entries
                $existingEntries = $existingTokenData->entries ?? [];
                $newEntries = $request->entries;
                
                // Merge entries (avoid duplicates based on timestamp)
                $mergedEntries = $existingEntries;
                foreach ($newEntries as $newEntry) {
                    // Check if entry with same timestamp already exists
                    $exists = false;
                    foreach ($mergedEntries as $existingEntry) {
                        if (isset($existingEntry['timestamp']) && isset($newEntry['timestamp']) &&
                            $existingEntry['timestamp'] === $newEntry['timestamp']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $mergedEntries[] = $newEntry;
                    }
                }

                // Recalculate counts based on merged entries
                $mergedCounts = [];
                for ($i = 0; $i < 10; $i++) {
                    $mergedCounts[$i] = 0;
                }
                foreach ($mergedEntries as $entry) {
                    if (isset($entry['number']) && isset($entry['quantity'])) {
                        $number = (int)$entry['number'];
                        $quantity = (int)$entry['quantity'];
                        if ($number >= 0 && $number <= 9) {
                            $mergedCounts[$number] += $quantity;
                        }
                    }
                }

                // Update existing record
                $existingTokenData->update([
                    'entries' => $mergedEntries,
                    'counts' => $mergedCounts,
                    'saved_at' => now(),
                ]);

                Log::info('✅ TokenData Updated Successfully (Merged)', [
                    'user_id' => $user->id,
                    'token_data_id' => $existingTokenData->id,
                    'time_slot_id' => $request->timeSlotId,
                    'time_slot' => $request->timeSlot,
                    'date' => $request->date,
                    'total_entries_count' => count($mergedEntries),
                    'new_entries_added' => count($mergedEntries) - count($existingEntries),
                    'saved_at' => $existingTokenData->saved_at->toDateTimeString(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Token data updated successfully (merged with existing entries)',
                    'data' => [
                        'id' => $existingTokenData->id,
                        'time_slot_id' => $existingTokenData->time_slot_id,
                        'saved_at' => $existingTokenData->saved_at,
                        'total_entries' => count($mergedEntries),
                        'new_entries_added' => count($mergedEntries) - count($existingEntries),
                    ],
                ], 200);
            }

            // Time slot doesn't exist - create new record
            $tokenData = TokenData::create([
                'user_id' => $user->id,
                'time_slot_id' => $request->timeSlotId,
                'date' => $request->date,
                'time_slot' => $request->timeSlot,
                'entries' => $request->entries,
                'counts' => $request->counts,
                'saved_at' => now(),
            ]);

            Log::info('✅ TokenData Created Successfully', [
                'user_id' => $user->id,
                'token_data_id' => $tokenData->id,
                'time_slot_id' => $request->timeSlotId,
                'time_slot' => $request->timeSlot,
                'date' => $request->date,
                'entries_count' => count($request->entries),
                'saved_at' => $tokenData->saved_at->toDateTimeString(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Token data saved successfully',
                'data' => [
                    'id' => $tokenData->id,
                    'time_slot_id' => $tokenData->time_slot_id,
                    'saved_at' => $tokenData->saved_at,
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('❌ TokenData Save Error', [
                'user_id' => $user->id ?? null,
                'time_slot_id' => $request->input('timeSlotId'),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save token data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get token data by date
     */
    public function getByDate(string $date): JsonResponse
    {
        $validator = Validator::make(['date' => $date], [
            'date' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid date format',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = TokenData::forUser(auth()->id())
                ->where('date', $date)
                ->orderBy('time_slot')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $data,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching token data', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch token data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get token data by date range
     */
    public function getByDateRange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = TokenData::forUser(auth()->id())
                ->whereBetween('date', [$request->start_date, $request->end_date])
                ->orderBy('date')
                ->orderBy('time_slot')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $data,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching token data by range', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch token data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Generate all time slots for a day
     * 9:00-11:00 AM: 15-minute intervals
     * 11:00 AM-9:40 PM: 20-minute intervals
     */
    private function generateTimeSlots(): array
    {
        $slots = [];
        
        // 9:00 AM to 11:00 AM - 15 minute intervals
        for ($hour = 9; $hour < 11; $hour++) {
            for ($minute = 0; $minute < 60; $minute += 15) {
                $slots[] = sprintf('%02d:%02d', $hour, $minute);
            }
        }
        // 11:00 AM (only add once)
        $slots[] = '11:00';
        
        // 11:00 AM to 9:40 PM - 20 minute intervals (start from 11:20 to avoid duplicate 11:00)
        for ($hour = 11; $hour < 22; $hour++) {
            // For hour 11, start from minute 20 to avoid duplicate 11:00
            $startMinute = $hour === 11 ? 20 : 0;
            for ($minute = $startMinute; $minute < 60; $minute += 20) {
                // Stop at 9:40 PM (21:40)
                if ($hour === 21 && $minute > 40) {
                    break;
                }
                $slots[] = sprintf('%02d:%02d', $hour, $minute);
                // Break after 21:40
                if ($hour === 21 && $minute === 40) {
                    break 2; // Break both loops
                }
            }
            // Break outer loop after 21:40
            if ($hour === 21) {
                break;
            }
        }
        
        return $slots;
    }

    /**
     * Calculate missing time slots between last record and current time
     */
    private function calculateMissingTimeSlots($lastRecord, $currentTime): array
    {
        $missingSlots = [];
        $allSlots = $this->generateTimeSlots();
        
        // Get last record's date and time slot
        $lastDate = Carbon::parse($lastRecord->date);
        $lastTimeSlot = $lastRecord->time_slot;
        
        // Find index of last time slot in the slots array
        $lastSlotIndex = array_search($lastTimeSlot, $allSlots);
        if ($lastSlotIndex === false) {
            // If last slot not found, start from first slot of last date
            $lastSlotIndex = -1;
        }
        
        // Start from the next slot after the last one
        $startSlotIndex = $lastSlotIndex + 1;
        
        // Get current date and time
        $currentDate = now();
        $currentHour = (int)$currentDate->format('H');
        $currentMinute = (int)$currentDate->format('i');
        $currentTimeMinutes = $currentHour * 60 + $currentMinute;
        
        // Calculate which slot we're currently in, or the last slot that has passed
        $currentSlotIndex = -1;
        $lastPassedSlotIndex = -1;
        
        foreach ($allSlots as $index => $slot) {
            [$slotHour, $slotMinute] = explode(':', $slot);
            $slotTimeMinutes = (int)$slotHour * 60 + (int)$slotMinute;
            
            // Track the last slot that has passed
            if ($currentTimeMinutes >= $slotTimeMinutes) {
                $lastPassedSlotIndex = $index;
                
                // Check if we're currently in this slot (before next slot starts)
                if ($index < count($allSlots) - 1) {
                    [$nextHour, $nextMinute] = explode(':', $allSlots[$index + 1]);
                    $nextSlotTimeMinutes = (int)$nextHour * 60 + (int)$nextMinute;
                    if ($currentTimeMinutes < $nextSlotTimeMinutes) {
                        $currentSlotIndex = $index;
                        // Don't break - continue to find last passed slot
                    }
                } else {
                    // Last slot - check if we're before 22:00 (10:00 PM)
                    if ($currentTimeMinutes < 22 * 60) {
                        $currentSlotIndex = $index;
                    }
                }
            }
        }
        
        // If before first slot (before 9:00 AM), don't create slots for today
        if ($currentTimeMinutes < 9 * 60) {
            $currentSlotIndex = -1;
            $lastPassedSlotIndex = -1;
        }
        
        // If after last slot (after 22:00), use the last slot index
        if ($currentTimeMinutes >= 22 * 60) {
            $currentSlotIndex = -1;
            // Keep lastPassedSlotIndex as the last slot of the day
        }
        
        // Use current slot if found, otherwise use last passed slot
        $targetSlotIndex = $currentSlotIndex >= 0 ? $currentSlotIndex : $lastPassedSlotIndex;
        
        // Generate missing slots from last record date to current date
        $dateIterator = $lastDate->copy();
        $endDate = $currentDate->copy();
        
        while ($dateIterator->lte($endDate)) {
            $dateStr = $dateIterator->format('Y-m-d');
            $isLastDate = $dateIterator->isSameDay($endDate);
            $isFirstDate = $dateIterator->isSameDay($lastDate);
            
            // Determine which slots to process for this date
            $slotsToProcess = [];
            
            if ($isFirstDate && $isLastDate) {
                // Same day - only process slots between last slot and target slot (current or last passed)
                if ($targetSlotIndex >= $startSlotIndex) {
                    for ($i = $startSlotIndex; $i <= $targetSlotIndex && $i < count($allSlots); $i++) {
                        $slotsToProcess[] = $allSlots[$i];
                    }
                }
            } elseif ($isFirstDate) {
                // First date - process from next slot after last to end of day
                for ($i = $startSlotIndex; $i < count($allSlots); $i++) {
                    $slotsToProcess[] = $allSlots[$i];
                }
            } elseif ($isLastDate) {
                // Last date - process from first slot to target slot
                if ($targetSlotIndex >= 0) {
                    for ($i = 0; $i <= $targetSlotIndex && $i < count($allSlots); $i++) {
                        $slotsToProcess[] = $allSlots[$i];
                    }
                }
            } else {
                // Middle dates - process all slots
                $slotsToProcess = $allSlots;
            }
            
            // Add missing slots for this date
            foreach ($slotsToProcess as $slot) {
                $timeSlotId = $dateStr . '_' . $slot;
                $missingSlots[] = [
                    'time_slot_id' => $timeSlotId,
                    'date' => $dateStr,
                    'time_slot' => $slot,
                ];
            }
            
            // Move to next day
            $dateIterator->addDay();
        }
        
        return $missingSlots;
    }

    /**
     * Create missing time slot records with 0 quantities
     * 
     * Note: All time calculations use IST (Asia/Kolkata) timezone
     * as configured in config/app.php. This ensures consistent
     * time slot calculations for all users in India.
     */
    private function createMissingTimeSlots(int $userId): void
    {
        try {
            // Get last saved record for this user
            // All times are in IST (Asia/Kolkata) timezone
            $lastRecord = TokenData::forUser($userId)
                ->orderBy('date', 'desc')
                ->orderBy('time_slot', 'desc')
                ->first();
            
            // If no last record, create slots from today's first slot to current slot
            if (!$lastRecord) {
                $allSlots = $this->generateTimeSlots();
                $currentDate = now();
                $currentHour = (int)$currentDate->format('H');
                $currentMinute = (int)$currentDate->format('i');
                $currentTimeMinutes = $currentHour * 60 + $currentMinute;
                
                // Find current slot index
                $currentSlotIndex = -1;
                foreach ($allSlots as $index => $slot) {
                    [$slotHour, $slotMinute] = explode(':', $slot);
                    $slotTimeMinutes = (int)$slotHour * 60 + (int)$slotMinute;
                    
                    if ($currentTimeMinutes >= $slotTimeMinutes) {
                        if ($index < count($allSlots) - 1) {
                            [$nextHour, $nextMinute] = explode(':', $allSlots[$index + 1]);
                            $nextSlotTimeMinutes = (int)$nextHour * 60 + (int)$nextMinute;
                            if ($currentTimeMinutes < $nextSlotTimeMinutes) {
                                $currentSlotIndex = $index;
                                break;
                            }
                        } else {
                            if ($currentTimeMinutes < 22 * 60) {
                                $currentSlotIndex = $index;
                                break;
                            }
                        }
                    }
                }
                
                // Only create slots if we're within valid time range (9:00 AM - 10:00 PM)
                if ($currentSlotIndex >= 0 && $currentTimeMinutes >= 9 * 60 && $currentTimeMinutes < 22 * 60) {
                    $dateStr = $currentDate->format('Y-m-d');
                    $zeroCounts = array_fill(0, 10, 0);
                    
                    for ($i = 0; $i <= $currentSlotIndex; $i++) {
                        $slot = $allSlots[$i];
                        $timeSlotId = $dateStr . '_' . $slot;
                        
                        TokenData::firstOrCreate(
                            [
                                'user_id' => $userId,
                                'time_slot_id' => $timeSlotId,
                            ],
                            [
                                'date' => $dateStr,
                                'time_slot' => $slot,
                                'entries' => [],
                                'counts' => $zeroCounts,
                                'saved_at' => now(),
                            ]
                        );
                    }
                    
                    Log::info('✅ Created missing time slots for new user', [
                        'user_id' => $userId,
                        'slots_created' => $currentSlotIndex + 1,
                    ]);
                }
                
                return;
            }
            
            // Calculate missing slots from last record to now
            $missingSlots = $this->calculateMissingTimeSlots($lastRecord, now());
            
            Log::info('🔍 Checking for missing time slots', [
                'user_id' => $userId,
                'last_record_date' => $lastRecord->date,
                'last_record_time_slot' => $lastRecord->time_slot,
                'missing_slots_count' => count($missingSlots),
                'missing_slots' => $missingSlots,
            ]);
            
            if (empty($missingSlots)) {
                // No missing slots
                Log::info('ℹ️ No missing time slots found', [
                    'user_id' => $userId,
                ]);
                return;
            }
            
            // Create records for missing slots
            $zeroCounts = array_fill(0, 10, 0);
            $createdCount = 0;
            
            foreach ($missingSlots as $slotData) {
                try {
                    // First check if record exists for this user
                    $existing = TokenData::where('user_id', $userId)
                        ->where('time_slot_id', $slotData['time_slot_id'])
                        ->first();
                    
                    if ($existing) {
                        Log::info('ℹ️ Time slot already exists', [
                            'user_id' => $userId,
                            'time_slot_id' => $slotData['time_slot_id'],
                        ]);
                        continue;
                    }
                    
                    // Try to create the record
                    $created = TokenData::create([
                        'user_id' => $userId,
                        'time_slot_id' => $slotData['time_slot_id'],
                        'date' => $slotData['date'],
                        'time_slot' => $slotData['time_slot'],
                        'entries' => [],
                        'counts' => $zeroCounts,
                        'saved_at' => now(),
                    ]);
                    
                    $createdCount++;
                    Log::info('✅ Created missing time slot record', [
                        'user_id' => $userId,
                        'time_slot_id' => $slotData['time_slot_id'],
                        'date' => $slotData['date'],
                        'time_slot' => $slotData['time_slot'],
                    ]);
                } catch (\Illuminate\Database\QueryException $e) {
                    // Handle duplicate key error (unique constraint violation)
                    if ($e->getCode() == 23000 || str_contains($e->getMessage(), 'Duplicate entry')) {
                        // Check if it exists for this user now (race condition)
                        $existing = TokenData::where('user_id', $userId)
                            ->where('time_slot_id', $slotData['time_slot_id'])
                            ->first();
                        
                        if ($existing) {
                            Log::info('ℹ️ Time slot already exists (race condition)', [
                                'user_id' => $userId,
                                'time_slot_id' => $slotData['time_slot_id'],
                            ]);
                        } else {
                            // Duplicate exists for different user - skip
                            Log::warning('⚠️ Time slot exists for different user, skipping', [
                                'user_id' => $userId,
                                'time_slot_id' => $slotData['time_slot_id'],
                                'error' => $e->getMessage(),
                            ]);
                        }
                    } else {
                        // Re-throw if it's a different error
                        throw $e;
                    }
                }
            }
            
            if ($createdCount > 0) {
                Log::info('✅ Created missing time slots', [
                    'user_id' => $userId,
                    'slots_created' => $createdCount,
                    'total_missing_slots' => count($missingSlots),
                    'last_record_date' => $lastRecord->date,
                    'last_record_time_slot' => $lastRecord->time_slot,
                ]);
            } else {
                Log::info('ℹ️ All missing time slots already exist', [
                    'user_id' => $userId,
                    'total_missing_slots' => count($missingSlots),
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('❌ Error creating missing time slots', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            // Don't throw - allow getAll to continue even if slot creation fails
        }
    }

    /**
     * Get all token data with pagination and filters
     */
    public function getAll(Request $request): JsonResponse
    {
        try {
            // Check if user is authenticated
            if (!auth()->check()) {
                Log::warning('Unauthenticated request to getAll', ['ip' => $request->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $userId = auth()->id();
            if (!$userId) {
                Log::error('User ID is null in getAll', ['ip' => $request->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 401);
            }

            // Create missing time slots before querying (BEST APPROACH)
            Log::info('🔍 getAll called - checking for missing time slots', [
                'user_id' => $userId,
                'timestamp' => now()->toDateTimeString(),
            ]);
            $this->createMissingTimeSlots($userId);

            $validator = Validator::make($request->all(), [
                'page' => 'sometimes|integer|min:1',
                'per_page' => 'sometimes|integer|min:1|max:100',
                'start_date' => 'sometimes|date',
                'end_date' => 'sometimes|date|after_or_equal:start_date',
                'time_slot' => [
                    'sometimes',
                    'string',
                    function ($attribute, $value, $fail) {
                        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $value)) {
                            $fail('The ' . $attribute . ' must be a valid time format (HH:MM).');
                        }
                    },
                ],
            ]);

            if ($validator->fails()) {
                Log::warning('Validation failed in getAll', [
                    'errors' => $validator->errors(),
                    'request' => $request->all(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $perPage = $request->input('per_page', 10);
            $page = $request->input('page', 1);

            $query = TokenData::forUser($userId);

            // Apply date range filter
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('date', [$request->start_date, $request->end_date]);
            } elseif ($request->has('start_date')) {
                $query->where('date', '>=', $request->start_date);
            } elseif ($request->has('end_date')) {
                $query->where('date', '<=', $request->end_date);
            }

            // Apply time slot filter
            if ($request->has('time_slot')) {
                $query->where('time_slot', $request->time_slot);
            }

            // Order by date (desc) and time slot (desc) - newest first
            $query->orderBy('date', 'desc')->orderBy('time_slot', 'desc');

            // Paginate
            $paginated = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => $paginated->items(),
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page' => $paginated->perPage(),
                    'total' => $paginated->total(),
                    'last_page' => $paginated->lastPage(),
                    'from' => $paginated->firstItem(),
                    'to' => $paginated->lastItem(),
                ],
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error fetching all token data', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch token data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete token data record
     */
    public function destroy($id): JsonResponse
    {
        try {
            // Check if user is authenticated
            if (!auth()->check()) {
                Log::warning('Unauthenticated request to destroy', ['ip' => request()->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $userId = auth()->id();
            if (!$userId) {
                Log::error('User ID is null in destroy', ['ip' => request()->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 401);
            }

            // Find the token data record and verify it belongs to the user
            $tokenData = TokenData::forUser($userId)->find($id);

            if (!$tokenData) {
                Log::warning('Token data not found or unauthorized', [
                    'id' => $id,
                    'user_id' => $userId,
                    'ip' => request()->ip(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Token data not found or unauthorized',
                ], 404);
            }

            // Delete the record
            $tokenData->delete();

            Log::info('Token data deleted successfully', [
                'id' => $id,
                'user_id' => $userId,
                'time_slot_id' => $tokenData->time_slot_id,
                'date' => $tokenData->date,
                'time_slot' => $tokenData->time_slot,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Token data deleted successfully',
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error deleting token data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete token data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update token data record
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            // Check if user is authenticated
            if (!auth()->check()) {
                Log::warning('Unauthenticated request to update', ['ip' => request()->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $userId = auth()->id();
            if (!$userId) {
                Log::error('User ID is null in update', ['ip' => request()->ip()]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 401);
            }

            // Find the token data record and verify it belongs to the user
            $tokenData = TokenData::forUser($userId)->find($id);

            if (!$tokenData) {
                Log::warning('Token data not found or unauthorized', [
                    'id' => $id,
                    'user_id' => $userId,
                    'ip' => request()->ip(),
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Token data not found or unauthorized',
                ], 404);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'entries' => 'required|array',
                'entries.*.number' => 'required|integer|min:0|max:9',
                'entries.*.quantity' => 'required|integer|min:1',
                'entries.*.timestamp' => 'required|integer',
            ]);

            if ($validator->fails()) {
                Log::warning('❌ TokenData Update Validation Failed', [
                    'user_id' => $userId,
                    'errors' => $validator->errors()->toArray(),
                    'input' => $request->all(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Recalculate counts from entries
            $counts = [];
            for ($i = 0; $i < 10; $i++) {
                $counts[$i] = 0;
            }
            foreach ($request->entries as $entry) {
                if (isset($entry['number']) && isset($entry['quantity'])) {
                    $number = (int)$entry['number'];
                    $quantity = (int)$entry['quantity'];
                    if ($number >= 0 && $number <= 9) {
                        $counts[$number] += $quantity;
                    }
                }
            }

            // Update the record
            $tokenData->update([
                'entries' => $request->entries,
                'counts' => $counts,
                'saved_at' => now(),
            ]);

            Log::info('✅ TokenData Updated Successfully', [
                'user_id' => $userId,
                'token_data_id' => $tokenData->id,
                'time_slot_id' => $tokenData->time_slot_id,
                'date' => $tokenData->date,
                'time_slot' => $tokenData->time_slot,
                'entries_count' => count($request->entries),
                'updated_at' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Token data updated successfully',
                'data' => $tokenData->fresh(),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error updating token data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'id' => $id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update token data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
