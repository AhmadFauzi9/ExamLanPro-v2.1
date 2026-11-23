<?php
/**
 * ExamLAN Pro - Student Import Service
 */

class StudentImportService {
    public static function import(array $rows, array &$db): array {
        $existingNisMap = [];
        $db['students'] = $db['students'] ?? [];

        foreach ($db['students'] as $idx => &$st) {
            if (empty($st['id'])) {
                $st['id'] = 'std_' . bin2hex(random_bytes(6));
            }
            $sNis = trim((string)($st['nis'] ?? ''));
            if ($sNis !== '') {
                $existingNisMap[$sNis] = $idx;
            }
        }
        unset($st);

        $imported = 0;
        $updated = 0;

        foreach ($rows as $r) {
            $name = trim((string)($r['name'] ?? ''));
            $class = trim((string)($r['class'] ?? ''));
            $nis = trim((string)($r['nis'] ?? ''));
            $no_absen = trim((string)($r['no_absen'] ?? $r['absen'] ?? ''));
            $tahun_ajaran = trim((string)($r['tahun_ajaran'] ?? $r['academic_year'] ?? '2026-2027'));
            $photo = (string)($r['photo'] ?? '');
            if ($name === '') continue;

            if ($nis !== '' && isset($existingNisMap[$nis])) {
                $idx = $existingNisMap[$nis];
                $db['students'][$idx]['name'] = $name;
                $db['students'][$idx]['class'] = $class ?: $db['students'][$idx]['class'];
                $db['students'][$idx]['tahun_ajaran'] = $tahun_ajaran ?: ($db['students'][$idx]['tahun_ajaran'] ?? '2026-2027');
                if ($no_absen !== '') {
                    $db['students'][$idx]['no_absen'] = $no_absen;
                }
                if (empty($db['students'][$idx]['password'])) {
                    $db['students'][$idx]['password'] = Utils::generateStudentPassword();
                }
                if ($photo) {
                    $db['students'][$idx]['photo'] = $photo;
                }
                $updated++;
            } else {
                $newSt = [
                    'id' => 'std_' . bin2hex(random_bytes(6)),
                    'no_absen' => $no_absen,
                    'nis' => $nis,
                    'name' => $name,
                    'class' => $class,
                    'tahun_ajaran' => $tahun_ajaran ?: '2026-2027',
                    'password' => Utils::generateStudentPassword(),
                    'photo' => $photo
                ];
                $db['students'][] = $newSt;
                if ($nis !== '') {
                    $existingNisMap[$nis] = count($db['students']) - 1;
                }
                $imported++;
            }
        }

        return ['imported' => $imported, 'updated' => $updated];
    }
}
