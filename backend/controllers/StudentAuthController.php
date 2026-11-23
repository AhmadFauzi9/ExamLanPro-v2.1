<?php
/**
 * ExamLAN Pro - Student Authentication Controller
 */

class StudentAuthController {
    public static function login(): void {
        $input = Utils::getJsonInput();
        $nis = trim((string)($input['nis'] ?? ''));
        $password = strtoupper(trim((string)($input['password'] ?? '')));

        $db = Database::load();
        $requirePassword = (bool)($db['settings']['require_student_password'] ?? true);

        $inputName = strtoupper(trim((string)($input['name'] ?? '')));

        if ($nis === '' && $inputName === '') {
            Response::error('Nomor Induk Siswa (NIS) atau Nama Siswa wajib diisi.', 400);
        }

        if ($requirePassword && $password === '') {
            Response::error('Password login siswa wajib diisi.', 400);
        }

        $matched = null;
        if ($nis !== '') {
            foreach ($db['students'] as $st) {
                if (trim((string)($st['nis'] ?? '')) === $nis) {
                    $matched = $st;
                    break;
                }
            }
        }

        if (!$matched && $inputName !== '') {
            foreach ($db['students'] as $st) {
                $stName = strtoupper(trim((string)($st['name'] ?? '')));
                if ($stName === $inputName) {
                    $matched = $st;
                    $nis = trim((string)($st['nis'] ?? ''));
                    break;
                }
            }
        }

        if (!$matched) {
            Response::error('Data belum terdaftar di server, harap hubungi guru untuk mendaftarkan data anda.', 404);
        }

        if ($requirePassword) {
            $stPassword = strtoupper(trim((string)($matched['password'] ?? '')));
            if ($stPassword === '' || !hash_equals($stPassword, $password)) {
                Response::error('Password login salah! Silakan tanyakan password login Anda kepada guru pengawas.', 401);
            }
        }

        $quizId = trim((string)($input['quiz_id'] ?? ''));
        $quizTitle = '';
        $selectedQuizObj = null;
        if ($quizId !== '') {
            foreach ($db['questions'] as $q) {
                if ((string)($q['id'] ?? '') === $quizId) {
                    $quizTitle = (string)($q['title'] ?? $quizId);
                    $selectedQuizObj = $q;
                    break;
                }
            }
        }

        // Periksa apakah siswa memiliki sesi ujian aktif yang belum selesai
        $activeAttempt = null;
        $activeAttemptKey = null;
        $db['active_attempts'] = $db['active_attempts'] ?? [];
        foreach ($db['active_attempts'] as $ak => $cand) {
            if ((string)($cand['nis'] ?? '') === $nis && empty($cand['finished'])) {
                $activeAttempt = $cand;
                $activeAttemptKey = $ak;
                break;
            }
        }

        $db['student_sessions'] = $db['student_sessions'] ?? [];
        $prev = $db['student_sessions'][$nis] ?? [];
        $isResetAllowed = !empty($prev['reset_allowed']) || !empty($activeAttempt['reset_allowed']);

        // Kasus 1: Siswa memiliki ujian aktif yang belum selesai
        if ($activeAttempt !== null) {
            $attemptQuizId = (string)($activeAttempt['quiz_id'] ?? $quizId);
            $attemptQuiz = null;
            foreach ($db['questions'] as $q) {
                if ((string)($q['id'] ?? '') === $attemptQuizId) {
                    $attemptQuiz = $q;
                    break;
                }
            }
            if (!$attemptQuiz) {
                foreach (($db['trash']['quizzes'] ?? []) as $candidate) {
                    if ((string)($candidate['id'] ?? '') === $attemptQuizId) {
                        $attemptQuiz = $candidate;
                        break;
                    }
                }
            }
            if (!$attemptQuiz) {
                $emDb = Database::getEmergencyBackupDb();
                if ($emDb && !empty($emDb['questions'])) {
                    foreach ($emDb['questions'] as $candidate) {
                        if ((string)($candidate['id'] ?? '') === $attemptQuizId) {
                            $attemptQuiz = $candidate;
                            break;
                        }
                    }
                }
            }

            if ($isResetAllowed) {
                $quizPin = strtoupper(trim((string)($attemptQuiz['pin'] ?? '')));
                $inputPin = strtoupper(trim((string)($input['pin'] ?? '')));

                if ($quizPin !== '') {
                    if ($inputPin === '') {
                        Response::success([
                            'needs_resume_token' => true,
                            'message' => 'Sesi ujian Anda telah dibuka oleh Guru Pengawas. Silakan masukkan Token Ujian aktif terbaru dari pengawas untuk melanjutkan ujian.',
                            'student' => [
                                'nis' => (string)$matched['nis'],
                                'name' => (string)$matched['name'],
                                'class' => (string)$matched['class'],
                                'tahun_ajaran' => (string)($matched['tahun_ajaran'] ?? '')
                            ],
                            'quiz' => [
                                'id' => $attemptQuizId,
                                'title' => $attemptQuiz['title'] ?? 'Ujian Berjalan',
                                'subject' => $attemptQuiz['subject'] ?? 'Umum',
                                'class' => $attemptQuiz['class'] ?? ''
                            ]
                        ]);
                    }

                    if (!hash_equals($quizPin, $inputPin)) {
                        Response::error('TOKEN ujian tidak valid atau sudah kedaluwarsa. Silakan minta dan masukkan token terbaru yang aktif dari guru pengawas.', 401);
                    }
                }

                if (isset($db['student_sessions'][$nis])) {
                    $db['student_sessions'][$nis]['reset_allowed'] = false;
                    $db['student_sessions'][$nis]['last_seen'] = time();
                    $db['student_sessions'][$nis]['status'] = 'WORKING';
                    $db['student_sessions'][$nis]['ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
                }
                if (isset($db['active_attempts'][$activeAttemptKey])) {
                    $db['active_attempts'][$activeAttemptKey]['reset_allowed'] = false;
                    $db['active_attempts'][$activeAttemptKey]['updated_at'] = time();
                }

                AuditService::log($db, 'Siswa resume ujian', "{$nis} - " . (string)$matched['name'], "Melanjutkan ujian setelah reset sesi & validasi token pengawas: " . ($attemptQuiz['title'] ?? $attemptQuizId));

                $tokenToSend = $activeAttempt['token'] ?? '';
                if ($tokenToSend === '') {
                    $tokenToSend = bin2hex(random_bytes(32));
                    $newHash = Utils::attemptKey($tokenToSend);
                    $db['active_attempts'][$newHash] = $activeAttempt;
                    $db['active_attempts'][$newHash]['token'] = $tokenToSend;
                    $db['active_attempts'][$newHash]['token_hash'] = $newHash;
                    if ($newHash !== $activeAttemptKey) {
                        unset($db['active_attempts'][$activeAttemptKey]);
                    }
                }
                Database::save($db);

                Response::success([
                    'resume_attempt' => true,
                    'message' => 'Sesi ujian Anda dipulihkan oleh Guru Pengawas. Seluruh progres dan jawaban tersimpan aman di server.',
                    'student' => [
                        'nis' => (string)$matched['nis'],
                        'name' => (string)$matched['name'],
                        'class' => (string)$matched['class'],
                        'tahun_ajaran' => (string)($matched['tahun_ajaran'] ?? '')
                    ],
                    'attempt' => [
                        'attempt_token' => $tokenToSend,
                        'quiz' => $attemptQuiz ? ScoringEngine::publicAttemptQuiz($attemptQuiz, $activeAttempt) : null,
                        'answers' => $activeAttempt['answers'] ?? [],
                        'server_now' => time(),
                        'ends_at' => $activeAttempt['ends_at']
                    ]
                ]);
            }

            $lastSeen = (int)($prev['last_seen'] ?? ($activeAttempt['updated_at'] ?? 0));
            $prevIp = (string)($prev['ip'] ?? '');
            $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';

            // Jika siswa login kembali dari komputer/IP yang sama, otomatis pulihkan sesi ujian aktif
            if ($prevIp === '' || $prevIp === $currentIp) {
                if (isset($db['student_sessions'][$nis])) {
                    $db['student_sessions'][$nis]['last_seen'] = time();
                    $db['student_sessions'][$nis]['status'] = 'WORKING';
                    $db['student_sessions'][$nis]['ip'] = $currentIp;
                }
                if (isset($db['active_attempts'][$activeAttemptKey])) {
                    $db['active_attempts'][$activeAttemptKey]['updated_at'] = time();
                }

                AuditService::log($db, 'Siswa resume ujian otomatis', "{$nis} - " . (string)$matched['name'], "Melanjutkan ujian dari perangkat yang sama: " . ($attemptQuiz['title'] ?? $attemptQuizId));

                $tokenToSend = $activeAttempt['token'] ?? '';
                if ($tokenToSend === '') {
                    $tokenToSend = bin2hex(random_bytes(32));
                    $newHash = Utils::attemptKey($tokenToSend);
                    $db['active_attempts'][$newHash] = $activeAttempt;
                    $db['active_attempts'][$newHash]['token'] = $tokenToSend;
                    $db['active_attempts'][$newHash]['token_hash'] = $newHash;
                    if ($newHash !== $activeAttemptKey) {
                        unset($db['active_attempts'][$activeAttemptKey]);
                    }
                }
                Database::save($db);

                Response::success([
                    'resume_attempt' => true,
                    'message' => 'Sesi ujian Anda dipulihkan otomatis. Seluruh progres dan jawaban tersimpan aman di server.',
                    'student' => [
                        'nis' => (string)$matched['nis'],
                        'name' => (string)$matched['name'],
                        'class' => (string)$matched['class'],
                        'tahun_ajaran' => (string)($matched['tahun_ajaran'] ?? '')
                    ],
                    'attempt' => [
                        'attempt_token' => $tokenToSend,
                        'quiz' => $attemptQuiz ? ScoringEngine::publicAttemptQuiz($attemptQuiz, $activeAttempt) : null,
                        'answers' => $activeAttempt['answers'] ?? [],
                        'server_now' => time(),
                        'ends_at' => $activeAttempt['ends_at']
                    ]
                ]);
            }

            // Jika login dari perangkat/IP yang berbeda tanpa reset pengawas
            Response::json([
                'status' => 'locked',
                'message' => "Sesi ujian Anda masih aktif di perangkat lab lain. Jika komputer Anda mati, restart, atau berganti perangkat ujian lab, silakan lapor kepada Guru Pengawas untuk melakukan 'Reset Sesi' di halaman Monitoring Ujian agar Anda dapat lanjut ujian tanpa kehilangan jawaban."
            ], 403);
        }

        $db['student_sessions'][$nis] = [
            'nis' => (string)$matched['nis'],
            'name' => (string)$matched['name'],
            'class' => (string)$matched['class'],
            'quiz_id' => $quizId ?: ($prev['quiz_id'] ?? ''),
            'quiz_title' => $quizTitle ?: ($prev['quiz_title'] ?? ''),
            'status' => (!empty($prev['status']) && $prev['status'] === 'WORKING') ? 'WORKING' : 'STANDBY',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'login_at' => $prev['login_at'] ?? time(),
            'last_seen' => time(),
            'attempt_token_hash' => $prev['attempt_token_hash'] ?? ''
        ];
        AuditService::log($db, 'Login siswa', (string)$matched['nis'] . ' - ' . (string)$matched['name'], 'Kelas ' . (string)$matched['class'] . ($quizTitle ? ' (' . $quizTitle . ')' : ''));
        Database::save($db);

        $hasToken = !empty($selectedQuizObj['pin']);
        Response::success([
            'message' => 'Login berhasil.',
            'student' => [
                'nis' => (string)$matched['nis'],
                'name' => (string)$matched['name'],
                'class' => (string)$matched['class'],
                'tahun_ajaran' => (string)($matched['tahun_ajaran'] ?? '')
            ],
            'quiz' => [
                'id' => $quizId,
                'title' => $quizTitle,
                'has_token' => $hasToken,
                'pin_enabled' => $hasToken
            ]
        ]);
    }

    public static function getStudentData(): void {
        $db = Database::load();
        $studentQuizzes = [];

        foreach ($db['questions'] as $quiz) {
            if (ScoringEngine::quizAvailableNow($quiz) !== null) continue;
            $safeQuestions = [];
            foreach (($quiz['questions'] ?? []) as $idx => $q) {
                $safe = $q;
                unset($safe['answer'], $safe['answers'], $safe['explanation']);
                $safe['_student_id'] = (string)$idx;
                $safeQuestions[] = $safe;
            }
            $hasToken = !empty($quiz['pin']);
            unset($quiz['pin']);
            $quiz['has_token'] = $hasToken;
            $quiz['pin_enabled'] = $hasToken;
            $quiz['questions'] = $safeQuestions;
            $studentQuizzes[] = $quiz;
        }

        $publicStudents = array_map(function($st) {
            return [
                'nis' => (string)($st['nis'] ?? ''),
                'name' => (string)($st['name'] ?? ''),
                'class' => (string)($st['class'] ?? ''),
                'tahun_ajaran' => (string)($st['tahun_ajaran'] ?? $st['academic_year'] ?? '')
            ];
        }, $db['students'] ?? []);

        $requirePassword = (bool)($db['settings']['require_student_password'] ?? true);

        Response::json([
            'questions' => $studentQuizzes,
            'results' => [],
            'students' => $publicStudents,
            'settings' => [
                'require_student_password' => $requirePassword
            ]
        ]);
    }

    public static function getPublicSettings(): void {
        $db = Database::load();
        Response::success([
            'require_student_password' => (bool)($db['settings']['require_student_password'] ?? true)
        ]);
    }

    public static function checkAttempt(): void {
        $db = Database::load();
        $nis = trim((string)($_GET['nis'] ?? ''));
        $quizId = trim((string)($_GET['quiz_id'] ?? ''));

        if ($nis === '' || $quizId === '') {
            Response::error('NIS atau paket kuis tidak valid.', 400);
        }

        $alreadyAttempted = false;
        foreach ($db['results'] as $r) {
            if ((string)($r['nis'] ?? '') === $nis && (string)($r['code'] ?? '') === $quizId) {
                $alreadyAttempted = true;
                break;
            }
        }

        $permissionKey = $nis . '_' . $quizId;
        $permission = $db['retake_permissions'][$permissionKey] ?? null;
        $retakeAllowed = is_array($permission) 
            ? ((int)($permission['remaining'] ?? 0) > 0 && (empty($permission['expires_at']) || strtotime($permission['expires_at']) >= time())) 
            : !empty($permission);

        Response::success([
            'already_attempted' => $alreadyAttempted,
            'retake_allowed' => $retakeAllowed
        ]);
    }
}
