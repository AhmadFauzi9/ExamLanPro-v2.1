/**
 * ExamLAN Pro - Quiz Screen & Active Attempt Lifecycle Component
 */

import { ExamContext } from '../../context/ExamContext.js';
import { StudentContext } from '../../context/StudentContext.js';
import { Storage } from '../../utils/storage.js';
import { examApi } from '../../api/examApi.js';
import { authApi } from '../../api/authApi.js';
import { enterFullScreen, initAntiCheat, stopAntiCheat, checkAndEnforceFullScreen, setReloadIntended } from '../../hooks/useAntiCheat.js';
import { startTimer } from '../../hooks/useTimer.js';
import { syncAnswers, setSaveStatus } from '../../hooks/useAutoSave.js';
import { renderCurrentQuestion } from './QuestionCard.js';
import { updateNavbarStudentProfile } from '../common/Header.js';
import { showToast } from '../common/Toast.js';
import { appAlert, closeConfirmReloadModal } from '../common/Modal.js';
import { resetStudentView, renderResultScreen } from '../result/ResultScreen.js';
import { startReviewPermissionPolling } from '../result/QuestionBreakdown.js';

export function saveActiveAttempt(extra = {}) {
    const attemptToken = ExamContext.attemptToken || extra.attempt_token || extra.token;
    const activeStudent = StudentContext.activeStudent;
    const pendingInfo = ExamContext.pendingExamInfo;
    const currentQuiz = ExamContext.currentQuiz || extra.quiz;
    const existing = Storage.getActiveAttempt() || {};

    const attemptRecord = {
        ...existing,
        token: attemptToken || existing.token || '',
        nis: activeStudent?.nis || pendingInfo?.nis || existing.nis || '',
        name: activeStudent?.name || pendingInfo?.name || existing.name || '',
        classId: activeStudent?.classId || pendingInfo?.classId || existing.classId || '',
        quiz: currentQuiz || existing.quiz || null,
        ends_at: extra.ends_at !== undefined ? extra.ends_at : (existing.ends_at || 0),
        server_now: extra.server_now !== undefined ? extra.server_now : (existing.server_now || Math.floor(Date.now() / 1000)),
        violations_count: extra.violations_count !== undefined ? extra.violations_count : (existing.violations_count || 0),
        saved_at: Date.now()
    };

    Storage.setActiveAttempt(attemptRecord);
}

export function clearActiveAttempt() {
    stopAntiCheat();
    Storage.clearActiveAttempt();
    ExamContext.setAttemptToken(null);
}

export function hydrateAttempt(data) {
    Storage.clearPendingExamInfo();
    Storage.clearPendingResumeInfo();
    ExamContext.setPendingExamInfo(null);
    ExamContext.setPendingResumeData(null);

    enterFullScreen();
    initAntiCheat(data.violations_count || 0);
    checkAndEnforceFullScreen();
    setTimeout(() => checkAndEnforceFullScreen(), 250);

    ExamContext.setAttemptToken(data.attempt_token || data.token);
    ExamContext.setCurrentQuiz(data.quiz);

    // Gabungkan jawaban: Jawaban lokal di perangkat ini memiliki prioritas tertinggi agar tidak tertimpa
    const localPending = Storage.getPendingAnswers() || {};
    const serverAnswers = data.answers || {};
    const mergedAnswers = { ...serverAnswers, ...localPending };
    ExamContext.setAnswers(mergedAnswers);
    Storage.setPendingAnswers(mergedAnswers);

    const studentData = {
        nis: data.nis || StudentContext.activeStudent.nis || '',
        name: data.name || StudentContext.activeStudent.name || '',
        classId: data.class || data.classId || StudentContext.activeStudent.classId || ''
    };
    StudentContext.setStudent(studentData);
    saveActiveAttempt(data);
    updateNavbarStudentProfile(studentData.name, studentData.nis, studentData.classId);

    const infoEl = document.getElementById('active-student-info');
    if (infoEl && studentData.name && studentData.nis) {
        infoEl.innerText = `NIS: ${studentData.nis} | Siswa: ${studentData.name} | Kelas: ${studentData.classId}`;
    }

    StudentContext.setReviewToken(null);
    ExamContext.setResult(null);
    ExamContext.setAnswerReviewVisible(false);
    ExamContext.setQuestionIndex(0);

    document.getElementById('student-login-card')?.classList.add('hidden');
    document.getElementById('student-exam-info-screen')?.classList.add('hidden');
    document.getElementById('student-resume-token-screen')?.classList.add('hidden');
    document.getElementById('student-result-screen')?.classList.add('hidden');
    document.getElementById('student-quiz-screen')?.classList.remove('hidden');

    const titleEl = document.getElementById('active-quiz-title');
    if (titleEl) titleEl.innerText = data.quiz?.title || 'Ujian CBT';

    const totalNumEl = document.getElementById('total-questions-num');
    if (totalNumEl) totalNumEl.innerText = (data.quiz?.questions || []).length;

    const remaining = Math.max(0, Number(data.ends_at) - Number(data.server_now));
    ExamContext.setTimeRemaining(remaining);
    startTimer();
    renderCurrentQuestion();
    setSaveStatus('✓ Jawaban tersimpan', 'text-emerald-600');

    setTimeout(() => {
        checkAndEnforceFullScreen();
    }, 300);
}

