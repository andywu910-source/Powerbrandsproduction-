<?php

namespace Database\Seeders;

use App\Models\Equipment;
use App\Models\LogDefinition;
use App\Models\LogEntry;
use App\Models\LogEntryLink;
use App\Models\Room;
use App\Models\User;
use App\Support\LogHub;
use Illuminate\Database\Seeder;

/**
 * LOCAL / PREVIEW ONLY: a handful of sample Compliance hub entries that share one Metrc UID, a room and a scale,
 * so the hub, the due strip and the Related logs panel have something to show. Refuses on production.
 * php artisan db:seed --class=LogHubSampleSeeder
 */
class LogHubSampleSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'staging', 'testing')) {
            throw new \RuntimeException('LogHubSampleSeeder only runs on a local or preview copy.');
        }
        $pw = 'local-only-'.bin2hex(random_bytes(4));
        $mk = fn ($name, $email, $role) => User::firstOrCreate(['email' => $email], ['name' => $name, 'role' => $role, 'password' => $pw, 'is_active' => true, 'must_change_password' => false]);
        $andy = $mk('Andy Wu (sample)', 'andy.sample@example.com', 'admin');
        $leo = $mk('Leo (sample)', 'leo.sample@example.com', 'supervisor');
        $jenny = $mk('Jenny (sample)', 'jenny.sample@example.com', 'supervisor');
        $mk('Sae (sample)', 'sae.sample@example.com', 'operator');
        Room::firstOrCreate(['room_no' => '11'], ['name' => 'Vape Production Room', 'active' => true]);
        Room::firstOrCreate(['room_no' => '12'], ['name' => 'Cold Room', 'active' => true]);
        Equipment::firstOrCreate(['equip_id' => 'PB-0004'], ['name' => 'Ohaus Scout scale', 'is_scale' => true, 'active' => true]);

        $uid = '1A4120300000596000002107';
        $add = function (string $code, string $on, array $data, User $by) {
            $d = LogDefinition::where('code', $code)->firstOrFail();
            $e = LogEntry::create(['log_definition_id' => $d->id, 'data' => $data, 'occurred_on' => $on, 'entered_by_user_id' => $by->id]);
            foreach (LogHub::extractLinks($d, $data) as $l) {
                LogEntryLink::create(['log_entry_id' => $e->id] + $l);
            }
        };
        $today = LogHub::today();
        $d = fn (int $ago) => $today->subDays($ago)->toDateString();

        $add('1-C', $d(20), ['date' => $d(20), 'transfer_manifest_no' => '0000489465', 'source_licensee' => 'OCM-PROC-24-000071-P2', 'package_uid' => $uid,
            'qty_weight' => '1.1kg', 'manifest_verified' => true, 'accepted_in_metrc' => true, 'received_by' => 'Andy Wu (sample)', 'item_strain_per_manifest' => 'Bulk THC Distillate (sample)'], $andy);
        $add('2-E', $d(6), ['date' => $d(6), 'item' => 'Bulk THC Distillate (sample)', 'lost_damaged_tag_old_uid' => '1A4120300000596000001709', 'reason' => 'Lost Tag',
            'new_tag_uid_metrc' => $uid, 'entered_by' => 'Leo (sample)'], $leo);
        $add('4-A', $d(2), ['reconciled_on' => $d(2), 'package_uid_metrc' => $uid, 'location' => 'Vape Production Room', 'wrong_location' => false,
            'expected_qty' => '0g', 'actual_qty' => '200g', 'adjustment_reason_entered' => 'Leftover from run (sample)', 'entered_by' => 'Jenny (sample)'], $jenny);
        $add('1-A', $d(1), ['date' => $d(1), 'item' => 'Distillate residue (sample)', 'metrc_tag' => $uid, 'weight' => '12', 'unit' => 'g', 'reason' => 'Unusable residue',
            'method' => 'Grind and mix', 'performed_by' => 'Leo (sample)', 'witness' => 'Jenny (sample)'], $leo);
        $add('2-D', $d(7), ['date' => $d(7), 'container_room' => 'Vape Production Room', 'tag_present_and_original' => true, 'deficiency_noted' => 'No deficiencies'], $leo);
        $add('T&H', $d(1), ['date' => $d(1), 'time' => '09:00', 'area_room' => 'Cold Room', 'temperature_f' => '38', 'humidity_pct_rh' => '45', 'in_range_yn' => 'Y', 'recorded_by' => 'Jenny (sample)'], $jenny);
        $add('3-C', $d(40), ['date' => $d(40), 'scale_id_location' => 'PB-0004', 'calibration_weight_used' => '.003, .001, 2, 10, 50', 'pass_fail' => 'Pass', 'calibrated_by' => 'Leo (sample)'], $leo);
        $add('BAL', $d(3), ['scale' => 'PB-0004', 'date_time' => $d(3).' 08:30', 'analyst' => 'Leo (sample)', 'weight_set_id' => 'PB-0059', 'zero' => true,
            'place_20g_19_8_20_2' => '20', 'add_2g_21_78_22_22' => '21.99', 'remove_weights_0_1_0_1' => '0', 'place_100g_99_0_101_0' => '100', 'pass' => true], $leo);
        $add('VIS', $d(0), ['date' => $d(0), 'visitor_full_name' => 'Sample Visitor', 'company' => 'Example Pest Co', 'time_of_arrival' => '10:00', 'purpose_of_the_visit' => 'Pest service', 'escort_name' => 'Leo (sample)'], $leo);
    }
}
