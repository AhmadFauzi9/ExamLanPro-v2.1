/**
 * ExamLAN Pro - Quiz Navigation & Submission Controls Component
 */

import { ExamContext } from '../../context/ExamContext.js';
import { StudentContext } from '../../context/StudentContext.js';
import { Storage } from '../../utils/storage.js';
import { examApi } from '../../api/examApi.js';
import { stopTimer } from '../../hooks/useTimer.js';
import { stopAntiCheat } from '../../hooks/useAntiCheat.js';
import { setSaveStatus } from '../../hooks/useAutoSave.js';
import { isQuestionAnswered, jumpToQuestion } from './QuestionPalette.js';
import { renderCurrentQuestion } from './QuestionCard.js';
import { appAlert, appConfirm } from '../common/Modal.js';
import { renderResultScreen } from '../result/ResultScreen.js';
import { startReviewPermissionPolling } from '../result/QuestionBreakdown.js';

export function navigateQuestion(direction) {
    const quiz = ExamContext.currentQuiz;
    if (!quiz || !quiz.questions) return;
    const target = ExamContext.currentQuestionIdx + direction;
    if (target >= 0 && target < quiz.questions.length) {
        ExamContext.setQuestionIndex(target);
        renderCurrentQuestion();
    }
}

export function updateNavControls() {
    const quiz = ExamContext.currentQuiz;
    if (!quiz || !quiz.questions) return;

    const currentIdx = ExamContext.currentQuestionIdx;
    const isFirst = currentIdx === 0;
    const isLast = currentIdx === quiz.questions.length - 1;

    const prevBtn = document.getElementById('btn-prev-question');
    const nextBtn = document.getElementById('btn-next-question');
    const submitBtn = document.getElementById('btn-submit-quiz');

    if (prevBtn) prevBtn.disabled = isFirst;

    if (isLast) {
        nextBtn?.classList.add('hidden');
        submitBtn?.classList.remove('hidden');
    } else {
        nextBtn?.classList.remove('hidden');
        submitBtn?.classList.add('hidden');
    }
}

export async function confirmSubmitQuiz() {
    const quiz = ExamContext.currentQuiz;
    if (!quiz || !quiz.questions) return;

    const totalQ = quiz.questions.length;
    const answers = ExamContext.studentAnswers || {};
    const answeredQ = quiz.questions.filter((_, idx) => isQuestionAnswered(answers[idx])).length;

    if (answeredQ < totalQ) {
        const empty = quiz.questions
            .map((_, i) => i + 1)
            .filter((n, i) => !isQuestionAnswered(answers[i]));

        const ok = await appConfirm(
            `Masih ada ${empty.length} soal yang belum dijawab.\n\nNomor soal kosong: ${empty.join(', ')}\n\nSemua jawaban wajib diisi sebelum ujian dapat dikumpulkan.`,
            'Jawaban Masih Kosong',
            'Kembali ke Soal',
            'Lihat Soal Kosong'
        );
        if (ok) {
            const firstEmpty = empty[0] - 1;
            jumpToQuestion(firstEmpty);
        }
        return;
    } else {
        const ok = await appConfirm(
            'Semua soal sudah dijawab. Yakin ingin mengirimkan seluruh jawaban dan menyelesaikan ujian?',
            'Selesaikan Ujian',
            'Batal',
            'Kirim Jawaban'
        );
        if (!ok) return;
    }

    submitQuiz();
}

export async function submitQuiz(forceSubmit = false) {
    const attemptToken = ExamContext.attemptToken;
    const currentQuiz = ExamContext.currentQuiz;
    const activeStudent = StudentContext.activeStudent;
    const answers = ExamContext.studentAnswers || {};

    const payload = {
        attempt_token: attemptToken,
        force_submit: forceSubmit,
        answers: answers
    };

    try {
        const res = await examApi.submitResult(payload);
        const resJson = res.data;

        if (!res.ok || resJson?.status !== 'success') {
            appAlert(resJson?.message || 'Jawaban gagal dikirim ke server.');
            return;
        }

        // Hentikan timer dan anti-cheat hanya jika pengiriman jawaban berhasil
        stopTimer();
        stopAntiCheat();

        const reviewToken = resJson.review_token || null;
        StudentContext.setReviewToken(reviewToken);

        const submissionRecord = {
            nis: activeStudent.nis || '',
            name: activeStudent.name || '',
            class: activeStudent.classId || '',
            code: currentQuiz.id,
            title: currentQuiz.title,
            score: resJson.score || 0,
            correct: resJson.correct || 0,
            wrong: resJson.wrong || 0,
            date: payload.date || new Date().toISOString(),
            breakdowns: []
        };

        ExamContext.setResult(submissionRecord);
        Storage.clearActiveAttempt();
        Storage.clearPendingSubmission();
        ExamContext.setAttemptToken(null);

        // Simpan sesi hasil ke sessionStorage (BUKAN localStorage)
        Storage.setStudentSession({
            activeStudent,
            quizId: currentQuiz.id,
            reviewToken: reviewToken,
            result: submissionRecord
        });

        renderResultScreen(submissionRecord);
        startReviewPermissionPolling();
    } catch (err) {
        console.error('Gagal mengirim jawaban ke API:', err);
        // Simpan ke outbox antrean submit offline agar jawaban tidak hilang dan dikirim otomatis saat koneksi pulih
        Storage.setPendingSubmission({
            attempt_token: attemptToken,
            force_submit: forceSubmit,
            answers: answers,
            date: new Date().toISOString(),
            nis: activeStudent?.nis || '',
            name: activeStudent?.name || '',
            class: activeStudent?.classId || '',
            quiz_id: currentQuiz?.id || '',
            title: currentQuiz?.title || 'Ujian CBT'
        });
        Storage.setPendingAnswers(answers);
        stopTimer();
        stopAntiCheat();
        setSaveStatus('⚠ Offline: Jawaban siap kirim tersimpan lokal', 'text-amber-600');
        appAlert('Koneksi LAN terputus saat mengirimkan jawaban ke server.\n\nJangan panik! SELURUH JAWABAN ANDA TELAH TERSIMPAN AMAN di komputer ini.\n\nSistem akan otomatis mengirimkannya begitu kabel LAN/jaringan terhubung kembali, atau Anda dapat menekan tombol Refresh pada menu atas.', 'Jawaban Tersimpan Lokal (Offline)');
    }
}

window.navigateQuestion = navigateQuestion;
window.confirmSubmitQuiz = confirmSubmitQuiz;
