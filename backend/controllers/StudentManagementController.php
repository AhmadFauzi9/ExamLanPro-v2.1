<?php
// backend/controllers/StudentManagementController.php

require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Utils.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/AuditService.php';

class StudentManagementController {

    public static function getStudents() {
        AuthMiddleware::teacherOnly();
        $db = Database::load();
        $dirty = false;
        foreach ($db['students'] as &$st) {
            if (empty($st['id'])) {
                $st['id'] = 'std_' . bin2hex(random_bytes(6));
                $dirty = true;
            }
            if (!isset($st['tahun_ajaran']) || trim((string)$st['tahun_ajaran']) === '') {
                $st['tahun_ajaran'] = '2026-2027';
                $dirty = true;
            }
            if (!isset($st['no_absen'])) {
                $st['no_absen'] = '';
            }
        }
        unset($st);

        usort($db['students'], function($a, $b) {
            $classA = trim((string)($a['class'] ?? ''));
            $classB = trim((string)($b['class'] ?? ''));
            $clsCmp = strnatcasecmp($classA, $classB);
            if ($clsCmp !== 0) return $clsCmp;

            $hasAbsenA = isset($a['no_absen']) && trim((string)$a['no_absen']) !== '' && is_numeric($a['no_absen']);
            $hasAbsenB = isset($b['no_absen']) && trim((string)$b['no_absen']) !== '' && is_numeric($b['no_absen']);

            if ($hasAbsenA && $hasAbsenB) {
                $diff = (int)$a['no_absen'] - (int)$b['no_absen'];
                if ($diff !== 0) return $diff;
            } elseif ($hasAbsenA) {
                return -1;
            } elseif ($hasAbsenB) {
                return 1;
            }

            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        if ($dirty) Database::save($db);
        Response::json(['students' => $db['students'], 'settings' => $db['settings'] ?? []]);
    }

    public static function saveStudent() {
        AuthMiddleware::teacherOnly();
        $input = Utils::getJsonInput();
        $id = trim((string)($input['id'] ?? ''));
        $no_absen = trim((string)($input['no_absen'] ?? $input['absen'] ?? ''));
        $nis = trim((string)($input['nis'] ?? ''));
        $originalNis = trim((string)($input['original_nis'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $class = trim((string)($input['class'] ?? ''));
        $tahun_ajaran = trim((string)($input['tahun_ajaran'] ?? $input['academic_year'] ?? '2026-2027'));
        $photo = (string)($input['photo'] ?? '');

        if ($name === '' || $class === '') {
            Response::error('Nama siswa dan kelas wajib diisi.', 400);
        }
        if (!empty($photo) && !preg_match('#^data:image/(png|jpe?g|webp);base64,#i', $photo)) {
            Response::error('Format foto harus PNG, JPG, atau WEBP.', 400);
        }

        $db = Database::load();

        if ($nis !== '') {
            foreach ($db['students'] as $st) {
                $stNis = trim((string)($st['nis'] ?? ''));
                $stId = (string)($st['id'] ?? '');
                if ($stNis === $nis) {
                    if ($id !== '' && $stId === $id) continue;
                    if ($originalNis !== '' && $stNis === $originalNis) continue;
                    Response::error("NIS '{$nis}' sudah digunakan oleh siswa '{$st['name']}'.", 400);
                }
            }
        }

        $existingPassword = '';
        $existingAbsen = '';
        if ($id !== '') {
            foreach ($db['students'] as $st) {
                if ((string)($st['id'] ?? '') === $id) {
                    if (!empty($st['password'])) $existingPassword = (string)$st['password'];
                    if (isset($st['no_absen'])) $existingAbsen = (string)$st['no_absen'];
                    break;
                }
            }
        }
        if ($existingPassword === '' && $originalNis !== '') {
            foreach ($db['students'] as $st) {
                if ((string)($st['nis'] ?? '') === $originalNis) {
                    if (!empty($st['password'])) $existingPassword = (string)$st['password'];
                    if (isset($st['no_absen']) && $existingAbsen === '') $existingAbsen = (string)$st['no_absen'];
                    break;
                }
            }
        }
        if ($existingPassword === '' && $nis !== '') {
            foreach ($db['students'] as $st) {
                if ((string)($st['nis'] ?? '') === $nis) {
                    if (!empty($st['password'])) $existingPassword = (string)$st['password'];
                    if (isset($st['no_absen']) && $existingAbsen === '') $existingAbsen = (string)$st['no_absen'];
                    break;
                }
            }
        }

        $studentData = [
            'id' => $id !== '' ? $id : ('std_' . bin2hex(random_bytes(6))),
            'no_absen' => $no_absen !== '' ? $no_absen : $existingAbsen,
            'nis' => $nis,
            'name' => $name,
            'class' => $class,
            'tahun_ajaran' => $tahun_ajaran !== '' ? $tahun_ajaran : '2026-2027',
            'password' => $existingPassword !== '' ? $existingPassword : Utils::generateStudentPassword(),
            'photo' => $photo
        ];

        $found = false;
        $foundIdx = -1;
        $oldClass = '';
        if ($id !== '') {
            foreach ($db['students'] as $sIdx => $st) {
                if ((string)($st['id'] ?? '') === $id) {
                    $oldClass = (string)($st['class'] ?? '');
                    $foundIdx = $sIdx;
                    $found = true;
                    break;
                }
            }
        }
        if (!$found && $originalNis !== '') {
            foreach ($db['students'] as $sIdx => $st) {
                if ((string)($st['nis'] ?? '') === $originalNis) {
                    $oldClass = (string)($st['class'] ?? '');
                    $foundIdx = $sIdx;
                    $found = true;
                    break;
                }
            }
        }
        if (!$found && $nis !== '') {
            foreach ($db['students'] as $sIdx => $st) {
                if ((string)($st['nis'] ?? '') === $nis) {
                    $oldClass = (string)($st['class'] ?? '');
                    $foundIdx = $sIdx;
                    $found = true;
                    break;
                }
            }
        }

        if ($found && $foundIdx !== -1) {
            $db['students'][$foundIdx] = array_merge($db['students'][$foundIdx], $studentData);
            if ($oldClass !== '' && strcasecmp(trim($oldClass), trim($class)) !== 0) {
                $updatedStudent = $db['students'][$foundIdx];
                array_splice($db['students'], $foundIdx, 1);

                $targetClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)$class)));
                $lastClassIdx = -1;
                foreach ($db['students'] as $sIdx => $st) {
                    $stClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)($st['class'] ?? ''))));
                    if ($stClassClean === $targetClassClean) {
                        $lastClassIdx = $sIdx;
                    }
                }
                if ($lastClassIdx === -1 && $targetClassClean !== '') {
                    foreach ($db['students'] as $sIdx => $st) {
                        $stClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)($st['class'] ?? ''))));
                        if (strpos($stClassClean, $targetClassClean) === 0 || strpos($targetClassClean, $stClassClean) === 0) {
                            $lastClassIdx = $sIdx;
                        }
                    }
                }
                if ($lastClassIdx !== -1) {
                    array_splice($db['students'], $lastClassIdx + 1, 0, [$updatedStudent]);
                } else {
                    $db['students'][] = $updatedStudent;
                }
            }
        } else {
            $targetClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)$class)));
            $lastClassIdx = -1;

            foreach ($db['students'] as $sIdx => $st) {
                $stClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)($st['class'] ?? ''))));
                if ($stClassClean === $targetClassClean) {
                    $lastClassIdx = $sIdx;
                }
            }

            if ($lastClassIdx === -1 && $targetClassClean !== '') {
                foreach ($db['students'] as $sIdx => $st) {
                    $stClassClean = strtolower(trim(preg_replace('/\s+/', ' ', (string)($st['class'] ?? ''))));
                    if (strpos($stClassClean, $targetClassClean) === 0 || strpos($targetClassClean, $stClassClean) === 0) {
                        $lastClassIdx = $sIdx;
                    }
                }
            }

            if ($lastClassIdx !== -1) {
                array_splice($db['students'], $lastClassIdx + 1, 0, [$studentData]);
            } else {
                $db['students'][] = $studentData;
            }
        }

        usort($db['students'], function($a, $b) {
            $classA = trim((string)($a['class'] ?? ''));
            $classB = trim((string)($b['class'] ?? ''));
            $clsCmp = strnatcasecmp($classA, $classB);
            if ($clsCmp !== 0) return $clsCmp;

            $hasAbsenA = isset($a['no_absen']) && trim((string)$a['no_absen']) !== '' && is_numeric($a['no_absen']);
            $hasAbsenB = isset($b['no_absen']) && trim((string)$b['no_absen']) !== '' && is_numeric($b['no_absen']);

            if ($hasAbsenA && $hasAbsenB) {
                $diff = (int)$a['no_absen'] - (int)$b['no_absen'];
                if ($diff !== 0) return $diff;
            } elseif ($hasAbsenA) {
                return -1;
            } elseif ($hasAbsenB) {
                return 1;
            }

            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        AuditService::log($db, $found ? 'Edit siswa' : 'Tambah siswa', $nis !== '' ? $nis : $name);
        Database::save($db);
        Response::json(['status' => 'success', 'message' => 'Data siswa berhasil disimpan.']);
    }

    public static function deleteStudent() {
        AuthMiddleware::teacherOnly();
        $input = Utils::getJsonInput();
        $rawList = array_merge(
            (array)($input['ids'] ?? []),
            (array)($input['nis_list'] ?? [])
        );
        if (!empty($input['id'])) $rawList[] = $input['id'];
        if (!empty($input['nis'])) $rawList[] = $input['nis'];
        $ids = array_filter(array_map('trim', array_map('strval', $rawList)));
        $id = trim((string)($input['id'] ?? ''));
        $nis = trim((string)($input['nis'] ?? ''));

        $db = Database::load();

        if (!empty($ids)) {
            $idMap = array_flip($ids);
            $removed = [];
            $remaining = [];
            foreach ($db['students'] as $st) {
                $stId = (string)($st['id'] ?? '');
                $stNis = (string)($st['nis'] ?? '');
                if ((isset($idMap[$stId]) && $stId !== '') || (isset($idMap[$stNis]) && $stNis !== '')) {
                    $removed[] = $st;
                } else {
                    $remaining[] = $st;
                }
            }
            $db['trash']['students'] = array_merge($db['trash']['students'] ?? [], $removed);
            $db['students'] = $remaining;
            $count = count($removed);
            AuditService::log($db, 'Hapus siswa massal', "{$count} siswa");
            Database::save($db);
            Response::json(['status' => 'success', 'message' => "{$count} data siswa berhasil dihapus.", 'count' => $count]);
        }

        $removed = [];
        $remaining = [];
        foreach ($db['students'] as $st) {
            $match = false;
            if ($id !== '' && (string)($st['id'] ?? '') === $id) $match = true;
            elseif ($id === '' && $nis !== '' && (string)($st['nis'] ?? '') === $nis) $match = true;
            if ($match) $removed[] = $st; else $remaining[] = $st;
        }
        $db['trash']['students'] = array_merge($db['trash']['students'] ?? [], $removed);
        $db['students'] = $remaining;
        AuditService::log($db, 'Hapus siswa', $nis !== '' ? $nis : $id);
        Database::save($db);
        Response::json(['status' => 'success', 'message' => 'Data siswa dipindahkan ke trash.']);
    }

    public static function importStudents() {
        AuthMiddleware::teacherOnly();
        $input = Utils::getJsonInput();
        $rows = $input['students'] ?? [];
        if (!is_array($rows)) {
            Response::error('Data import tidak valid.', 400);
        }

        $db = Database::load();
        $existingNisMap = [];
        foreach ($db['students'] as $idx => &$st) {
            if (empty($st['id'])) $st['id'] = 'std_' . bin2hex(random_bytes(6));
            $sNis = trim((string)($st['nis'] ?? ''));
            if ($sNis !== '') $existingNisMap[$sNis] = $idx;
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
                if ($photo) $db['students'][$idx]['photo'] = $photo;
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
                if ($nis !== '') $existingNisMap[$nis] = count($db['students']) - 1;
                $imported++;
            }
        }

        usort($db['students'], function($a, $b) {
            $classA = trim((string)($a['class'] ?? ''));
            $classB = trim((string)($b['class'] ?? ''));
            $clsCmp = strnatcasecmp($classA, $classB);
            if ($clsCmp !== 0) return $clsCmp;

            $hasAbsenA = isset($a['no_absen']) && trim((string)$a['no_absen']) !== '' && is_numeric($a['no_absen']);
            $hasAbsenB = isset($b['no_absen']) && trim((string)$b['no_absen']) !== '' && is_numeric($b['no_absen']);

            if ($hasAbsenA && $hasAbsenB) {
                $diff = (int)$a['no_absen'] - (int)$b['no_absen'];
                if ($diff !== 0) return $diff;
            } elseif ($hasAbsenA) {
                return -1;
            } elseif ($hasAbsenB) {
                return 1;
            }

            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        AuditService::log($db, 'Import siswa', "Baru: {$imported}, Diperbarui: {$updated}");
        Database::save($db);
        Response::json([
            'status' => 'success',
            'message' => "Data siswa berhasil diimpor (Baru: {$imported}, Diperbarui: {$updated}).",
            'count' => ($imported + $updated)
        ]);
    }
}
