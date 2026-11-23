<?php
/**
 * ExamLAN Pro - Central Database Service
 * Robust, Concurrency-Safe, Atomic File-Backed Storage with Anti-Wipeout Protection
 */

require_once __DIR__ . '/BackupService.php';

class Database {
    private static ?array $cachedDb = null;
    private static $lockHandle = null;
    private static int $lockDepth = 0;

    private static function getLockFilePath(): string {
        return EXAMLAN_STORAGE . '/db.lock';
    }

    /**
     * Acquire file lock (LOCK_SH or LOCK_EX) with retry mechanism
     */
    public static function acquireLock(int $type): void {
        if (self::$lockHandle === null) {
            $lockFile = self::getLockFilePath();
            self::$lockHandle = @fopen($lockFile, 'c+');
            if (!self::$lockHandle) {
                return;
            }
        }

        if (self::$lockDepth === 0) {
            $retries = 35; // ~700ms max wait
            $acquired = false;
            while ($retries > 0) {
                if (@flock(self::$lockHandle, $type | LOCK_NB)) {
                    $acquired = true;
                    break;
                }
                usleep(20000); // 20ms
                $retries--;
            }
            if (!$acquired) {
                @flock(self::$lockHandle, $type);
            }
        }
        self::$lockDepth++;
    }

    /**
     * Release file lock
     */
    public static function releaseLock(): void {
        if (self::$lockDepth > 0) {
            self::$lockDepth--;
            if (self::$lockDepth === 0 && self::$lockHandle) {
                @flock(self::$lockHandle, LOCK_UN);
                @fclose(self::$lockHandle);
                self::$lockHandle = null;
            }
        }
    }

    /**
     * Find emergency backup database from .bak or latest valid storage backup
     */
    public static function getEmergencyBackupDb(): ?array {
        $bakFile = EXAMLAN_DB_FILE . '.bak';
        if (file_exists($bakFile) && filesize($bakFile) > 200) {
            $content = @file_get_contents($bakFile);
            $parsed = @json_decode($content, true);
            if (is_array($parsed) && !empty($parsed['questions'])) {
                return $parsed;
            }
        }

        $backupsDir = EXAMLAN_STORAGE . '/backups';
        if (is_dir($backupsDir)) {
            $files = glob($backupsDir . '/examlan-*.json');
            if ($files) {
                usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
                foreach ($files as $f) {
                    if (filesize($f) > 500) {
                        $content = @file_get_contents($f);
                        $parsed = @json_decode($content, true);
                        if (is_array($parsed) && !empty($parsed['questions'])) {
                            return $parsed;
                        }
                    }
                }
            }
        }
        return null;
    }

