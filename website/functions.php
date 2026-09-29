<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $path = 'index.php'): never
{
    header('Location: ' . $path);
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, role, full_name, age, qualification, email, profile_image FROM users WHERE id = :id');
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_login(): array
{
    $user = current_user();

    if (!$user) {
        redirect_to('index.php');
    }

    return $user;
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function normalize_role(string $role): string
{
    if ($role === 'admin') {
        return 'admin';
    }

    return $role === 'caretaker' ? 'caretaker' : 'doctor';
}

function store_profile_image(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile image upload failed.');
    }

    if (($file['size'] ?? 0) > MAX_UPLOAD_SIZE) {
        throw new RuntimeException('Profile image must be under 2 MB.');
    }

    $mime = mime_content_type($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, or WEBP images are allowed.');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0777, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Could not create upload folder.');
    }

    $filename = uniqid('profile_', true) . '.' . $allowed[$mime];
    $targetPath = UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return 'uploads/' . $filename;
}

function get_linked_patient(array $user): ?array
{
    if ($user['role'] === 'admin') {
        return null;
    }

    if ($user['role'] === 'caretaker') {
        $activePatientId = isset($_SESSION['active_patient_id']) ? (int) $_SESSION['active_patient_id'] : 0;

        if ($activePatientId > 0) {
            $stmt = db()->prepare(
                'SELECT p.*, d.email AS doctor_email, d.full_name AS doctor_name,
                        c.email AS caretaker_email, c.full_name AS caretaker_name
                 FROM patients p
                 LEFT JOIN users d ON d.id = p.doctor_id
                 LEFT JOIN users c ON c.id = p.caretaker_id
                 WHERE p.caretaker_id = :user_id AND p.id = :patient_id
                 LIMIT 1'
            );
            $stmt->execute([
                'user_id' => (int) $user['id'],
                'patient_id' => $activePatientId,
            ]);
            $patient = $stmt->fetch();

            if ($patient) {
                return $patient;
            }
        }

        $stmt = db()->prepare(
            'SELECT p.*, d.email AS doctor_email, d.full_name AS doctor_name,
                    c.email AS caretaker_email, c.full_name AS caretaker_name
             FROM patients p
             LEFT JOIN users d ON d.id = p.doctor_id
             LEFT JOIN users c ON c.id = p.caretaker_id
             WHERE p.caretaker_id = :user_id
             ORDER BY p.updated_at DESC, p.id DESC
             LIMIT 1'
        );
    } else {
        $activePatientId = isset($_SESSION['active_patient_id']) ? (int) $_SESSION['active_patient_id'] : 0;

        if ($activePatientId > 0) {
            $stmt = db()->prepare(
                'SELECT p.*, d.email AS doctor_email, d.full_name AS doctor_name,
                        c.email AS caretaker_email, c.full_name AS caretaker_name
                 FROM patients p
                 LEFT JOIN users d ON d.id = p.doctor_id
                 LEFT JOIN users c ON c.id = p.caretaker_id
                 WHERE p.doctor_id = :user_id AND p.id = :patient_id
                 LIMIT 1'
            );
            $stmt->execute([
                'user_id' => (int) $user['id'],
                'patient_id' => $activePatientId,
            ]);
            $patient = $stmt->fetch();

            if ($patient) {
                return $patient;
            }
        }

        $stmt = db()->prepare(
            'SELECT p.*, d.email AS doctor_email, d.full_name AS doctor_name,
                    c.email AS caretaker_email, c.full_name AS caretaker_name
             FROM patients p
             LEFT JOIN users d ON d.id = p.doctor_id
             LEFT JOIN users c ON c.id = p.caretaker_id
             WHERE p.doctor_id = :user_id
             ORDER BY p.updated_at DESC
             LIMIT 1'
        );
    }

    $stmt->execute(['user_id' => (int) $user['id']]);
    $patient = $stmt->fetch();

    return $patient ?: null;
}

function get_caretaker_patients(int $caretakerId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, d.full_name AS doctor_name, d.email AS doctor_email
         FROM patients p
         LEFT JOIN users d ON d.id = p.doctor_id
         WHERE p.caretaker_id = :caretaker_id
         ORDER BY p.patient_name ASC, p.updated_at DESC'
    );
    $stmt->execute(['caretaker_id' => $caretakerId]);

    return $stmt->fetchAll();
}

