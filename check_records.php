<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "Checking token_data records for user_id = 2...\n\n";

$records = DB::table('token_data')
    ->where('user_id', 2)
    ->orderBy('date', 'desc')
    ->orderBy('time_slot', 'desc')
    ->get(['id', 'date', 'time_slot', 'time_slot_id', 'counts', 'entries', 'created_at']);

echo "Total records: " . $records->count() . "\n\n";

$autoCreated = 0;
$manual = 0;

foreach ($records as $r) {
    $counts = json_decode($r->counts, true);
    $entries = json_decode($r->entries, true);
    $entriesCount = is_array($entries) ? count($entries) : 0;
    
    // Check if all counts are 0 (auto-created record)
    $allZeros = true;
    if (is_array($counts)) {
        foreach ($counts as $count) {
            if ($count != 0) {
                $allZeros = false;
                break;
            }
        }
    }
    
    $type = $allZeros && $entriesCount == 0 ? "[AUTO-CREATED]" : "[MANUAL]";
    
    if ($type == "[AUTO-CREATED]") {
        $autoCreated++;
    } else {
        $manual++;
    }
    
    // Only show first 20 records to avoid too much output
    if ($autoCreated + $manual <= 20) {
        echo "{$type} Date: {$r->date} | Time: {$r->time_slot} | ID: {$r->time_slot_id}\n";
        echo "  Counts: " . json_encode($counts) . " | Entries: {$entriesCount}\n";
        echo "  Created: {$r->created_at}\n\n";
    }
}

echo "\n=== SUMMARY ===\n";
echo "Total records: " . $records->count() . "\n";
echo "Auto-created (0 quantities): {$autoCreated}\n";
echo "Manual (with data): {$manual}\n";
