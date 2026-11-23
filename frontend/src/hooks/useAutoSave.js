/**
 * ExamLAN Pro - Auto-Save & Offline Answer Synchronization Hook
 */

import { ExamContext } from '../context/ExamContext.js';
import { examApi } from '../api/examApi.js';
import { Storage } from '../utils/storage.js';
import { setText } from '../utils/dom.js';

let saveIndicatorTimer = null;

export function setSaveStatus(text, color = 'text-slate-500') {
    ExamContext.setSaveStatus(text, color);
    const el = document.getElementById('save-status');
    if (el) {
        el.textContent = text;
        el.className = `text-xs font-medium ${color}`;
    }
}

export function queueAnswerSave() {
    clearTimeout(saveIndicatorTimer);
    setSaveStatus('⟳ Menyimpan…', 'text-brand-600');
    saveIndicatorTimer = setTimeout(syncAnswers, 500);
}

export async function syncAnswers() {
    const attemptToken = ExamContext.attemptToken;
    const answers = ExamContext.studentAnswers;
    if (!attemptToken) return;

    try {
        const res = await examApi.saveAnswers(attemptToken, answers);
        if (!res.ok) {
            throw new Error(res.error || res.data?.message || 'Gagal menyimpan jawaban');
        }
        // Simpan backup di sessionStorage (BUKAN localStorage demi privasi & keamanan lab)
        Storage.setPendingAnswers(answers);
        setSaveStatus('✓ Jawaban tersimpan', 'text-emerald-600');
    } catch (e) {
        // Simpan cadangan di sessionStorage jika koneksi LAN terputus
        Storage.setPendingAnswers(answers);
        setSaveStatus('⚠ Koneksi terputus — jawaban disimpan lokal', 'text-amber-600');
    }
}

export function initAutoSaveNetworkListener() {
    window.addEventListener('online', async () => {
        try {
            // 1. Cek apakah ada antrean pengiriman ujian yang tertunda (pending submission)
            const pendingSub = Storage.getPendingSubmission();
            if (pendingSub && examApi) {
                try {
                    const submitRes = await examApi.submitResult(pendingSub);
                    if (submitRes.ok && submitRes.data?.status === 'success') {
                        Storage.clearPendingSubmission();
                        Storage.clearActiveAttempt();
                        if (typeof window.executeLiveReload === 'function') {
                            window.executeLiveReload();
                            return;
                        }
                    }
                } catch (subErr) {
                    console.warn('Gagal submit pending saat online event:', subErr);
                }
            }

            // 2. Sinkronkan jawaban lokal yang tertunda
            const pending = Storage.getPendingAnswers();
            if (pending && ExamContext.attemptToken) {
                ExamContext.setAnswers(pending);
                await syncAnswers();
            }
        } catch (e) {
            console.warn('Gagal sinkronisasi jawaban offline:', e);
        }
    });
}
