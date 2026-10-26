<?php
declare(strict_types=1);

require_once __DIR__ . '/http.php';

/**
 * Resumes. Stored OUTSIDE the public web folder (private/uploads/resumes/) under a random name, one per candidate
 * (a new upload replaces the old). Only PDF, DOC and DOCX up to 5 MB, and the file's first bytes must match its type,
 * so a renamed program or picture is refused. Advisors download through /members/api/resume.php, which checks the upline tree.
 */
const RESUME_MAX_BYTES = 5 * 1024 * 1024;
const RESUME_TYPES = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

function resume_dir(array $config): string
{
    $dir = rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/../uploads')), '/') . '/resumes';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new HttpError(500, 'The upload folder could not be created.');
    }
    $deny = dirname($dir) . '/.htaccess';
    if (!file_exists($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n");   // belt and braces: this folder is already outside public_html
    }
    return $dir;
}

/** A safe name to show and to download as: letters, digits, spaces, dots, dashes, underscores; keeps the extension. */
function resume_clean_name(string $name): string
{
    $base = basename(str_replace('\\', '/', $name));
    $base = preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '', $base) ?? '';
    $base = trim(preg_replace('/\s+/', ' ', $base) ?? '', " .");
    return $base === '' ? 'resume' : (mb_strlen($base) > 120 ? mb_substr($base, -120) : $base);
}

/** @return string pdf|doc|docx, or throws */
function resume_detect(string $path, string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!isset(RESUME_TYPES[$ext])) {
        throw new HttpError(422, 'Please upload a PDF or Word file (.pdf, .doc or .docx).');
    }
    $head = (string)file_get_contents($path, false, null, 0, 8);
    $ok = match ($ext) {
        'pdf' => str_starts_with($head, '%PDF-'),
        'doc' => str_starts_with($head, "\xD0\xCF\x11\xE0"),
        'docx' => str_starts_with($head, "PK\x03\x04"),
    };
    if (!$ok) {
        throw new HttpError(422, 'That file does not look like a real ' . strtoupper($ext) . ' file.');
    }
    if ($ext === 'docx' && class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path) !== true || $z->locateName('word/document.xml') === false) {
            throw new HttpError(422, 'That file does not look like a real Word document.');
        }
        $z->close();
    }
    return $ext;
}

/** @return array{id:int, name:string, size:int, uploaded_by:string, created_at:string}|null */
function resume_get(PDO $pdo, int $candidateId): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM candidate_files WHERE candidate_id = ? AND kind = 'resume' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$candidateId]);
    } catch (PDOException $e) {
        error_log('candidate_files missing? ' . $e->getMessage());   // before schema_phase8.sql has been run
        return null;
    }
    $r = $stmt->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => $r['original_name'], 'size' => (int)$r['size_bytes'], 'uploaded_by' => $r['uploaded_by'], 'created_at' => $r['created_at'], 'stored' => $r['stored_name'], 'mime' => $r['mime']] : null;
}

/** Saves the file (replacing any earlier resume). $file = a PHP $_FILES entry. $by = 'candidate' or a member id. */
function resume_store(PDO $pdo, array $config, int $candidateId, array $file, string $by): array
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new HttpError(413, 'That file is too big. The limit is 5 MB.');
    }
    if ($err !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_file($file['tmp_name'])) {
        throw new HttpError(422, 'Please choose a file to upload.');
    }
    $size = (int)filesize($file['tmp_name']);
    if ($size <= 0) {
        throw new HttpError(422, 'That file is empty.');
    }
    if ($size > RESUME_MAX_BYTES) {
        throw new HttpError(413, 'That file is too big. The limit is 5 MB.');
    }
    $ext = resume_detect($file['tmp_name'], (string)($file['name'] ?? ''));
    $dir = resume_dir($config);
    $stored = $candidateId . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest = $dir . '/' . $stored;
    $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $dest) : copy($file['tmp_name'], $dest);
    if (!$moved) {
        throw new HttpError(500, 'The file could not be saved.');
    }
    @chmod($dest, 0640);
    $old = resume_get($pdo, $candidateId);
    $name = resume_clean_name((string)($file['name'] ?? 'resume.' . $ext));
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $ext) {
        $name .= '.' . $ext;
    }
    $pdo->prepare("INSERT INTO candidate_files (candidate_id, kind, original_name, stored_name, mime, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?)")
        ->execute([$candidateId, 'resume', $name, $stored, RESUME_TYPES[$ext], $size, $by]);
    if ($old) {
        resume_remove($pdo, $config, $candidateId, (int)$old['id']);
    }
    return resume_get($pdo, $candidateId);
}

/** Removes one stored resume (row and file), or all of a candidate's when $fileId is null. */
function resume_remove(PDO $pdo, array $config, int $candidateId, ?int $fileId = null): void
{
    try {
        $stmt = $pdo->prepare('SELECT id, stored_name FROM candidate_files WHERE candidate_id = ?' . ($fileId !== null ? ' AND id = ?' : ''));
        $stmt->execute($fileId !== null ? [$candidateId, $fileId] : [$candidateId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return;
    }
    $dir = resume_dir($config);
    foreach ($rows as $r) {
        $path = $dir . '/' . basename((string)$r['stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
        $pdo->prepare('DELETE FROM candidate_files WHERE id = ?')->execute([(int)$r['id']]);
    }
}

function resume_path(array $config, array $resume): string
{
    return resume_dir($config) . '/' . basename((string)$resume['stored']);
}

/** Stored file names for these candidates, collected BEFORE their rows are deleted. @return string[] */
function resume_collect(PDO $pdo, array $candidateIds): array
{
    if (!$candidateIds) {
        return [];
    }
    $in = implode(',', array_map('intval', $candidateIds));
    try {
        return array_map('strval', $pdo->query("SELECT stored_name FROM candidate_files WHERE candidate_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

/** Deletes the files from disk (call after the database delete has been committed). */
function resume_unlink(array $config, array $storedNames): void
{
    if (!$storedNames) {
        return;
    }
    $dir = resume_dir($config);
    foreach ($storedNames as $n) {
        $p = $dir . '/' . basename($n);
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

/** @return array<int,true> candidate ids (from $ids) that have a resume */
function resume_flags(PDO $pdo, array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    try {
        return array_fill_keys(array_map('intval', $pdo->query("SELECT DISTINCT candidate_id FROM candidate_files WHERE kind = 'resume' AND candidate_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN)), true);
    } catch (PDOException $e) {
        return [];
    }
}
