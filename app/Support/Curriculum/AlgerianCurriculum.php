<?php

declare(strict_types=1);

namespace App\Support\Curriculum;

use App\Enums\Cycle;
use App\Models\Level;
use App\Models\Subject;
use App\Models\Tenant;
use App\Models\Track;
use App\Support\Audit\AuditRecorder;

/** Reference data of the Algerian national education system, copied into each new tenant. */
final class AlgerianCurriculum
{
    /** @return list<array{code: string, name: string, name_fr: string, cycle: string, sort_order: int}> */
    public static function levels(): array
    {
        $rows = [[
            'code' => 'PREP', 'name' => 'التحضيري', 'name_fr' => 'Préparatoire',
            'cycle' => Cycle::Preparatory->value, 'sort_order' => 0,
        ]];

        $ordinalsAr = ['الأولى', 'الثانية', 'الثالثة', 'الرابعة', 'الخامسة'];
        $ordinalsFr = ['1ère', '2ème', '3ème', '4ème', '5ème'];

        $cycles = [
            ['AP', 5, 'ابتدائي', 'primaire', Cycle::Primary],
            ['AM', 4, 'متوسط', 'moyenne', Cycle::Middle],
            ['AS', 3, 'ثانوي', 'secondaire', Cycle::Secondary],
        ];

        $order = 1;
        foreach ($cycles as [$suffix, $years, $nameAr, $nameFr, $cycle]) {
            for ($i = 0; $i < $years; $i++) {
                $rows[] = [
                    'code' => ($i + 1).$suffix,
                    'name' => "السنة {$ordinalsAr[$i]} {$nameAr}",
                    'name_fr' => "{$ordinalsFr[$i]} année {$nameFr}",
                    'cycle' => $cycle->value,
                    'sort_order' => $order++,
                ];
            }
        }

        return $rows;
    }

    /** @return list<array{code: string, name: string, name_fr: string}> */
    public static function tracks(): array
    {
        return [
            ['code' => 'ST', 'name' => 'جذع مشترك علوم وتكنولوجيا', 'name_fr' => 'Tronc commun Sciences et Technologies'],
            ['code' => 'L', 'name' => 'جذع مشترك آداب', 'name_fr' => 'Tronc commun Lettres'],
            ['code' => 'SE', 'name' => 'علوم تجريبية', 'name_fr' => 'Sciences expérimentales'],
            ['code' => 'M', 'name' => 'رياضيات', 'name_fr' => 'Mathématiques'],
            ['code' => 'TM', 'name' => 'تقني رياضي', 'name_fr' => 'Technique mathématique'],
            ['code' => 'GE', 'name' => 'تسيير واقتصاد', 'name_fr' => 'Gestion et économie'],
            ['code' => 'LP', 'name' => 'آداب وفلسفة', 'name_fr' => 'Lettres et philosophie'],
            ['code' => 'LE', 'name' => 'لغات أجنبية', 'name_fr' => 'Langues étrangères'],
        ];
    }

    /** @return list<array{code: string, name: string, name_fr: string}> */
    public static function subjects(): array
    {
        return [
            ['code' => 'AR', 'name' => 'اللغة العربية', 'name_fr' => 'Langue arabe'],
            ['code' => 'MATH', 'name' => 'الرياضيات', 'name_fr' => 'Mathématiques'],
            ['code' => 'FR', 'name' => 'اللغة الفرنسية', 'name_fr' => 'Langue française'],
            ['code' => 'EN', 'name' => 'اللغة الإنجليزية', 'name_fr' => 'Langue anglaise'],
            ['code' => 'SVT', 'name' => 'العلوم الطبيعية', 'name_fr' => 'Sciences naturelles'],
            ['code' => 'PHY', 'name' => 'العلوم الفيزيائية والتكنولوجيا', 'name_fr' => 'Sciences physiques et technologie'],
            ['code' => 'ISL', 'name' => 'التربية الإسلامية', 'name_fr' => 'Éducation islamique'],
            ['code' => 'HIST', 'name' => 'التاريخ', 'name_fr' => 'Histoire'],
            ['code' => 'GEO', 'name' => 'الجغرافيا', 'name_fr' => 'Géographie'],
            ['code' => 'CIV', 'name' => 'التربية المدنية', 'name_fr' => 'Éducation civique'],
            ['code' => 'PHILO', 'name' => 'الفلسفة', 'name_fr' => 'Philosophie'],
            ['code' => 'INFO', 'name' => 'الإعلام الآلي', 'name_fr' => 'Informatique'],
            ['code' => 'EPS', 'name' => 'التربية البدنية', 'name_fr' => 'Éducation physique'],
            ['code' => 'ART', 'name' => 'التربية الفنية', 'name_fr' => 'Éducation artistique'],
            ['code' => 'TAM', 'name' => 'اللغة الأمازيغية', 'name_fr' => 'Tamazight'],
        ];
    }

    /**
     * Bulk-inserts the reference rows (no per-row audit noise; one summary audit event instead).
     * Rows get their tenant_id explicitly because bulk insert bypasses model events.
     */
    public static function seedFor(Tenant $tenant): void
    {
        $base = ['tenant_id' => $tenant->getKey(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()];

        Level::query()->insert(array_map(static fn (array $r): array => $r + $base, self::levels()));
        Track::query()->insert(array_map(static fn (array $r): array => $r + $base, self::tracks()));
        Subject::query()->insert(array_map(static fn (array $r): array => $r + $base, self::subjects()));

        AuditRecorder::event('tenant.curriculum_seeded', [
            'levels' => count(self::levels()),
            'tracks' => count(self::tracks()),
            'subjects' => count(self::subjects()),
        ], (int) $tenant->getKey());
    }
}
