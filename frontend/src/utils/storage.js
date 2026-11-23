/**
 * ExamLAN Pro - Multi-Tab Synchronized Storage Utility
 * 
 * Mendukung sinkronisasi antar-tab browser (menggunakan localStorage & sessionStorage)
 * sehingga ketika siswa membuka tab baru, sesi aktif (informasi ujian atau pengerjaan soal)
 * otomatis dipulihkan langsung ke halaman yang sedang aktif tanpa harus login ulang.
 */

const memoryStore = new Map();

function isStorageSupported(type) {
    try {
        if (typeof window === 'undefined') return false;
        const s = window[type];
        if (!s) return false;
        const testKey = '__examlan_test__';
        s.setItem(testKey, testKey);
        s.removeItem(testKey);
        return true;
    } catch (e) {
        return false;
    }
}

const hasSessionStorage = isStorageSupported('sessionStorage');
const hasLocalStorage = isStorageSupported('localStorage');

export const Storage = {
    KEYS: {
        ACTIVE_ATTEMPT: 'examlan_active_attempt',
        PENDING_ANSWERS: 'examlan_pending_answers',
        STUDENT_SESSION: 'examlan_student_session',
        PENDING_EXAM_INFO: 'examlan_pending_exam_info',
        PENDING_RESUME_INFO: 'examlan_pending_resume_info',
        PENDING_SUBMISSION: 'examlan_pending_submission'
    },

    /**
     * Helper legacy (dibiarkan aman tanpa menghapus sesi aktif antar-tab)
     */
    purgeLegacyLocalStorage() {
        // Jangan hapus sesi aktif agar tab baru dapat membaca sesi yang sedang berjalan
    },

    get(key) {
        try {
            let item = null;
            if (hasSessionStorage) {
                item = window.sessionStorage.getItem(key);
            }
            if (!item && hasLocalStorage) {
                item = window.localStorage.getItem(key);
                if (item && hasSessionStorage) {
                    try { window.sessionStorage.setItem(key, item); } catch (e) {}
                }
            }
            if (item) {
                return JSON.parse(item);
            }
            return memoryStore.get(key) || null;
        } catch (e) {
            console.warn(`Gagal membaca key ${key} dari storage:`, e);
            return null;
        }
    },

    set(key, value) {
        try {
            const serialized = JSON.stringify(value);
            if (hasSessionStorage) {
                window.sessionStorage.setItem(key, serialized);
            }
            if (hasLocalStorage) {
                window.localStorage.setItem(key, serialized);
            }
            memoryStore.set(key, value);
        } catch (e) {
            console.warn(`Gagal menyimpan key ${key} ke storage:`, e);
            memoryStore.set(key, value);
        }
    },

    remove(key) {
        try {
            if (hasSessionStorage) {
                window.sessionStorage.removeItem(key);
            }
            if (hasLocalStorage) {
                window.localStorage.removeItem(key);
            }
            memoryStore.delete(key);
        } catch (e) {}
    },

    // --- Helper Khusus untuk Sesi Siswa & Ujian ---

    getActiveAttempt() {
        return this.get(this.KEYS.ACTIVE_ATTEMPT);
    },

    setActiveAttempt(attemptData) {
        if (!attemptData) {
            this.clearActiveAttempt();
            return;
        }
        this.set(this.KEYS.ACTIVE_ATTEMPT, attemptData);
    },

    clearActiveAttempt() {
        this.remove(this.KEYS.ACTIVE_ATTEMPT);
    },

    getPendingAnswers() {
        return this.get(this.KEYS.PENDING_ANSWERS);
    },

    setPendingAnswers(answers) {
        if (!answers) {
            this.clearPendingAnswers();
            return;
        }
        this.set(this.KEYS.PENDING_ANSWERS, answers);
    },

    clearPendingAnswers() {
        this.remove(this.KEYS.PENDING_ANSWERS);
    },

    getStudentSession() {
        return this.get(this.KEYS.STUDENT_SESSION);
    },

    setStudentSession(sessionData) {
        if (!sessionData) {
            this.clearStudentSession();
            return;
        }
        this.set(this.KEYS.STUDENT_SESSION, sessionData);
    },

    clearStudentSession() {
        this.remove(this.KEYS.STUDENT_SESSION);
    },

    getPendingExamInfo() {
        return this.get(this.KEYS.PENDING_EXAM_INFO);
    },

    setPendingExamInfo(info) {
        if (!info) {
            this.clearPendingExamInfo();
            return;
        }
        this.set(this.KEYS.PENDING_EXAM_INFO, info);
    },

    clearPendingExamInfo() {
        this.remove(this.KEYS.PENDING_EXAM_INFO);
    },

    getPendingResumeInfo() {
        return this.get(this.KEYS.PENDING_RESUME_INFO);
    },

    setPendingResumeInfo(info) {
        if (!info) {
            this.clearPendingResumeInfo();
            return;
        }
        this.set(this.KEYS.PENDING_RESUME_INFO, info);
    },

    clearPendingResumeInfo() {
        this.remove(this.KEYS.PENDING_RESUME_INFO);
    },

    getPendingSubmission() {
        return this.get(this.KEYS.PENDING_SUBMISSION);
    },

    setPendingSubmission(submission) {
        if (!submission) {
            this.clearPendingSubmission();
            return;
        }
        this.set(this.KEYS.PENDING_SUBMISSION, submission);
    },

    clearPendingSubmission() {
        this.remove(this.KEYS.PENDING_SUBMISSION);
    },

    clearAllExamData() {
        Object.values(this.KEYS).forEach(k => this.remove(k));
    }
};