export async function restoreActiveAttemptAfterRefresh() {
    if (ExamContext.currentStudentResult) return false;

    // 1. Periksa apakah ada pengiriman ujian tertunda (pending submission dari kondisi offline)
    const pendingSubmission = Storage.getPendingSubmission();
    if (pendingSubmission && pendingSubmission.attempt_token) {
        try {
            const submitRes = await examApi.submitResult(pendingSubmission);
            const resJson = submitRes.data;
            if (submitRes.ok && resJson?.status === 'success') {
                Storage.clearPendingSubmission();
                Storage.clearActiveAttempt();
                ExamContext.setAttemptToken(null);
                const submissionRecord = {
                    nis: pendingSubmission.nis || '',
                    name: pendingSubmission.name || '',
                    class: pendingSubmission.class || '',
                    code: pendingSubmission.quiz_id || '',
                    title: pendingSubmission.title || 'Ujian CBT',
                    score: resJson.score || 0,
                    correct: resJson.correct || 0,
                    wrong: resJson.wrong || 0,
                    date: pendingSubmission.date || new Date().toISOString(),
                    breakdowns: []
                };
                ExamContext.setResult(submissionRecord);
                Storage.setStudentSession({
                    activeStudent: {
                        nis: submissionRecord.nis,
                        name: submissionRecord.name,
                        classId: submissionRecord.class
                    },
                    quizId: submissionRecord.code,
                    reviewToken: resJson.review_token || null,
                    result: submissionRecord
                });
                renderResultScreen(submissionRecord);
                startReviewPermissionPolling();
                showToast('✓ Jawaban ujian berhasil diserahkan ke server saat reload!', 'success');
                return true;
            }
        } catch (subErr) {
            console.warn('Masih offline saat mencoba submit pending:', subErr);
        }
    }

    // 2. Membaca sesi aktif dari storage
    const saved = Storage.getActiveAttempt();
    if (!saved || !saved.token) return false;

    if (saved.name && saved.nis) {
        StudentContext.setStudent({
            nis: saved.nis,
            name: saved.name,
            classId: saved.classId || ''
        });
        updateNavbarStudentProfile(saved.name, saved.nis, saved.classId);
    }

    try {
        const res = await examApi.getAttempt(saved.token);
        const d = res.data;

        if (!res.ok || d?.finished || d?.status === 'needs_login') {
            clearActiveAttempt();
            if (d?.status === 'needs_login' && d?.message) {
                showToast(d.message);
            }
            return false;
        }

        hydrateAttempt(d);
        return true;
    } catch (e) {
        // Pemulihan Offline: Jika jaringan LAN terputus tetapi data kuis dan soal tersimpan di cache lokal
        if (saved.quiz && Array.isArray(saved.quiz.questions) && saved.quiz.questions.length > 0) {
            const localAnswers = Storage.getPendingAnswers() || {};
            let remaining = 0;
            if (saved.ends_at) {
                const nowSec = Math.floor(Date.now() / 1000);
                const elapsedSinceSave = Math.max(0, Math.floor((Date.now() - (saved.saved_at || Date.now())) / 1000));
                const estimatedServerNow = (Number(saved.server_now) || nowSec) + elapsedSinceSave;
                remaining = Math.max(0, Number(saved.ends_at) - estimatedServerNow);
            }
            const offlineAttempt = {
                attempt_token: saved.token,
                quiz: saved.quiz,
                answers: localAnswers,
                nis: saved.nis,
                name: saved.name,
                class: saved.classId,
                ends_at: saved.ends_at,
                server_now: saved.ends_at ? (saved.ends_at - remaining) : Math.floor(Date.now() / 1000),
                violations_count: saved.violations_count || 0
            };
            hydrateAttempt(offlineAttempt);
            if (Storage.getPendingSubmission()) {
                setSaveStatus('⚠ Offline: Jawaban tersimpan lokal & menunggu server untuk submit', 'text-amber-600');
                showToast('Koneksi terputus. Ujian telah selesai dijawab & tersimpan aman secara offline.', 'warning');
            } else {
                setSaveStatus('⚠ Mode Offline — Jawaban tersimpan lokal di perangkat', 'text-amber-600');
                showToast('Koneksi server terputus. Ujian dimuat dari penyimpanan lokal offline.', 'warning');
            }
            return true;
        }

        setSaveStatus('⚠ Koneksi terputus — jawaban lokal akan disinkronkan.', 'text-amber-600');
        return false;
    }
}

