<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Checking slots AFTER 15:40 for user_id = 2 on 2025-12-11...\n\n";

$records = DB::table('token_data')
    ->where('user_id', 2)
    ->where('date', '2025-12-11')
    ->where('time_slot', '>', '15:40')
    ->orderBy('time_slot', 'asc')
    ->get(['id', 'time_slot', 'time_slot_id', 'counts', 'entries', 'created_at']);

echo "Records found after 15:40: " . $records->count() . "\n\n";

if ($records->count() > 0) {
    echo "Slots after 15:40:\n";
    foreach ($records as $r) {
        $counts = json_decode($r->counts, true);
        $entries = json_decode($r->entries, true);
        $entriesCount = is_array($entries) ? count($entries) : 0;
        
        $allZeros = true;
        if (is_array($counts)) {
            foreach ($counts as $count) {
                if ($count != 0) {
                    $allZeros = false;
                    break;
                }
            }
        }
        
        $type = $allZeros && $entriesCount == 0 ? "[AUTO]" : "[MANUAL]";
        echo "{$type} {$r->time_slot} | Created: {$r->created_at}\n";
    }
} else {
    echo "❌ NO SLOTS FOUND AFTER 15:40!\n";
    echo "Expected slots: 16:00, 16:20, 16:40, 17:00, 17:20, 17:40, 18:00, 18:20, 18:40, 19:00, 19:20, 19:40, 20:00, 20:20, 20:40, 21:00, 21:20, 21:40\n";
}

echo "\n=== Checking what the last record is ===\n";
$lastRecord = DB::table('token_data')
    ->where('user_id', 2)
    ->orderBy('date', 'desc')
    ->orderBy('time_slot', 'desc')
    ->first(['date', 'time_slot', 'time_slot_id', 'created_at']);

if ($lastRecord) {
    echo "Last record: {$lastRecord->date} | {$lastRecord->time_slot} | Created: {$lastRecord->created_at}\n";
} else {
    echo "No records found for user 2\n";
}


