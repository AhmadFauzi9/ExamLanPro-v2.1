<?php
/**
 * ExamLAN Pro - Audit Logging Service
 */

class AuditService {
    public static function getClientIp(): string {
        $ip = '';
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $ip = trim($ip);
        if ($ip === '::1' || $ip === '') {
            $ip = '127.0.0.1';
        }

        return $ip;
    }

    public static function log(array &$db, string $action, string $target = '', string $detail = '', string $user = 'guru', ?string $ip = null): void {
        if (!isset($db['audit_logs']) || !is_array($db['audit_logs'])) {
            $db['audit_logs'] = [];
        }

        $clientIp = $ip ?: self::getClientIp();
        if ($clientIp === '::1' || $clientIp === '') {
            $clientIp = '127.0.0.1';
        }

        // Jika target berisi format IP (misal dari panggilan lama 'Login guru')
        if ($action === 'Login guru' && ($target === '::1' || preg_match('/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/', $target))) {
            if (empty($ip)) {
                $clientIp = ($target === '::1') ? '127.0.0.1' : $target;
            }
            $target = 'Dashboard Guru';
        }

        $db['audit_logs'][] = [
            'time' => date('c'),
            'user' => $user,
            'action' => $action,
            'target' => $target,
            'detail' => $detail,
            'ip' => $clientIp
        ];

        if (count($db['audit_logs']) > MAX_AUDIT_LOGS) {
            $db['audit_logs'] = array_slice($db['audit_logs'], -MAX_AUDIT_LOGS);
        }
    }
}
