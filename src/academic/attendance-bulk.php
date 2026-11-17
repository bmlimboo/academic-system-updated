<?php
// Bulk download of submitted attendance registers for Admin / Exam Cell / DAA.
// One ZIP: an Excel register per module (Register + Eligibility sheets, as in the single download) and an index.csv.
const SA_BULK_MAX = 150;

function saSubmittedRecordIds(array $get): array {
    $sql = "SELECT r.id FROM academic_records r JOIN users u ON u.id=r.owner_id WHERE r.kind='attendance' AND r.status='Submitted'";
    $args = [];
    foreach (['department', 'programme', 'academic_year'] as $key) if (!empty($get[$key]) && is_string($get[$key])) { $sql .= ' AND r.' . $key . '=?'; $args[] = $get[$key]; }
    if (!empty($get['search']) && is_string($get['search'])) { $sql .= ' AND (u.name LIKE ? OR r.module_code LIKE ?)'; $args[] = '%' . $get['search'] . '%'; $args[] = '%' . $get['search'] . '%'; }
    return array_map('intval', array_column(all($sql . ' ORDER BY r.programme,r.study_year,r.semester,r.module_code,r.id', $args), 'id'));
}

function saRegisterBook(array $r): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $rows = saMatrix($r, false);
    $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $book->getActiveSheet()->setTitle('Register');
    foreach ($rows as $i => $row) foreach ($row as $j => $v) $sheet->setCellValueExplicit([$j + 1, $i + 1], (string)$v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $cols = count($rows[1] ?? $rows[0]);
    $last = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cols);
    $sheet->freezePane('D5');
    $sheet->getStyle('A1:' . $last . '4')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
    $sheet->getStyle('A1:' . $last . '4')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF164D72');
    $sheet->getColumnDimension('A')->setWidth(10); $sheet->getColumnDimension('B')->setWidth(20); $sheet->getColumnDimension('C')->setWidth(30);
    for ($c = 4; $c <= $cols; $c++) $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setWidth(18);
    $dateCells = array_slice($rows[0], 3);
    for ($c = 0; $c < count($dateCells);) { $span = 1; while (isset($dateCells[$c + $span]) && $dateCells[$c + $span] === $dateCells[$c]) $span++; if ($span > 1) $sheet->mergeCells([$c + 4, 1, $c + $span + 3, 1]); $c += $span; }
    $endRow = max(4, count($rows));
    $sheet->getStyle('A1:' . $last . $endRow)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FF9CB1C5');
    $sheet->getStyle('A1:' . $last . $endRow)->getAlignment()->setVertical('center');
    $sheet->getStyle('D1:' . $last . $endRow)->getAlignment()->setHorizontal('center');
    $sheet->getStyle('A1:' . $last . '4')->getAlignment()->setWrapText(true);
    for ($row = 1; $row <= $endRow; $row++) { $sheet->getRowDimension($row)->setRowHeight($row <= 4 ? 30 : 24); if ($row >= 5 && $row % 2 === 0) $sheet->getStyle('A' . $row . ':' . $last . $row)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFF2F6FB'); }
    $sheet->getStyle('B5:B' . $endRow)->getNumberFormat()->setFormatCode('@');
    $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(1, 4);
    $summary = []; $report = acAttendance($r);
    foreach ($report['students'] as $student) $summary[] = [count($summary) + 1, $student['id'], $student['name'], $r['contact_hours'], $student['present'], $student['absent'], $student['extra_present'], $student['percentage'], $student['recorded_percentage'], saEligibility($r, $student), $report['complete'] ? 'Complete recorded coverage' : 'Provisional: check missing uploads'];
    acWriteSheet($book->createSheet()->setTitle('Eligibility'), ['Sl. No.', 'Student ID', 'Student Name', 'DPD hours', 'Present', 'Absent', 'Extra class', 'Attendance % (DPD)', 'Recorded-session %', 'Remarks', 'DPD coverage'], $summary);
    return $book;
}

function saBulkDownload(array $official): never {
    acAssert(saReviewer($official), 'Only Admin, Exam Cell and DAA can download all attendance registers.');
    $ids = saSubmittedRecordIds($_GET);
    acAssert((bool)$ids, 'No submitted attendance registers match these filters.');
    acAssert(count($ids) <= SA_BULK_MAX, count($ids) . ' registers match. Narrow the filters (department, programme or academic year) to ' . SA_BULK_MAX . ' or fewer and try again.');
    @set_time_limit(300); ignore_user_abort(true);
    $zipPath = tempnam(sys_get_temp_dir(), 'attzip'); $temps = [$zipPath];
    $zip = new \ZipArchive();
    acAssert($zip->open($zipPath, \ZipArchive::OVERWRITE) === true, 'Unable to prepare the download.');
    $safe = fn($v) => trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$v), '_');
    $index = [['Receipt', 'Module code', 'Module name', 'Programme', 'Academic year', 'Year', 'Semester', 'Section', 'Tutor', 'Last updated', 'File']];
    try {
        foreach ($ids as $id) {
            $r = acGet($official, $id);
            if ($r['kind'] !== 'attendance' || $r['status'] !== 'Submitted') continue;
            $name = $safe($r['module_code']) . '_' . $safe($r['programme']) . '_' . $safe($r['academic_year']) . '_Y' . $safe($r['study_year']) . '_S' . $safe($r['semester']) . '_' . $safe($r['section']) . '_R' . $id . '.xlsx';
            $book = saRegisterBook($r); $tmp = tempnam(sys_get_temp_dir(), 'attreg'); $temps[] = $tmp;
            \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($book, 'Xlsx')->save($tmp);
            $book->disconnectWorksheets(); unset($book);
            $zip->addFile($tmp, $name);
            $tutor = one('SELECT name FROM users WHERE id=?', [$r['owner_id']]);
            $index[] = [$id, $r['module_code'], $r['module_name'], $r['programme'], $r['academic_year'], $r['study_year'], $r['semester'], $r['section'], $tutor['name'] ?? '', $r['updated_at'], $name];
        }
        $csv = "\xEF\xBB\xBF"; $f = fopen('php://temp', 'r+');
        foreach ($index as $row) fputcsv($f, array_map(fn($v) => preg_match('/^[=+@\-\t\r]/', (string)$v) ? "'" . $v : $v, $row));
        rewind($f); $csv .= stream_get_contents($f); fclose($f);
        $zip->addFromString('index.csv', $csv);
        acAssert($zip->close(), 'Unable to create the ZIP file.');
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="Attendance_Registers_' . date('Ymd_His') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
    } finally {
        foreach ($temps as $t) @unlink($t);
    }
    exit;
}
