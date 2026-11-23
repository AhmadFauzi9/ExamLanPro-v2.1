<?php
/**
 * ExamLAN Pro - Teacher Authentication Controller
 */

class TeacherAuthController {
    public static function login(): void {
        $input = Utils::getJsonInput();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $limit = $_SESSION['teacher_login_limit'] ?? ['count' => 0, 'until' => 0];

        if ($limit['until'] > time()) {
            Response::tooManyRequests('Terlalu banyak percobaan login. Coba lagi beberapa menit lagi.');
        }

        $db = Database::load();
        $hash = $db['teacher_password_hash'] ?? null;
        if (!is_string($hash) || !preg_match('/^\$2[ayb]\$\d{2}\$[A-Za-z0-9.\/]{53}$/', $hash)) {
            $hash = DEFAULT_TEACHER_HASH;
        }

        if (password_verify((string)($input['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['teacher_authenticated'] = true;
            $_SESSION['teacher_last_activity'] = time();
            $_SESSION['teacher_login_limit'] = ['count' => 0, 'until' => 0];

            AuditService::log($db, 'Login guru', 'Dashboard Guru', 'Autentikasi sesi berhasil', 'guru', $ip);
            Database::save($db);

            Response::success([
                'message' => 'Login guru berhasil.',
                'must_change_password' => empty($db['teacher_password_changed'])
            ]);
        } else {
            $count = (int)$limit['count'] + 1;
            $_SESSION['teacher_login_limit'] = ['count' => $count, 'until' => $count >= 5 ? time() + 300 : 0];
            Response::error('Password guru salah.', 401);
        }
    }

    public static function changePassword(): void {
        AuthMiddleware::teacherOnly();
        $input = Utils::getJsonInput();
        $newPassword = (string)($input['new_password'] ?? '');

        if (strlen($newPassword) < 8) {
            Response::error('Password baru minimal 8 karakter.', 400);
        }

        $db = Database::load();
        $db['teacher_password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        $db['teacher_password_changed'] = true;

        AuditService::log($db, 'Perubahan password guru');
        Database::save($db);

        Response::success([], 'Password guru berhasil diganti.');
    }

    public static function logout(): void {
        $db = Database::load();
        AuditService::log($db, 'Logout guru');
        Database::save($db);

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        Response::success([], 'Sesi guru dikunci.');
    }

    public static function status(): void {
        $isAuthenticated = AuthMiddleware::isTeacher();
        if ($isAuthenticated) {
            $_SESSION['teacher_last_activity'] = time();
            $db = Database::load();
            Response::success([
                'authenticated' => true,
                'must_change_password' => empty($db['teacher_password_changed'])
            ]);
        } else {
            $_SESSION['teacher_authenticated'] = false;
            Response::success([
                'authenticated' => false,
                'must_change_password' => false
            ]);
        }
    }
}