function get_doctor_patients(int $doctorId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, c.full_name AS caretaker_name, c.email AS caretaker_email
         FROM patients p
         LEFT JOIN users c ON c.id = p.caretaker_id
         WHERE p.doctor_id = :doctor_id
         ORDER BY p.patient_name ASC, p.updated_at DESC'
    );
    $stmt->execute(['doctor_id' => $doctorId]);

    return $stmt->fetchAll();
}

function get_all_doctors(): array
{
    $stmt = db()->query(
        "SELECT u.id, u.full_name, u.age, u.qualification, u.email, u.profile_image, u.created_at,
                COUNT(p.id) AS patient_count
         FROM users u
         LEFT JOIN patients p ON p.doctor_id = u.id
         WHERE u.role = 'doctor'
         GROUP BY u.id
         ORDER BY u.full_name ASC"
    );

    return $stmt->fetchAll();
}

function get_all_patients(): array
{
    $stmt = db()->query(
        "SELECT p.*, d.full_name AS doctor_name, d.email AS doctor_email,
                c.full_name AS caretaker_name, c.email AS caretaker_email
         FROM patients p
         LEFT JOIN users d ON d.id = p.doctor_id
         LEFT JOIN users c ON c.id = p.caretaker_id
         ORDER BY p.patient_name ASC, p.created_at DESC"
    );

    return $stmt->fetchAll();
}

function get_all_caretakers(): array
{
    $stmt = db()->query(
        "SELECT u.id, u.full_name, u.age, u.qualification, u.email, u.profile_image, u.created_at,
                COUNT(p.id) AS patient_count
         FROM users u
         LEFT JOIN patients p ON p.caretaker_id = u.id
         WHERE u.role = 'caretaker'
         GROUP BY u.id
         ORDER BY u.full_name ASC"
    );

    return $stmt->fetchAll();
}

function get_medicines(int $patientId): array
{
    $stmt = db()->prepare('SELECT * FROM medicines WHERE patient_id = :patient_id ORDER BY time_of_day ASC, created_at DESC');
    $stmt->execute(['patient_id' => $patientId]);

    return $stmt->fetchAll();
}

function get_doctor_notes(int $patientId): array
{
    $stmt = db()->prepare(
        'SELECT dn.*, u.full_name AS doctor_name
         FROM doctor_notes dn
         INNER JOIN users u ON u.id = dn.doctor_id
         WHERE dn.patient_id = :patient_id
         ORDER BY dn.updated_at DESC, dn.created_at DESC'
    );
    $stmt->execute(['patient_id' => $patientId]);

    return $stmt->fetchAll();
}

function get_unread_chat_count(int $patientId, int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM chat_messages
         WHERE patient_id = :patient_id AND receiver_id = :receiver_id AND is_read = 0'
    );
    $stmt->execute([
        'patient_id' => $patientId,
        'receiver_id' => $userId,
    ]);

    return (int) $stmt->fetchColumn();
}

function get_chat_messages(int $patientId): array
{
    $stmt = db()->prepare(
        'SELECT cm.id, cm.message_text, cm.created_at, cm.sender_id, cm.receiver_id, cm.is_read,
                u.full_name AS sender_name, u.role AS sender_role
         FROM chat_messages cm
         INNER JOIN users u ON u.id = cm.sender_id
         WHERE cm.patient_id = :patient_id
         ORDER BY cm.created_at ASC, cm.id ASC'
    );
    $stmt->execute(['patient_id' => $patientId]);

    return $stmt->fetchAll();
}

function format_time_display(?string $time): string
{
    if (!$time) {
        return '--';
    }

    $date = DateTime::createFromFormat('H:i:s', $time) ?: DateTime::createFromFormat('H:i', $time);

    return $date ? $date->format('h:i A') : $time;
}

function format_date_display(?string $value): string
{
    if (!$value) {
        return '--';
    }

    try {
        return (new DateTime($value))->format('d M Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function can_access_patient(array $user, array $patient): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }

    if ($user['role'] === 'doctor') {
        return (int) $patient['doctor_id'] === (int) $user['id'];
    }

    return (int) $patient['caretaker_id'] === (int) $user['id'];
}