export async function executeLiveReload() {
    closeConfirmReloadModal();
    const btn = document.getElementById('btn-header-reload');
    const prevHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin text-[11px]"></i> <span class="hidden sm:inline">Memuat...</span>`;
    }
    setSaveStatus('⟳ Menyinkronkan…', 'text-amber-500');

    try {
        // 1. Cek apakah ada antrean pengiriman ujian yang tertunda (pending submission)
        const pendingSubmission = Storage.getPendingSubmission();
        if (pendingSubmission && pendingSubmission.attempt_token) {
            const submitRes = await examApi.submitResult(pendingSubmission);
            const resJson = submitRes.data;
            if (submitRes.ok && resJson?.status === 'success') {
                Storage.clearPendingSubmission();
                Storage.clearActiveAttempt();
                ExamContext.setAttemptToken(null);
                const submissionRecord = {
                    nis: pendingSubmission.nis || StudentContext.activeStudent?.nis || '',
                    name: pendingSubmission.name || StudentContext.activeStudent?.name || '',
                    class: pendingSubmission.class || StudentContext.activeStudent?.classId || '',
                    code: pendingSubmission.quiz_id || ExamContext.currentQuiz?.id || '',
                    title: pendingSubmission.title || ExamContext.currentQuiz?.title || 'Ujian CBT',
                    score: resJson.score || 0,
                    correct: resJson.correct || 0,
                    wrong: resJson.wrong || 0,
                    date: pendingSubmission.date || new Date().toISOString(),
                    breakdowns: []
                };
                ExamContext.setResult(submissionRecord);
                Storage.setStudentSession({
                    activeStudent: {
                        nis: submissionRecord.nis,
                        name: submissionRecord.name,
                        classId: submissionRecord.class
                    },
                    quizId: submissionRecord.code,
                    reviewToken: resJson.review_token || null,
                    result: submissionRecord
                });
                renderResultScreen(submissionRecord);
                startReviewPermissionPolling();
                showToast('✓ Jawaban ujian offline berhasil diserahkan ke server!', 'success');
                return;
            }
        }

        // 2. Simpan jawaban lokal terlebih dahulu jika server online
        await syncAnswers();

        // 3. Refresh attempt data dari server via API
        if (ExamContext.attemptToken) {
            const res = await examApi.getAttempt(ExamContext.attemptToken);
            const d = res.data;
            if (res.ok && d?.status === 'success' && !d.finished) {
                hydrateAttempt(d);
                setSaveStatus('✓ Jawaban tersimpan', 'text-emerald-600');
                showToast('✓ Data ujian berhasil dimuat ulang & tetap dalam layar penuh!', 'success');
                return;
            } else if (d?.status === 'needs_login') {
                appAlert(d?.message || 'Sesi ujian telah direset oleh Pengawas.', 'Sesi Berakhir');
                resetStudentView();
                return;
            }
        }

        const studentDataRes = await authApi.getStudentData();
        if (studentDataRes.ok && studentDataRes.data) {
            ExamContext.setQuizzes(studentDataRes.data.questions || []);
            StudentContext.setRegisteredStudents(studentDataRes.data.students || []);
        }
        showToast('✓ Data kuis berhasil disinkronkan!', 'success');
    } catch (err) {
        if (Storage.getPendingSubmission()) {
            setSaveStatus('⚠ Offline: Jawaban siap kirim tersimpan lokal', 'text-amber-600');
            showToast('Koneksi server masih terputus. Seluruh jawaban sudah selesai & aman di komputer.', 'warning');
        } else {
            setSaveStatus('⚠ Koneksi offline — jawaban lokal aman', 'text-amber-600');
            showToast('Koneksi server terputus. Jawaban lokal tetap tersimpan aman.', 'warning');
        }
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = prevHtml;
        }
    }
}

export async function executeHardBrowserReload() {
    closeConfirmReloadModal();
    // Jalankan sinkronisasi dan muat ulang data secara live agar tetap berada dalam Mode Kiosk Layar Penuh
    await executeLiveReload();
}

window.executeLiveReload = executeLiveReload;
window.executeHardBrowserReload = executeHardBrowserReload;
