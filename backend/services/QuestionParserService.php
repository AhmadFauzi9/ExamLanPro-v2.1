<?php
/**
 * ExamLAN Pro - Question Import & Document Parsing Service
 */

class QuestionParserService {
    public static function parseQuestionBlocks(array $blocks): array {
        $questions = [];
        foreach ($blocks as $b) {
            $lines = $b['lines'] ?? [];
            $image = $b['image'] ?? '';
            if (empty($lines)) continue;

            $qData = [
                'type' => 'mc',
                'question' => '',
                'options' => [],
                'answer' => 0,
                'answers' => [],
                'explanation' => '',
                'image' => $image ?: '',
                'premises' => [],
                'targets' => [],
                'pairs' => []
            ];

            $optMap = [];
            $matchingPairs = [];
            $hasExplicitType = false;
            $hasExplicitKey = false;

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;

                if (preg_match('/^TIPE\s*[:=]\s*(.+)$/i', $line, $m)) {
                    $qData['type'] = strtolower(trim($m[1]));
                    $hasExplicitType = true;
                } elseif (preg_match('/^PERTANYAAN\s*[:=]\s*(.+)$/i', $line, $m)) {
                    $qData['question'] = trim($m[1]);
                } elseif (preg_match('/^KASUS\s*[:=]\s*(.+)$/i', $line, $m)) {
                    $qData['passage'] = trim($m[1]);
                } elseif (preg_match('/^PERNYATAAN\s*[:=]\s*(.+)$/i', $line, $m)) {
                    $qData['statement'] = trim($m[1]);
                } elseif (preg_match('/^ALASAN\s*[:=]\s*(.+)$/i', $line, $m)) {
                    $qData['reason'] = trim($m[1]);
                } elseif (preg_match('/^(PASANGAN|JODOHKAN)\s*[:=]\s*(.+)$/i', $line, $m)) {
                    $rawP = trim($m[2]);
                    if (strpos($rawP, '|') !== false) {
                        foreach (explode('|', $rawP) as $sp) {
                            if (trim($sp) !== '') $matchingPairs[] = trim($sp);
                        }
                    } else {
                        $matchingPairs[] = $rawP;
                    }
                } elseif (preg_match('/^(PASANGAN|JODOHKAN)\s*[:=]?$/i', $line)) {
                    // Header penanda bagian menjodohkan
                } elseif ((strpos($line, ' - ') !== false || strpos($line, ' = ') !== false || strpos($line, '=') !== false) && !preg_match('/^(TIPE|PERTANYAAN|KASUS|PERNYATAAN|ALASAN|KUNCI|PEMBAHASAN|TEMPLATE|PETUNJUK)/i', $line)) {
                    if ($qData['type'] === 'matching' || strtolower($qData['type']) === 'jodohkan') {
                        $matchingPairs[] = $line;
                    }
                } elseif (preg_match('/^([A-E])\s*[:\.]\s*(.+)$/i', $line, $m)) {
                    $letter = strtoupper($m[1]);
                    $optMap[$letter] = trim($m[2]);
                } elseif (preg_match('/^(KUNCI(\s*JAWABAN)?|RUBRIK|PEDOMAN)\s*[:=]\s*(.+)$/i', $line, $m)) {
                    $qData['key_raw'] = trim($m[3]);
                    $hasExplicitKey = true;
                } elseif (preg_match('/^PEMBAHASAN\s*[:=]\s*(.+)$/i', $line, $m)) {
                    $qData['explanation'] = trim($m[1]);
                } elseif (empty($qData['question']) && !preg_match('/^(TEMPLATE|PETUNJUK|SOAL\s+\d+|Paket\s+\d+)/i', $line)) {
                    $qData['question'] = $line;
                }
            }

            $type = $qData['type'];
            if ($type === 'pilihan_ganda' || $type === 'pg') $type = 'mc';
            if ($type === 'pgk' || $type === 'ganda_kompleks' || $type === 'kompleks') $type = 'complex';
            if ($type === 'jodohkan' || $type === 'menjodohkan') $type = 'matching';
            if ($type === 'isian' || $type === 'isian_singkat' || $type === 'short') $type = 'short_answer';
            if ($type === 'esai' || $type === 'uraian' || $type === 'essay') $type = 'essay';
            $qData['type'] = $type;

            if ($type === 'mc') {
                $opts = [];
                foreach (['A', 'B', 'C', 'D', 'E'] as $let) {
                    if (isset($optMap[$let])) $opts[] = $optMap[$let];
                }
                $qData['options'] = $opts ?: ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D'];
                $key = $qData['key_raw'] ?? '0';
                if (is_numeric($key)) {
                    $qData['answer'] = (int)$key;
                } else {
                    $letIdx = ord(strtoupper($key[0] ?? 'A')) - 65;
                    $qData['answer'] = max(0, min(count($qData['options']) - 1, $letIdx));
                }
            } elseif ($type === 'complex') {
                $opts = [];
                foreach (['A', 'B', 'C', 'D', 'E'] as $let) {
                    if (isset($optMap[$let])) $opts[] = $optMap[$let];
                }
                $qData['options'] = $opts ?: ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D'];
                $keyRaw = $qData['key_raw'] ?? '0';
                $parts = explode(',', $keyRaw);
                $ans = [];
                foreach ($parts as $p) {
                    $p = trim($p);
                    if (is_numeric($p)) {
                        $ans[] = (int)$p;
                    } else {
                        $letIdx = ord(strtoupper($p[0] ?? 'A')) - 65;
                        if ($letIdx >= 0 && $letIdx < count($qData['options'])) $ans[] = $letIdx;
                    }
                }
                $qData['answers'] = array_values(array_unique($ans));
            } elseif ($type === 'matching') {
                $premises = [];
                $targets = [];
                $pairs = [];
                $idx = 0;
                $rawPairs = !empty($matchingPairs) ? $matchingPairs : $optMap;
                foreach ($rawPairs as $k => $val) {
                    $prem = '';
                    $targ = '';
                    if (strpos($val, '=') !== false) {
                        $split = explode('=', $val, 2);
                        $prem = trim($split[0]);
                        $targ = trim($split[1]);
                    } elseif (strpos($val, ' - ') !== false) {
                        $split = explode(' - ', $val, 2);
                        $prem = trim($split[0]);
                        $targ = trim($split[1]);
                    } elseif (strpos($val, ' : ') !== false || strpos($val, ':') !== false) {
                        $split = explode(':', $val, 2);
                        $prem = trim($split[0]);
                        $targ = trim($split[1]);
                    } else {
                        $prem = is_string($k) ? "Pernyataan $k" : "Premis " . ($idx + 1);
                        $targ = $val;
                    }
                    if ($prem && $targ) {
                        $premises[] = $prem;
                        $targets[] = $targ;
                        $pairs[(string)$idx] = $idx;
                        $idx++;
                    }
                }
                if (!empty($qData['key_raw'])) {
                    $customPairs = [];
                    $customParts = explode(',', (string)$qData['key_raw']);
                    foreach ($customParts as $cp) {
                        if (strpos($cp, '=') !== false) {
                            [$pPart, $tPart] = explode('=', trim($cp), 2);
                            $pPart = trim($pPart);
                            $tPart = trim($tPart);
                            $pIdx = is_numeric($pPart) ? (int)$pPart : (ord(strtoupper($pPart[0])) - 65);
                            $tIdx = is_numeric($tPart) ? (int)$tPart : (ord(strtoupper($tPart[0])) - 65);
                            $customPairs[(string)$pIdx] = $tIdx;
                        }
                    }
                    if (!empty($customPairs)) {
                        $pairs = $customPairs;
                    }
                }
                $qData['premises'] = $premises;
                $qData['targets'] = $targets;
                $qData['pairs'] = $pairs;
                $qData['answer'] = $pairs;
            } elseif ($type === 'short_answer') {
                $qData['answer'] = trim($qData['key_raw'] ?? '');
            } elseif ($type === 'essay') {
                $qData['answer'] = trim($qData['key_raw'] ?? '');
            } elseif ($type === 'cause_effect') {
                $qData['statement'] = $qData['statement'] ?? '';
                $qData['reason'] = $qData['reason'] ?? '';
                $rawKey = trim((string)($qData['key_raw'] ?? 'both_correct_connected'));
                $qData['answer'] = Utils::normalizeCauseEffectKey($rawKey);
            } elseif ($type === 'case_analysis') {
                $opts = [];
                foreach (['A', 'B', 'C', 'D', 'E'] as $let) {
                    if (isset($optMap[$let])) $opts[] = $optMap[$let];
                }
                $qData['options'] = $opts ?: ['Opsi A', 'Opsi B', 'Opsi C', 'Opsi D'];
                $key = trim((string)($qData['key_raw'] ?? '0'));
                if (preg_match('/^[A-E]$/i', $key)) {
                    $qData['answer'] = ord(strtoupper($key)) - 65;
                } elseif (is_numeric($key)) {
                    $intKey = (int)$key;
                    if ($intKey >= 0 && $intKey < count($qData['options'])) {
                        $qData['answer'] = $intKey;
                    } elseif ($intKey >= 1 && $intKey <= count($qData['options'])) {
                        $qData['answer'] = $intKey - 1;
                    } else {
                        $qData['answer'] = 0;
                    }
                } else {
                    $qData['answer'] = 0;
                }
            }

            unset($qData['key_raw']);

            // Lewati blok yang bukan merupakan butir soal (misal: header dokumen/petunjuk template)
            if (!$hasExplicitType && !$hasExplicitKey && empty($optMap) && empty($matchingPairs) && empty($qData['statement']) && empty($qData['passage'])) {
                continue;
            }

            if (!empty($qData['question']) || !empty($qData['statement'])) {
                $questions[] = $qData;
            }
        }
        return $questions;
    }

    public static function parseDocx(string $filePath, string $uploadsDir, string $quizId = 'quiz') {
        return self::parseDocxQuestions($filePath, $uploadsDir, $quizId);
    }

    public static function parseDocHtml(string $filePath, string $uploadsDir, string $quizId = 'quiz') {
        return self::parseHtmlDocQuestions($filePath, $uploadsDir, $quizId);
    }

    public static function parseXlsx(string $filePath, string $uploadsDir, string $quizId = 'quiz') {
        return self::parseXlsxQuestionsWithImages($filePath, $uploadsDir, $quizId);
    }

    public static function parseText(string $text, string $quizId = 'quiz'): array {
        return self::parseTextQuestions($text, $quizId);
    }

    public static function parseCsv(string $filePath, string $quizId = 'quiz') {
        return self::parseCsvQuestions($filePath, $quizId);
    }

    public static function parseDocxQuestions(string $filePath, string $uploadsDir, string $quizId = 'quiz') {
        if (!file_exists($filePath)) return ['error' => 'Berkas tidak ditemukan.'];
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return ['error' => 'Format berkas Word (.docx) tidak valid.'];

        $relsMap = [];
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($relsXml) {
            $dom = new DOMDocument();
            @$dom->loadXML($relsXml);
            $rels = $dom->getElementsByTagName('Relationship');
            foreach ($rels as $r) {
                $id = $r->getAttribute('Id');
                $target = $r->getAttribute('Target');
                $type = $r->getAttribute('Type');
                if (strpos($type, 'image') !== false || preg_match('/\.(png|jpe?g|gif|webp)$/i', $target)) {
                    $target = ltrim($target, '/');
                    if (strpos($target, 'word/') !== 0 && strpos($target, 'media/') === 0) {
                        $target = 'word/' . $target;
                    }
                    $relsMap[$id] = $target;
                }
            }
        }

        $docXml = $zip->getFromName('word/document.xml');
        if (!$docXml) {
            $zip->close();
            return ['error' => 'document.xml tidak ditemukan di dalam berkas docx.'];
        }

        $dom = new DOMDocument();
        @$dom->loadXML($docXml);

        $paras = $dom->getElementsByTagName('p');
        $blocks = [];
        $currentLines = [];
        $currentImage = null;

        foreach ($paras as $p) {
            $tNodes = $p->getElementsByTagName('t');
            $paraText = '';
            foreach ($tNodes as $t) {
                $paraText .= $t->nodeValue;
            }
            $paraText = trim($paraText);

            $imgNodes = [];
            $blips = $p->getElementsByTagName('blip');
            foreach ($blips as $b) {
                $rId = $b->getAttribute('r:embed') ?: $b->getAttribute('embed');
                if ($rId && isset($relsMap[$rId])) $imgNodes[] = $relsMap[$rId];
            }
            $imagedatas = $p->getElementsByTagName('imagedata');
            foreach ($imagedatas as $im) {
                $rId = $im->getAttribute('r:id') ?: $im->getAttribute('id');
                if ($rId && isset($relsMap[$rId])) $imgNodes[] = $relsMap[$rId];
            }

            if (!empty($imgNodes)) {
                $firstImgPath = $imgNodes[0];
                $imgBinary = $zip->getFromName($firstImgPath);
                if ($imgBinary) {
                    if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                    $ext = pathinfo($firstImgPath, PATHINFO_EXTENSION) ?: 'png';
                    $safeName = 'img_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $quizId) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                    $destPath = $uploadsDir . '/' . $safeName;
                    if (file_put_contents($destPath, $imgBinary) !== false) {
                        $currentImage = 'uploads/soal/' . $safeName;
                    }
                }
            }

            if (trim($paraText) === '---' || trim($paraText) === '===' || preg_match('/^Soal\s+(\d+)[:\.]?/i', $paraText)) {
                if (!empty($currentLines)) {
                    $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
                    $currentLines = [];
                    $currentImage = null;
                }
                if (preg_match('/^Soal\s+(\d+)[:\.]?/i', $paraText)) {
                    $currentLines[] = $paraText;
                }
            } else {
                if ($paraText !== '') {
                    $currentLines[] = $paraText;
                }
            }
        }
        if (!empty($currentLines)) {
            $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
        }
        $zip->close();

        return self::parseQuestionBlocks($blocks);
    }

    public static function parseHtmlDocQuestions(string $filePath, string $uploadsDir, string $quizId = 'quiz') {
        if (!file_exists($filePath)) return ['error' => 'Berkas tidak ditemukan.'];
        $html = file_get_contents($filePath);
        if (!$html) return ['error' => 'Berkas kosong.'];

        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//p | //div | //h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //li | //tr');

        $blocks = [];
        $currentLines = [];
        $currentImage = null;

        foreach ($elements as $el) {
            $imgs = $el->getElementsByTagName('img');
            foreach ($imgs as $img) {
                $src = $img->getAttribute('src');
                if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $src, $m)) {
                    $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
                    $b64 = substr($src, strpos($src, ',') + 1);
                    $bin = base64_decode($b64, true);
                    if ($bin) {
                        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                        $name = 'img_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $quizId) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                        if (file_put_contents($uploadsDir . '/' . $name, $bin) !== false) {
                            $currentImage = 'uploads/soal/' . $name;
                        }
                    }
                } elseif (strpos($src, 'uploads/soal/') !== false) {
                    $currentImage = $src;
                }
            }

            $text = trim($el->textContent);
            if ($text === '') continue;

            if ($text === '---' || $text === '===' || preg_match('/^Soal\s+(\d+)[:\.]?/i', $text)) {
                if (!empty($currentLines)) {
                    $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
                    $currentLines = [];
                    $currentImage = null;
                }
                if (preg_match('/^Soal\s+(\d+)[:\.]?/i', $text)) {
                    $currentLines[] = $text;
                }
            } else {
                $currentLines[] = $text;
            }
        }

        if (!empty($currentLines)) {
            $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
        }

        return self::parseQuestionBlocks($blocks);
    }

    public static function parseXlsxQuestionsWithImages(string $filePath, string $uploadsDir, string $quizId = 'quiz') {
        if (!file_exists($filePath)) return ['error' => 'Berkas tidak ditemukan.'];
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) return ['error' => 'Format berkas Excel (.xlsx) tidak valid.'];

        $rowImages = [];
        $drawingRelsXml = $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
        $drawingXml = $zip->getFromName('xl/drawings/drawing1.xml');
        if ($drawingRelsXml && $drawingXml) {
            $relMap = [];
            $dDom = new DOMDocument();
            @$dDom->loadXML($drawingRelsXml);
            foreach ($dDom->getElementsByTagName('Relationship') as $r) {
                $rId = $r->getAttribute('Id');
                $target = $r->getAttribute('Target');
                $target = ltrim(str_replace('../', 'xl/', $target), '/');
                $relMap[$rId] = $target;
            }

            $drawDom = new DOMDocument();
            @$drawDom->loadXML($drawingXml);
            $twoCells = $drawDom->getElementsByTagName('twoCellAnchor');
            foreach ($twoCells as $tc) {
                $fromRow = null;
                $fromRows = $tc->getElementsByTagName('from');
                if ($fromRows->length > 0) {
                    $rowEls = $fromRows->item(0)->getElementsByTagName('row');
                    if ($rowEls->length > 0) $fromRow = (int)$rowEls->item(0)->nodeValue;
                }
                $blips = $tc->getElementsByTagName('blip');
                if ($blips->length > 0 && $fromRow !== null) {
                    $rId = $blips->item(0)->getAttribute('r:embed');
                    if ($rId && isset($relMap[$rId])) {
                        $mediaPath = $relMap[$rId];
                        $bin = $zip->getFromName($mediaPath);
                        if ($bin) {
                            if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0755, true);
                            $ext = pathinfo($mediaPath, PATHINFO_EXTENSION) ?: 'png';
                            $imgName = 'img_soal_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $quizId) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                            if (file_put_contents($uploadsDir . '/' . $imgName, $bin) !== false) {
                                $rowImages[$fromRow + 1] = 'uploads/soal/' . $imgName;
                            }
                        }
                    }
                }
            }
        }

        $sharedStrings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $sDom = new DOMDocument();
            @$sDom->loadXML($ssXml);
            foreach ($sDom->getElementsByTagName('si') as $si) {
                $sharedStrings[] = $si->textContent;
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) {
            $zip->close();
            return ['error' => 'Lembar sheet1.xml tidak ditemukan di dalam berkas Excel.'];
        }

        $shDom = new DOMDocument();
        @$shDom->loadXML($sheetXml);
        $rows = $shDom->getElementsByTagName('row');

        $allRows = [];
        foreach ($rows as $rowEl) {
            $rowNum = (int)$rowEl->getAttribute('r');
            $cCells = $rowEl->getElementsByTagName('c');
            $rowData = [];

            foreach ($cCells as $c) {
                $cellRef = $c->getAttribute('r');
                $colLetter = preg_replace('/[0-9]/', '', $cellRef);
                $type = $c->getAttribute('t');
                $vEls = $c->getElementsByTagName('v');
                $val = '';
                if ($vEls->length > 0) {
                    $rawVal = $vEls->item(0)->nodeValue;
                    if ($type === 's' && isset($sharedStrings[(int)$rawVal])) {
                        $val = $sharedStrings[(int)$rawVal];
                    } else {
                        $val = $rawVal;
                    }
                } else {
                    $tEls = $c->getElementsByTagName('t');
                    if ($tEls->length > 0) $val = $tEls->item(0)->nodeValue;
                }
                $rowData[$colLetter] = trim($val);
            }
            if (!empty($rowData)) {
                $allRows[$rowNum] = $rowData;
            }
        }

        $blocks = [];
        $firstRow = reset($allRows) ?: [];
        $firstA = strtolower(preg_replace('/[^a-z]/', '', $firstRow['A'] ?? ''));
        $firstB = strtolower(preg_replace('/[^a-z]/', '', $firstRow['B'] ?? ''));
        $isHorizontal = ($firstA === 'tipe' || $firstA === 'type') && ($firstB === 'pertanyaan' || $firstB === 'question');

        if ($isHorizontal) {
            // === LOGIKA HORIZONTAL (FORMAT LAMA - UNTUK KOMPATIBILITAS FILE SEBELUMNYA) ===
            $headerMap = [];
            foreach ($allRows as $rowNum => $rowData) {
                if ($rowNum === 1 || empty($headerMap)) {
                    foreach ($rowData as $col => $name) {
                        $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $name));
                        $headerMap[$col] = $cleanName;
                    }
                } else {
                    $namedRow = [];
                    foreach ($rowData as $col => $v) {
                        $k = $headerMap[$col] ?? $col;
                        $namedRow[$k] = $v;
                    }

                    $img = $rowImages[$rowNum] ?? ($namedRow['gambar'] ?? '');

                    $lines = [];
                    $typeVal = $namedRow['tipe'] ?? ($namedRow['type'] ?? 'mc');
                    if (empty($typeVal) && empty($namedRow['pertanyaan'])) continue;

                    $lines[] = 'TIPE: ' . ($typeVal ?: 'mc');
                    if (!empty($namedRow['pertanyaan'])) $lines[] = 'PERTANYAAN: ' . $namedRow['pertanyaan'];
                    $caseVal = $namedRow['teks_kasus'] ?? ($namedRow['kasus'] ?? ($namedRow['bacaan'] ?? ''));
                    if (!empty($caseVal)) $lines[] = 'KASUS: ' . $caseVal;
                    $stmtVal = $namedRow['pernyataan'] ?? ($namedRow['statement'] ?? '');
                    if (!empty($stmtVal)) $lines[] = 'PERNYATAAN: ' . $stmtVal;
                    $reasonVal = $namedRow['alasan'] ?? ($namedRow['reason'] ?? '');
                    if (!empty($reasonVal)) $lines[] = 'ALASAN: ' . $reasonVal;
                    if (!empty($namedRow['opsi_a'])) $lines[] = 'A: ' . $namedRow['opsi_a'];
                    if (!empty($namedRow['opsi_b'])) $lines[] = 'B: ' . $namedRow['opsi_b'];
                    if (!empty($namedRow['opsi_c'])) $lines[] = 'C: ' . $namedRow['opsi_c'];
                    if (!empty($namedRow['opsi_d'])) $lines[] = 'D: ' . $namedRow['opsi_d'];
                    if (!empty($namedRow['opsi_e'])) $lines[] = 'E: ' . $namedRow['opsi_e'];
                    $matchVal = $namedRow['pasangan_menjodohkan'] ?? ($namedRow['jodohkan'] ?? ($namedRow['pasangan'] ?? ''));
                    if (!empty($matchVal)) {
                        $lines[] = 'PASANGAN: ' . $matchVal;
                    }
                    $keyVal = $namedRow['kunci_jawaban'] ?? ($namedRow['kunci'] ?? ($namedRow['jawaban'] ?? ($namedRow['rubrik'] ?? ($namedRow['pedoman'] ?? ''))));
                    if (!empty($keyVal)) $lines[] = 'KUNCI: ' . $keyVal;
                    if (!empty($namedRow['pembahasan'])) $lines[] = 'PEMBAHASAN: ' . $namedRow['pembahasan'];

                    $blocks[] = ['lines' => $lines, 'image' => $img];
                }
            }
        } else {
            // === LOGIKA VERTIKAL (TABEL KE BAWAH: NO SOAL | BAGIAN / LABEL | ISI / TEKS) ===
            $currentLines = [];
            $currentImage = null;
            $currentQNum = null;

            foreach ($allRows as $rowNum => $rowData) {
                if (isset($rowImages[$rowNum])) {
                    $currentImage = $rowImages[$rowNum];
                }

                $colA = trim((string)($rowData['A'] ?? ''));
                $colB = trim((string)($rowData['B'] ?? ''));
                $colC = trim((string)($rowData['C'] ?? ''));

                if ($colA === '' && $colB === '' && $colC === '') continue;

                // Abaikan header tabel pada baris 1 (misal "NO SOAL", "BAGIAN / LABEL", "ISI / TEKS SOAL")
                if ($rowNum === 1) {
                    $cleanA = strtolower(preg_replace('/[^a-z]/', '', $colA));
                    $cleanB = strtolower(preg_replace('/[^a-z]/', '', $colB));
                    if (($cleanA === 'no' || $cleanA === 'nosoal' || $cleanA === 'nomor') || ($cleanB === 'bagian' || $cleanB === 'label' || $cleanB === 'bagianlabel')) {
                        continue;
                    }
                }

                // Pemisah antar soal dengan "---" atau "==="
                if ($colA === '---' || $colA === '===' || $colB === '---' || $colB === '===') {
                    if (!empty($currentLines)) {
                        $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
                        $currentLines = [];
                        $currentImage = null;
                        $currentQNum = null;
                    }
                    continue;
                }

                $rowQNum = '';
                $label = '';
                $val = '';

                if (isset($rowData['C'])) {
                    // 3 kolom: A=No, B=Label, C=Isi
                    $rowQNum = $colA;
                    $label = $colB;
                    $val = $colC;
                } elseif (isset($rowData['B'])) {
                    // 2 kolom: A=Label, B=Isi
                    $label = $colA;
                    $val = $colB;
                } else {
                    $val = $colA;
                }

                if ($label === '' && preg_match('/^(TIPE|TYPE|PERTANYAAN|SOAL|QUESTION|KASUS|BACAAN|PERNYATAAN|ALASAN|A|B|C|D|E|OPSI\s*[A-E]|PASANGAN|JODOHKAN|KUNCI|PEMBAHASAN)\s*[:=]\s*(.+)$/i', $val, $m)) {
                    $label = $m[1];
                    $val = $m[2];
                }

                $cleanLabel = strtoupper(trim(preg_replace('/[^a-zA-Z0-9]/', '', $label)));

                // Deteksi pergantian nomor soal pada kolom A
                if ($rowQNum !== '' && is_numeric($rowQNum)) {
                    $numVal = (int)$rowQNum;
                    if ($currentQNum !== null && $numVal !== $currentQNum && !empty($currentLines)) {
                        $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
                        $currentLines = [];
                        $currentImage = null;
                    }
                    $currentQNum = $numVal;
                }

                // Jika terdeteksi TIPE baru padahal blok sebelumnya sudah memiliki pertanyaan/opsi, buat blok baru
                if (($cleanLabel === 'TIPE' || $cleanLabel === 'TYPE') && !empty($currentLines)) {
                    $hasQuestionAlready = false;
                    foreach ($currentLines as $cl) {
                        if (strpos($cl, 'PERTANYAAN:') === 0 || strpos($cl, 'A:') === 0 || strpos($cl, 'PERNYATAAN:') === 0) {
                            $hasQuestionAlready = true;
                            break;
                        }
                    }
                    if ($hasQuestionAlready) {
                        $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
                        $currentLines = [];
                        $currentImage = null;
                    }
                }

                if ($cleanLabel === 'TIPE' || $cleanLabel === 'TYPE') {
                    $currentLines[] = 'TIPE: ' . $val;
                } elseif ($cleanLabel === 'PERTANYAAN' || $cleanLabel === 'SOAL' || $cleanLabel === 'QUESTION') {
                    $currentLines[] = 'PERTANYAAN: ' . $val;
                } elseif ($cleanLabel === 'KASUS' || $cleanLabel === 'BACAAN' || $cleanLabel === 'TEKSKASUS' || $cleanLabel === 'PASSAGE') {
                    $currentLines[] = 'KASUS: ' . $val;
                } elseif ($cleanLabel === 'PERNYATAAN' || $cleanLabel === 'STATEMENT') {
                    $currentLines[] = 'PERNYATAAN: ' . $val;
                } elseif ($cleanLabel === 'ALASAN' || $cleanLabel === 'REASON') {
                    $currentLines[] = 'ALASAN: ' . $val;
                } elseif (in_array($cleanLabel, ['A', 'B', 'C', 'D', 'E'])) {
                    $currentLines[] = $cleanLabel . ': ' . $val;
                } elseif (preg_match('/^OPSI([A-E])$/i', $cleanLabel, $om)) {
                    $currentLines[] = strtoupper($om[1]) . ': ' . $val;
                } elseif ($cleanLabel === 'PASANGAN' || $cleanLabel === 'JODOHKAN' || $cleanLabel === 'PAIR') {
                    $currentLines[] = 'PASANGAN: ' . $val;
                } elseif ($cleanLabel === 'KUNCI' || $cleanLabel === 'KUNCIJAWABAN' || $cleanLabel === 'JAWABAN' || $cleanLabel === 'KEY' || $cleanLabel === 'RUBRIK') {
                    $currentLines[] = 'KUNCI: ' . $val;
                } elseif ($cleanLabel === 'PEMBAHASAN' || $cleanLabel === 'EXPLANATION') {
                    $currentLines[] = 'PEMBAHASAN: ' . $val;
                } elseif ($val !== '') {
                    if (!empty($label)) {
                        $currentLines[] = $label . ': ' . $val;
                    } else {
                        $currentLines[] = $val;
                    }
                }
            }

            if (!empty($currentLines)) {
                $blocks[] = ['lines' => $currentLines, 'image' => $currentImage];
            }
        }
        $zip->close();

        return self::parseQuestionBlocks($blocks);
    }

    public static function parseTextQuestions(string $text, string $quizId = 'quiz'): array {
        $rawBlocks = preg_split('/(\r?\n|^)(\-{3,}|\={3,})(\r?\n|$)/', $text);
        $blocks = [];
        foreach ($rawBlocks as $b) {
            $lines = array_filter(array_map('trim', explode("\n", $b)), fn($l) => $l !== '');
            if ($lines) $blocks[] = ['lines' => array_values($lines), 'image' => ''];
        }
        return self::parseQuestionBlocks($blocks);
    }

    public static function parseCsvQuestions(string $filePath, string $quizId = 'quiz') {
        $handle = fopen($filePath, 'r');
        if (!$handle) return ['error' => 'Gagal membuka berkas CSV.'];
        $firstRow = fgetcsv($handle);
        if (!$firstRow) {
            fclose($handle);
            return ['error' => 'Berkas CSV kosong.'];
        }

        $cleanCol0 = strtolower(preg_replace('/[^a-z]/', '', $firstRow[0] ?? ''));
        $cleanCol1 = strtolower(preg_replace('/[^a-z]/', '', $firstRow[1] ?? ''));
        $isHorizontal = ($cleanCol0 === 'tipe' || $cleanCol0 === 'type') && ($cleanCol1 === 'pertanyaan' || $cleanCol1 === 'question');

        if ($isHorizontal) {
            $headerMap = array_map(fn($h) => strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $h)), $firstRow);
            $blocks = [];
            while (($row = fgetcsv($handle)) !== false) {
                $named = [];
                foreach ($row as $i => $v) {
                    $k = $headerMap[$i] ?? "col_$i";
                    $named[$k] = trim($v);
                }
                $lines = [];
                $t = $named['tipe'] ?? ($named['type'] ?? 'mc');
                if (empty($t) && empty($named['pertanyaan'])) continue;
                $lines[] = 'TIPE: ' . ($t ?: 'mc');
                if (!empty($named['pertanyaan'])) $lines[] = 'PERTANYAAN: ' . $named['pertanyaan'];
                $caseVal = $named['teks_kasus'] ?? ($named['kasus'] ?? ($named['bacaan'] ?? ''));
                if (!empty($caseVal)) $lines[] = 'KASUS: ' . $caseVal;
                $stmtVal = $named['pernyataan'] ?? ($named['statement'] ?? '');
                if (!empty($stmtVal)) $lines[] = 'PERNYATAAN: ' . $stmtVal;
                $reasonVal = $named['alasan'] ?? ($named['reason'] ?? '');
                if (!empty($reasonVal)) $lines[] = 'ALASAN: ' . $reasonVal;
                if (!empty($named['opsi_a'])) $lines[] = 'A: ' . $named['opsi_a'];
                if (!empty($named['opsi_b'])) $lines[] = 'B: ' . $named['opsi_b'];
                if (!empty($named['opsi_c'])) $lines[] = 'C: ' . $named['opsi_c'];
                if (!empty($named['opsi_d'])) $lines[] = 'D: ' . $named['opsi_d'];
                if (!empty($named['opsi_e'])) $lines[] = 'E: ' . $named['opsi_e'];
                $matchVal = $named['pasangan_menjodohkan'] ?? ($named['jodohkan'] ?? ($named['pasangan'] ?? ''));
                if (!empty($matchVal)) {
                    $lines[] = 'PASANGAN: ' . $matchVal;
                }
                $keyVal = $named['kunci_jawaban'] ?? ($named['kunci'] ?? ($named['jawaban'] ?? ''));
                if (!empty($keyVal)) $lines[] = 'KUNCI: ' . $keyVal;
                if (!empty($named['pembahasan'])) $lines[] = 'PEMBAHASAN: ' . $named['pembahasan'];
                $blocks[] = ['lines' => $lines, 'image' => $named['gambar'] ?? ''];
            }
            fclose($handle);
            return self::parseQuestionBlocks($blocks);
        } else {
            // Vertikal CSV
            $csvRows = [$firstRow];
            while (($r = fgetcsv($handle)) !== false) {
                $csvRows[] = $r;
            }
            fclose($handle);

            $blocks = [];
            $currentLines = [];
            $currentQNum = null;

            foreach ($csvRows as $idx => $row) {
                $colA = trim((string)($row[0] ?? ''));
                $colB = trim((string)($row[1] ?? ''));
                $colC = trim((string)($row[2] ?? ''));

                if ($colA === '' && $colB === '' && $colC === '') continue;

                if ($idx === 0) {
                    $cleanA = strtolower(preg_replace('/[^a-z]/', '', $colA));
                    $cleanB = strtolower(preg_replace('/[^a-z]/', '', $colB));
                    if (($cleanA === 'no' || $cleanA === 'nosoal' || $cleanA === 'nomor') || ($cleanB === 'bagian' || $cleanB === 'label' || $cleanB === 'bagianlabel')) {
                        continue;
                    }
                }

                if ($colA === '---' || $colA === '===' || $colB === '---' || $colB === '===') {
                    if (!empty($currentLines)) {
                        $blocks[] = ['lines' => $currentLines, 'image' => ''];
                        $currentLines = [];
                        $currentQNum = null;
                    }
                    continue;
                }

                $rowQNum = '';
                $label = '';
                $val = '';

                if (isset($row[2])) {
                    $rowQNum = $colA;
                    $label = $colB;
                    $val = $colC;
                } elseif (isset($row[1])) {
                    $label = $colA;
                    $val = $colB;
                } else {
                    $val = $colA;
                }

                if ($label === '' && preg_match('/^(TIPE|TYPE|PERTANYAAN|SOAL|QUESTION|KASUS|BACAAN|PERNYATAAN|ALASAN|A|B|C|D|E|OPSI\s*[A-E]|PASANGAN|JODOHKAN|KUNCI|PEMBAHASAN)\s*[:=]\s*(.+)$/i', $val, $m)) {
                    $label = $m[1];
                    $val = $m[2];
                }

                $cleanLabel = strtoupper(trim(preg_replace('/[^a-zA-Z0-9]/', '', $label)));

                if ($rowQNum !== '' && is_numeric($rowQNum)) {
                    $numVal = (int)$rowQNum;
                    if ($currentQNum !== null && $numVal !== $currentQNum && !empty($currentLines)) {
                        $blocks[] = ['lines' => $currentLines, 'image' => ''];
                        $currentLines = [];
                    }
                    $currentQNum = $numVal;
                }

                if (($cleanLabel === 'TIPE' || $cleanLabel === 'TYPE') && !empty($currentLines)) {
                    $hasQuestionAlready = false;
                    foreach ($currentLines as $cl) {
                        if (strpos($cl, 'PERTANYAAN:') === 0 || strpos($cl, 'A:') === 0 || strpos($cl, 'PERNYATAAN:') === 0) {
                            $hasQuestionAlready = true;
                            break;
                        }
                    }
                    if ($hasQuestionAlready) {
                        $blocks[] = ['lines' => $currentLines, 'image' => ''];
                        $currentLines = [];
                    }
                }

                if ($cleanLabel === 'TIPE' || $cleanLabel === 'TYPE') {
                    $currentLines[] = 'TIPE: ' . $val;
                } elseif ($cleanLabel === 'PERTANYAAN' || $cleanLabel === 'SOAL' || $cleanLabel === 'QUESTION') {
                    $currentLines[] = 'PERTANYAAN: ' . $val;
                } elseif ($cleanLabel === 'KASUS' || $cleanLabel === 'BACAAN' || $cleanLabel === 'TEKSKASUS' || $cleanLabel === 'PASSAGE') {
                    $currentLines[] = 'KASUS: ' . $val;
                } elseif ($cleanLabel === 'PERNYATAAN' || $cleanLabel === 'STATEMENT') {
                    $currentLines[] = 'PERNYATAAN: ' . $val;
                } elseif ($cleanLabel === 'ALASAN' || $cleanLabel === 'REASON') {
                    $currentLines[] = 'ALASAN: ' . $val;
                } elseif (in_array($cleanLabel, ['A', 'B', 'C', 'D', 'E'])) {
                    $currentLines[] = $cleanLabel . ': ' . $val;
                } elseif (preg_match('/^OPSI([A-E])$/i', $cleanLabel, $om)) {
                    $currentLines[] = strtoupper($om[1]) . ': ' . $val;
                } elseif ($cleanLabel === 'PASANGAN' || $cleanLabel === 'JODOHKAN' || $cleanLabel === 'PAIR') {
                    $currentLines[] = 'PASANGAN: ' . $val;
                } elseif ($cleanLabel === 'KUNCI' || $cleanLabel === 'KUNCIJAWABAN' || $cleanLabel === 'JAWABAN' || $cleanLabel === 'KEY' || $cleanLabel === 'RUBRIK') {
                    $currentLines[] = 'KUNCI: ' . $val;
                } elseif ($cleanLabel === 'PEMBAHASAN' || $cleanLabel === 'EXPLANATION') {
                    $currentLines[] = 'PEMBAHASAN: ' . $val;
                } elseif ($val !== '') {
                    if (!empty($label)) {
                        $currentLines[] = $label . ': ' . $val;
                    } else {
                        $currentLines[] = $val;
                    }
                }
            }

            if (!empty($currentLines)) {
                $blocks[] = ['lines' => $currentLines, 'image' => ''];
            }

            return self::parseQuestionBlocks($blocks);
        }
    }
}
