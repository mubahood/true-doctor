<?php

namespace Database\Seeders;

use App\Models\District;
use Illuminate\Database\Seeder;

/**
 * Uganda's districts by region — reference data kept independent of the
 * (retired) registry domain; reused by patient address fields (HMS_PLAN.md §4).
 */
class DistrictSeeder extends Seeder
{
    public function run(): void
    {
        $byRegion = [
            'Central' => ['Kampala', 'Wakiso', 'Mukono', 'Mpigi', 'Masaka', 'Luwero', 'Mubende', 'Mityana', 'Kayunga', 'Nakaseke', 'Kalangala', 'Buikwe', 'Kiboga', 'Ssembabule', 'Rakai'],
            'Eastern' => ['Jinja', 'Mbale', 'Soroti', 'Tororo', 'Iganga', 'Kamuli', 'Busia', 'Kapchorwa', 'Pallisa', 'Bugiri', 'Kumi', 'Sironko', 'Butaleja', 'Namutumba'],
            'Northern' => ['Gulu', 'Lira', 'Arua', 'Kitgum', 'Nebbi', 'Apac', 'Adjumani', 'Moyo', 'Kotido', 'Moroto', 'Pader', 'Yumbe', 'Koboko', 'Nwoya'],
            'Western' => ['Mbarara', 'Kabale', 'Fort Portal', 'Kasese', 'Hoima', 'Masindi', 'Bushenyi', 'Ntungamo', 'Kabarole', 'Kisoro', 'Rukungiri', 'Ibanda', 'Kanungu', 'Bundibugyo', 'Kibaale'],
        ];

        $count = 0;
        foreach ($byRegion as $region => $names) {
            foreach ($names as $name) {
                District::updateOrCreate(['name' => $name], ['region' => $region]);
                $count++;
            }
        }

        $this->command->info("DistrictSeeder: {$count} districts seeded.");
    }
}
