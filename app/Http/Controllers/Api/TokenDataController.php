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