    /**
     * Load raw database from file with retry and auto-recovery
     */
    public static function loadRaw(): array {
        $file_db = EXAMLAN_DB_FILE;

        if (!file_exists($file_db) || filesize($file_db) < 50) {
            $recovered = self::getEmergencyBackupDb();
            if ($recovered !== null) {
                self::saveRaw($recovered);
                return $recovered;
            }

            $defaultData = [
                'questions' => [],
                'results' => [],
                'review_settings' => [],
                'retake_permissions' => [],
                'students' => [],
                'active_attempts' => [],
                'student_sessions' => [],
                'audit_logs' => [],
                'backups' => [],
                'time_extension_requests' => [],
                'settings' => ['require_student_password' => true],
                'trash' => ['results' => [], 'students' => [], 'quizzes' => []],
                'teacher_password_hash' => DEFAULT_TEACHER_HASH,
                'teacher_password_changed' => false
            ];
            self::saveRaw($defaultData);
            return $defaultData;
        }

        $content = false;
        for ($i = 0; $i < 5; $i++) {
            $content = @file_get_contents($file_db);
            if ($content !== false && strlen(trim($content)) > 20) {
                break;
            }
            usleep(25000);
        }

        $db = is_string($content) ? json_decode($content, true) : null;

        // Auto-recovery jika file terbaca kosong atau rusak akibat benturan proses (race condition)
        if (!is_array($db) || empty($db)) {
            $recovered = self::getEmergencyBackupDb();
            if ($recovered !== null && !empty($recovered['questions'])) {
                error_log('[ExamLAN Pro Auto-Recovery] db_kuis.json invalid/empty, recovered ' . count($recovered['questions']) . ' quizzes from backup.');
                $db = $recovered;
                self::saveRaw($db);
            }
        }

        if (!is_array($db)) {
            $db = [];
        }

        $db['questions'] = $db['questions'] ?? [];
        $dirty = false;
        $now = time();
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        foreach ($db['questions'] as &$q) {
            $pin = strtoupper(trim((string)($q['pin'] ?? '')));
            $hasPin = ($pin !== '');
            $q['pin'] = $pin;
            $q['pin_enabled'] = $hasPin;

            if (!$hasPin) {
                $q['pin_auto_refresh'] = false;
            } else {
                $autoRefresh = filter_var($q['pin_auto_refresh'] ?? true, FILTER_VALIDATE_BOOLEAN);
                $q['pin_auto_refresh'] = $autoRefresh;
                if ($autoRefresh) {
                    $updatedAt = (int)($q['pin_updated_at'] ?? 0);
                    if ($updatedAt <= 0) {
                        $q['pin_updated_at'] = $now;
                        $dirty = true;
                    } elseif (($now - $updatedAt) >= TOKEN_ROTATION_INTERVAL) {
                        $q['pin'] = substr(str_shuffle($chars), 0, 6);
                        $q['pin_updated_at'] = $now;
                        unset($q['prev_pin'], $q['prev_pin_expires_at']);
                        $dirty = true;
                    }
                }
            }
        }
        unset($q);

        $db['results'] = $db['results'] ?? [];
        foreach ($db['results'] as &$r) {
            if (empty($r['id'])) {
                $r['id'] = !empty($r['review_token']) ? ('res_' . substr($r['review_token'], 0, 16)) : ('res_' . bin2hex(random_bytes(8)));
                $dirty = true;
            }
        }
        unset($r);

        $db['review_settings'] = $db['review_settings'] ?? [];
        $db['retake_permissions'] = $db['retake_permissions'] ?? [];
        $db['students'] = $db['students'] ?? [];

        foreach ($db['students'] as &$st) {
            if (empty($st['password'])) {
                $st['password'] = Utils::generateStudentPassword();
                $dirty = true;
            }
        }
        unset($st);

        $db['active_attempts'] = $db['active_attempts'] ?? [];
        $db['student_sessions'] = $db['student_sessions'] ?? [];
        $db['audit_logs'] = $db['audit_logs'] ?? [];

        $defaultHost = $_SERVER['SERVER_ADDR'] ?? $_SERVER['LOCAL_ADDR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if ($defaultHost === '::1' || $defaultHost === '') $defaultHost = '127.0.0.1';

        foreach ($db['audit_logs'] as &$log) {
            if (empty($log['ip'])) {
                if (!empty($log['target']) && ($log['target'] === '::1' || preg_match('/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/', $log['target']))) {
                    $log['ip'] = ($log['target'] === '::1') ? '127.0.0.1' : $log['target'];
                    if ($log['action'] === 'Login guru') {
                        $log['target'] = 'Dashboard Guru';
                    }
                } else {
                    $log['ip'] = $defaultHost;
                }
                $dirty = true;
            } elseif ($log['ip'] === '::1') {
                $log['ip'] = '127.0.0.1';
                $dirty = true;
            }
        }
        unset($log);
        $db['backups'] = BackupService::getBackupsList($db);
        $db['time_extension_requests'] = $db['time_extension_requests'] ?? [];
        $db['settings'] = $db['settings'] ?? [];

        if (!isset($db['settings']['require_student_password'])) {
            $db['settings']['require_student_password'] = true;
            $dirty = true;
        }

        $db['teacher_password_hash'] = $db['teacher_password_hash'] ?? DEFAULT_TEACHER_HASH;
        $db['teacher_password_changed'] = $db['teacher_password_changed'] ?? false;
        $db['trash'] = $db['trash'] ?? ['results' => [], 'students' => [], 'quizzes' => []];

        if ($dirty) {
            self::saveRaw($db);
        }

        return $db;
    }

    /**
     * Save raw database to file with atomic write and Anti-Wipeout Shield
     */
    public static function saveRaw(array &$db): void {
        $allowEmpty = !empty($db['__allow_empty_quizzes']);
        unset($db['__allow_empty_quizzes']);

        // ANTI-WIPEOUT SHIELD: Mencegah paket soal terhapus secara tidak sengaja oleh proses lain
        if (empty($db['questions']) && !$allowEmpty) {
            $backup = self::getEmergencyBackupDb();
            if ($backup !== null && !empty($backup['questions'])) {
                $db['questions'] = $backup['questions'];
                error_log('[ExamLAN Pro Anti-Wipeout Shield] Blocked attempt to save empty questions. Recovered ' . count($backup['questions']) . ' quizzes.');
            }
        }

        $json = json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) < 20) {
            error_log('[ExamLAN Pro Error] json_encode failed in Database::saveRaw, write aborted.');
            return;
        }

        // Salin ke file .bak sebelum menimpa database utama
        if (file_exists(EXAMLAN_DB_FILE) && filesize(EXAMLAN_DB_FILE) > 500) {
            @copy(EXAMLAN_DB_FILE, EXAMLAN_DB_FILE . '.bak');
        }

        // Penulisan atomik: Tulis ke berkas sementara terlebih dahulu
        $tempFile = EXAMLAN_DB_FILE . '.' . uniqid('atomic_', true) . '.tmp';
        $written = @file_put_contents($tempFile, $json, LOCK_EX);
        if ($written === false || $written < strlen($json)) {
            @unlink($tempFile);
            error_log('[ExamLAN Pro Error] Failed to write temporary database file.');
            return;
        }

        // Ganti berkas target secara atomik (tanpa pernah membuat file 0 byte)
        if (!@rename($tempFile, EXAMLAN_DB_FILE)) {
            @copy($tempFile, EXAMLAN_DB_FILE);
            @unlink($tempFile);
        }
    }

    /**
     * Load database with thread-safe shared lock
     */
    public static function load(): array {
        if (self::$cachedDb !== null) {
            return self::$cachedDb;
        }

        self::acquireLock(LOCK_SH);
        try {
            $db = self::loadRaw();
            self::$cachedDb = $db;
            return $db;
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Save database with exclusive thread-safe lock
     */
    public static function save(array $db): void {
        self::acquireLock(LOCK_EX);
        try {
            self::saveRaw($db);
            self::$cachedDb = $db;
        } finally {
            self::releaseLock();
        }
    }

    /**
     * Atomic transactional read-modify-write helper
     */
    public static function modify(callable $callback): mixed {
        self::acquireLock(LOCK_EX);
        try {
            self::$cachedDb = null;
            $db = self::loadRaw();
            $result = $callback($db);
            self::saveRaw($db);
            self::$cachedDb = $db;
            return $result;
        } finally {
            self::releaseLock();
        }
    }
}
