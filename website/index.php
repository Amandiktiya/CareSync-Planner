<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$flash = null;

try {
    if (is_post()) {
        $action = $_POST['action'] ?? '';

        if ($action === 'register') {
            $role = normalize_role($_POST['role'] ?? 'doctor');

            if ($role === 'admin') {
                throw new RuntimeException('Admin accounts cannot be created from this form.');
            }

            $fullName = trim($_POST['full_name'] ?? '');
            $accountAge = (int) ($_POST['age'] ?? 0);
            $qualification = trim($_POST['qualification'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = trim($_POST['password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');

            if ($fullName === '' || $email === '' || $password === '' || $confirmPassword === '') {
                throw new RuntimeException('Please fill in all account details.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }

            if ($password !== $confirmPassword) {
                throw new RuntimeException('Passwords do not match.');
            }

            if ($role === 'doctor' && ($accountAge <= 0 || $qualification === '')) {
                throw new RuntimeException('Doctor age and qualification are required.');
            }

            $existingStmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $existingStmt->execute(['email' => $email]);

            if ($existingStmt->fetch()) {
                throw new RuntimeException('This email is already registered.');
            }

            $profileImage = store_profile_image($_FILES['profile_picture'] ?? []);

            $stmt = db()->prepare(
                'INSERT INTO users (role, full_name, age, qualification, email, password_hash, profile_image)
                 VALUES (:role, :full_name, :age, :qualification, :email, :password_hash, :profile_image)'
            );
            $stmt->execute([
                'role' => $role,
                'full_name' => $fullName,
                'age' => $accountAge > 0 ? $accountAge : null,
                'qualification' => $qualification !== '' ? $qualification : null,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'profile_image' => $profileImage,
            ]);

            set_flash('success', 'Account created successfully. You can sign in now.');
            redirect_to('index.php?auth=login&role=' . $role);
        }

        if ($action === 'login') {
            $role = normalize_role($_POST['role'] ?? 'doctor');
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = trim($_POST['password'] ?? '');

            $stmt = db()->prepare('SELECT * FROM users WHERE email = :email AND role = :role LIMIT 1');
            $stmt->execute([
                'email' => $email,
                'role' => $role,
            ]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                throw new RuntimeException('Invalid login details. Please check role, email, and password.');
            }

            $_SESSION['user_id'] = (int) $user['id'];
            set_flash('success', 'Welcome back, ' . $user['full_name'] . '.');
            redirect_to('index.php?patient_id=' . (int) $patient['id']);
        }

        $user = require_login();
        $patient = get_linked_patient($user);

        if ($action === 'update_profile') {
            if (!in_array($user['role'], ['doctor', 'admin'], true)) {
                throw new RuntimeException('Only doctors and admins can update their profile.');
            }

            $fullName = trim($_POST['full_name'] ?? '');
            $accountAge = (int) ($_POST['age'] ?? 0);
            $qualification = trim($_POST['qualification'] ?? '');
            $email = strtolower(trim($_POST['email'] ?? ''));

            if ($fullName === '' || $accountAge <= 0 || $qualification === '' || $email === '') {
                throw new RuntimeException('Please fill in all profile details.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }

            $existingStmt = db()->prepare('SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1');
            $existingStmt->execute([
                'email' => $email,
                'id' => (int) $user['id'],
            ]);

            if ($existingStmt->fetch()) {
                throw new RuntimeException('This email is already in use by another account.');
            }

            $profileImage = $user['profile_image'];
            if (isset($_FILES['profile_picture']) && (int) ($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $profileImage = store_profile_image($_FILES['profile_picture']);
            }

            $stmt = db()->prepare(
                'UPDATE users
                 SET full_name = :full_name, age = :age, qualification = :qualification, email = :email, profile_image = :profile_image
                 WHERE id = :id'
            );
            $stmt->execute([
                'full_name' => $fullName,
                'age' => $accountAge,
                'qualification' => $qualification,
                'email' => $email,
                'profile_image' => $profileImage,
                'id' => (int) $user['id'],
            ]);

            set_flash('success', ucfirst($user['role']) . ' profile updated successfully.');
            $redirectPath = 'index.php';

            if ($user['role'] === 'doctor' && isset($_SESSION['active_patient_id']) && (int) $_SESSION['active_patient_id'] > 0) {
                $redirectPath .= '?patient_id=' . (int) $_SESSION['active_patient_id'];
            }

            if ($user['role'] === 'admin') {
                $redirectPath = 'index.php?admin_section=' . urlencode((string) ($_GET['admin_section'] ?? 'doctors'));

                if (isset($_GET['admin_doctor_id'])) {
                    $redirectPath .= '&admin_doctor_id=' . (int) $_GET['admin_doctor_id'];
                }

                if (isset($_GET['admin_patient_id'])) {
                    $redirectPath .= '&admin_patient_id=' . (int) $_GET['admin_patient_id'];
                }

                $redirectPath .= '&edit_profile=1';
            }

            redirect_to($redirectPath);
        }

        if ($action === 'save_patient') {
            if ($user['role'] !== 'caretaker') {
                throw new RuntimeException('Only caretakers can update patient details.');
            }

            $patientName = trim($_POST['patient_name'] ?? '');
            $age = (int) ($_POST['age'] ?? 0);
            $gender = trim($_POST['gender'] ?? '');
            $disease = trim($_POST['disease'] ?? '');
            $doctorEmail = strtolower(trim($_POST['doctor_email'] ?? ''));

            if ($patientName === '' || $age <= 0 || $gender === '' || $disease === '' || $doctorEmail === '') {
                throw new RuntimeException('Please fill in all patient details.');
            }

            $doctorStmt = db()->prepare('SELECT id FROM users WHERE role = :role AND email = :email LIMIT 1');
            $doctorStmt->execute([
                'role' => 'doctor',
                'email' => $doctorEmail,
            ]);
            $doctor = $doctorStmt->fetch();

            if (!$doctor) {
                throw new RuntimeException('The doctor email must match a registered doctor account.');
            }

            $patientId = (int) ($_POST['patient_id'] ?? 0);
            $existingPatient = null;

            if ($patientId > 0) {
                foreach (get_caretaker_patients((int) $user['id']) as $caretakerPatient) {
                    if ((int) $caretakerPatient['id'] === $patientId) {
                        $existingPatient = $caretakerPatient;
                        break;
                    }
                }
            }

            if ($existingPatient) {
                $stmt = db()->prepare(
                    'UPDATE patients
                     SET doctor_id = :doctor_id, patient_name = :patient_name, age = :age, gender = :gender, disease = :disease
                     WHERE id = :id'
                );
                $stmt->execute([
                    'doctor_id' => (int) $doctor['id'],
                    'patient_name' => $patientName,
                    'age' => $age,
                    'gender' => $gender,
                    'disease' => $disease,
                    'id' => (int) $existingPatient['id'],
                ]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO patients (caretaker_id, doctor_id, patient_name, age, gender, disease)
                     VALUES (:caretaker_id, :doctor_id, :patient_name, :age, :gender, :disease)'
                );
                $stmt->execute([
                    'caretaker_id' => (int) $user['id'],
                    'doctor_id' => (int) $doctor['id'],
                    'patient_name' => $patientName,
                    'age' => $age,
                    'gender' => $gender,
                    'disease' => $disease,
                ]);
            }

            set_flash('success', 'Patient details saved successfully.');
            $latestPatient = get_linked_patient($user);
            redirect_to('index.php' . ($latestPatient ? '?patient_id=' . (int) $latestPatient['id'] : ''));
        }

        if (!$patient) {
            throw new RuntimeException('Please save patient details first.');
        }

        if (!can_access_patient($user, $patient)) {
            throw new RuntimeException('You do not have permission to access this patient.');
        }

        if ($action === 'save_medicine') {
            if (!in_array($user['role'], ['doctor', 'caretaker'], true)) {
                throw new RuntimeException('Only doctors and caretakers can manage medicines.');
            }

            $medicineId = (int) ($_POST['medicine_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $time = trim($_POST['time_of_day'] ?? '');

            if ($name === '' || $time === '') {
                throw new RuntimeException('Please fill in the medicine name and time.');
            }

            if ($medicineId > 0) {
                $stmt = db()->prepare(
                    'UPDATE medicines SET name = :name, time_of_day = :time_of_day WHERE id = :id AND patient_id = :patient_id'
                );
                $stmt->execute([
                    'name' => $name,
                    'time_of_day' => $time,
                    'id' => $medicineId,
                    'patient_id' => (int) $patient['id'],
                ]);
                set_flash('success', 'Medicine updated successfully.');
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO medicines (patient_id, name, time_of_day) VALUES (:patient_id, :name, :time_of_day)'
                );
                $stmt->execute([
                    'patient_id' => (int) $patient['id'],
                    'name' => $name,
                    'time_of_day' => $time,
                ]);
                set_flash('success', 'Medicine added successfully.');
            }

            redirect_to('index.php?patient_id=' . (int) $patient['id']);
        }

        if ($action === 'toggle_medicine') {
            if ($user['role'] !== 'caretaker') {
                throw new RuntimeException('Only caretakers can manage medicines.');
            }

            $medicineId = (int) ($_POST['medicine_id'] ?? 0);
            $stmt = db()->prepare(
                'UPDATE medicines SET is_taken = CASE WHEN is_taken = 1 THEN 0 ELSE 1 END WHERE id = :id AND patient_id = :patient_id'
            );
            $stmt->execute([
                'id' => $medicineId,
                'patient_id' => (int) $patient['id'],
            ]);
            set_flash('success', 'Medicine status updated.');
            redirect_to('index.php' . ($patient ? '?patient_id=' . (int) $patient['id'] : ''));
        }

        if ($action === 'delete_medicine') {
            if (!in_array($user['role'], ['doctor', 'caretaker'], true)) {
                throw new RuntimeException('Only doctors and caretakers can manage medicines.');
            }

            $medicineId = (int) ($_POST['medicine_id'] ?? 0);
            $stmt = db()->prepare('DELETE FROM medicines WHERE id = :id AND patient_id = :patient_id');
            $stmt->execute([
                'id' => $medicineId,
                'patient_id' => (int) $patient['id'],
            ]);
            set_flash('success', 'Medicine deleted successfully.');
            redirect_to('index.php' . ($patient ? '?patient_id=' . (int) $patient['id'] : ''));
        }

        if ($action === 'save_note') {
            if ($user['role'] !== 'doctor') {
                throw new RuntimeException('Only doctors can manage prescription guidance.');
            }

            $noteId = (int) ($_POST['note_id'] ?? 0);
            $disease = 'Prescription';
            $symptoms = trim($_POST['symptoms'] ?? '');
            $precautions = trim($_POST['precautions'] ?? '');

            if ($symptoms === '' || $precautions === '') {
                throw new RuntimeException('Please fill in the suggested medicine list and precautions.');
            }

            if ($noteId > 0) {
                $stmt = db()->prepare(
                    'UPDATE doctor_notes
                     SET disease = :disease, symptoms = :symptoms, precautions = :precautions
                     WHERE id = :id AND patient_id = :patient_id AND doctor_id = :doctor_id'
                );
                $stmt->execute([
                    'disease' => $disease,
                    'symptoms' => $symptoms,
                    'precautions' => $precautions,
                    'id' => $noteId,
                    'patient_id' => (int) $patient['id'],
                    'doctor_id' => (int) $user['id'],
                ]);
                set_flash('success', 'Prescription guidance updated successfully.');
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO doctor_notes (patient_id, doctor_id, disease, symptoms, precautions)
                     VALUES (:patient_id, :doctor_id, :disease, :symptoms, :precautions)'
                );
                $stmt->execute([
                    'patient_id' => (int) $patient['id'],
                    'doctor_id' => (int) $user['id'],
                    'disease' => $disease,
                    'symptoms' => $symptoms,
                    'precautions' => $precautions,
                ]);
                set_flash('success', 'Prescription guidance added successfully.');
            }

            redirect_to('index.php?patient_id=' . (int) $patient['id']);
        }

        if ($action === 'delete_note') {
            if ($user['role'] !== 'doctor') {
                throw new RuntimeException('Only doctors can manage prescription guidance.');
            }

            $noteId = (int) ($_POST['note_id'] ?? 0);
            $stmt = db()->prepare(
                'DELETE FROM doctor_notes WHERE id = :id AND patient_id = :patient_id AND doctor_id = :doctor_id'
            );
            $stmt->execute([
                'id' => $noteId,
                'patient_id' => (int) $patient['id'],
                'doctor_id' => (int) $user['id'],
            ]);
            set_flash('success', 'Prescription guidance deleted successfully.');
            redirect_to('index.php?patient_id=' . (int) $patient['id']);
        }
    }
} catch (Throwable $exception) {
    set_flash('error', $exception->getMessage());
    redirect_to('index.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
}

$currentUser = current_user();
$flash = get_flash();
$viewMode = $_GET['auth'] ?? 'login';
$selectedRole = normalize_role($_GET['role'] ?? ($currentUser['role'] ?? 'doctor'));
$registrationRole = $selectedRole === 'admin' ? 'doctor' : $selectedRole;
$requestedPatientId = (int) ($_GET['patient_id'] ?? 0);
$isProfileEditOpen = (int) ($_GET['edit_profile'] ?? 0) === 1;
$monthlyPatientTrend = [];
$monthlyPatientMap = [];
$monthlyPatientStart = new DateTime('2026-01-01');
$monthlyPatientEnd = new DateTime('first day of this month');

try {
    $monthlyPatientStmt = db()->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS total
        FROM patients
        WHERE created_at >= '2026-01-01'
        GROUP BY month_key
        ORDER BY month_key
    ");

    foreach ($monthlyPatientStmt->fetchAll() as $monthlyPatientRow) {
        $monthKey = (string) ($monthlyPatientRow['month_key'] ?? '');
        if ($monthKey !== '') {
            $monthlyPatientMap[$monthKey] = (int) ($monthlyPatientRow['total'] ?? 0);

            $monthDate = DateTime::createFromFormat('Y-m', $monthKey);
            if ($monthDate instanceof DateTime && $monthDate > $monthlyPatientEnd) {
                $monthlyPatientEnd = (clone $monthDate)->modify('first day of this month');
            }
        }
    }
} catch (Throwable $exception) {
    $monthlyPatientMap = [];
}

$monthlyPatientCursor = clone $monthlyPatientStart;
while ($monthlyPatientCursor <= $monthlyPatientEnd) {
    $monthKey = $monthlyPatientCursor->format('Y-m');
    $monthlyPatientTrend[] = [
        'label' => $monthlyPatientCursor->format('F Y'),
        'short' => $monthlyPatientCursor->format('M'),
        'count' => $monthlyPatientMap[$monthKey] ?? 0,
    ];
    $monthlyPatientCursor->modify('+1 month');
}

$monthlyPatientMax = max(array_column($monthlyPatientTrend, 'count')) ?: 1;
$adminSection = $_GET['admin_section'] ?? 'doctors';
$adminSection = in_array($adminSection, ['doctors', 'patients', 'caretakers'], true) ? $adminSection : 'doctors';
$requestedAdminDoctorId = (int) ($_GET['admin_doctor_id'] ?? 0);
$requestedAdminPatientId = (int) ($_GET['admin_patient_id'] ?? 0);
$requestedAdminCaretakerId = (int) ($_GET['admin_caretaker_id'] ?? 0);

$patient = null;
$doctorPatients = [];
$caretakerPatients = [];
$medicines = [];
$doctorNotes = [];
$unreadChatCount = 0;
$editMedicine = null;
$editNote = null;
$adminDoctors = [];
$adminPatients = [];
$adminCaretakers = [];
$selectedAdminDoctor = null;
$selectedAdminPatient = null;
$selectedAdminDoctorPatients = [];
$selectedAdminCaretaker = null;
$selectedAdminCaretakerPatients = [];
$adminDoctorPatientsMap = [];
$adminCaretakerPatientsMap = [];

if ($currentUser) {
    if ($currentUser['role'] === 'doctor') {
        $doctorPatients = get_doctor_patients((int) $currentUser['id']);

        if ($requestedPatientId > 0) {
            foreach ($doctorPatients as $doctorPatient) {
                if ((int) $doctorPatient['id'] === $requestedPatientId) {
                    $_SESSION['active_patient_id'] = $requestedPatientId;
                    break;
                }
            }
        } else {
            unset($_SESSION['active_patient_id']);
        }
    }

    if ($currentUser['role'] === 'caretaker') {
        $caretakerPatients = get_caretaker_patients((int) $currentUser['id']);

        if ($requestedPatientId > 0) {
            foreach ($caretakerPatients as $caretakerPatient) {
                if ((int) $caretakerPatient['id'] === $requestedPatientId) {
                    $_SESSION['active_patient_id'] = $requestedPatientId;
                    break;
                }
            }
        } elseif ($caretakerPatients && empty($_SESSION['active_patient_id'])) {
            $_SESSION['active_patient_id'] = (int) $caretakerPatients[0]['id'];
        }
    }

    $patient = get_linked_patient($currentUser);

    if ($currentUser['role'] === 'doctor' && $requestedPatientId <= 0) {
        $patient = null;
    }

    if ($currentUser['role'] === 'admin') {
        $adminDoctors = get_all_doctors();
        $adminPatients = get_all_patients();
        $adminCaretakers = get_all_caretakers();

        foreach ($adminDoctors as $doctorRecord) {
            if ((int) $doctorRecord['id'] === $requestedAdminDoctorId) {
                $selectedAdminDoctor = $doctorRecord;
                break;
            }
        }

        foreach ($adminPatients as $patientRecord) {
            if ((int) $patientRecord['id'] === $requestedAdminPatientId) {
                $selectedAdminPatient = $patientRecord;
                break;
            }
        }

        foreach ($adminCaretakers as $caretakerRecord) {
            if ((int) $caretakerRecord['id'] === $requestedAdminCaretakerId) {
                $selectedAdminCaretaker = $caretakerRecord;
                break;
            }
        }

        if ($selectedAdminDoctor === null && $adminDoctors) {
            $selectedAdminDoctor = $adminDoctors[0];
        }

        if ($selectedAdminPatient === null && $adminPatients) {
            $selectedAdminPatient = $adminPatients[0];
        }

        if ($selectedAdminCaretaker === null && $adminCaretakers) {
            $selectedAdminCaretaker = $adminCaretakers[0];
        }

        if ($selectedAdminDoctor) {
            $selectedAdminDoctorPatients = get_doctor_patients((int) $selectedAdminDoctor['id']);
        }

        if ($selectedAdminCaretaker) {
            $selectedAdminCaretakerPatients = get_caretaker_patients((int) $selectedAdminCaretaker['id']);
        }

        foreach ($adminDoctors as $doctorRecord) {
            $adminDoctorPatientsMap[(int) $doctorRecord['id']] = get_doctor_patients((int) $doctorRecord['id']);
        }

        foreach ($adminCaretakers as $caretakerRecord) {
            $adminCaretakerPatientsMap[(int) $caretakerRecord['id']] = get_caretaker_patients((int) $caretakerRecord['id']);
        }
    }

    if ($patient && can_access_patient($currentUser, $patient)) {
        $medicines = get_medicines((int) $patient['id']);
        $doctorNotes = get_doctor_notes((int) $patient['id']);
        $unreadChatCount = get_unread_chat_count((int) $patient['id'], (int) $currentUser['id']);

        $editMedicineId = (int) ($_GET['edit_medicine'] ?? 0);
        if ($editMedicineId > 0 && in_array($currentUser['role'], ['doctor', 'caretaker'], true)) {
            foreach ($medicines as $medicine) {
                if ((int) $medicine['id'] === $editMedicineId) {
                    $editMedicine = $medicine;
                    break;
                }
            }
        }

        $editNoteId = (int) ($_GET['edit_note'] ?? 0);
        if ($editNoteId > 0 && $currentUser['role'] === 'doctor') {
            foreach ($doctorNotes as $doctorNote) {
                if ((int) $doctorNote['id'] === $editNoteId) {
                    $editNote = $doctorNote;
                    break;
                }
            }
        }
    }
}

$totalMedicines = count($medicines);
$takenCount = count(array_filter($medicines, static fn(array $medicine): bool => (int) $medicine['is_taken'] === 1));
$morningMedicines = array_values(array_filter(
    $medicines,
    static function (array $medicine): bool {
        $hour = (int) substr((string) ($medicine['time_of_day'] ?? '00:00:00'), 0, 2);
        return $hour < 17;
    }
));
$nightMedicines = array_values(array_filter(
    $medicines,
    static function (array $medicine): bool {
        $hour = (int) substr((string) ($medicine['time_of_day'] ?? '00:00:00'), 0, 2);
        return $hour >= 17;
    }
));
$doctorNoteCount = count($doctorNotes);
$doctorPatientCount = count($doctorPatients);
$doctorBasePath = 'index.php';
$doctorPatientPath = $patient ? 'index.php?patient_id=' . (int) $patient['id'] : 'index.php';
$profileBasePath = $currentUser && $currentUser['role'] === 'admin'
    ? 'index.php?admin_section=' . $adminSection
        . ($selectedAdminDoctor ? '&admin_doctor_id=' . (int) $selectedAdminDoctor['id'] : '')
        . ($selectedAdminPatient ? '&admin_patient_id=' . (int) $selectedAdminPatient['id'] : '')
    : $doctorPatientPath;
$profileTogglePath = $isProfileEditOpen
    ? $profileBasePath
    : $profileBasePath . (str_contains($profileBasePath, '?') ? '&' : '?') . 'edit_profile=1';
$isDoctorPatientView = $currentUser && $currentUser['role'] === 'doctor' && $patient && $requestedPatientId > 0;
$adminDoctorCount = count($adminDoctors);
$adminPatientCount = count($adminPatients);
$adminCaretakerCount = count($adminCaretakers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CareSync Medicine Manager</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="ambient ambient-one"></div>
    <div class="ambient ambient-two"></div>
    <div class="ambient ambient-three"></div>

    <div class="page-shell">
        <?php if (!$currentUser): ?>
            <section class="login-panel">
                <div class="brand-card">
                    <div class="brand-copy">
                        <p class="eyebrow">CareSync Planner</p>
                        <h1>A connected health dashboard for doctors and caretakers.</h1>
                        <p class="lead">Doctors can suggest medicines and precautions, while caretakers manage medicine schedules and daily status updates.</p>
                    </div>

                    <div class="feature-ribbon">
                        <div class="mini-pill"><span class="mini-dot"></span>PHP and MySQL powered</div>
                        <div class="mini-pill"><span class="mini-dot"></span>Role-based login</div>
                        <div class="mini-pill"><span class="mini-dot"></span>Persistent chat and notifications</div>
                    </div>

                    <div class="trend-card">
                        <div class="trend-card-head">
                            <div>
                                <p class="eyebrow">Patient Growth</p>
                                <h3>Patients added month by month</h3>
                            </div>
                            <span class="trend-total"><?= array_sum(array_column($monthlyPatientTrend, 'count')) ?> total</span>
                        </div>
                        <div class="trend-chart" aria-label="Patients added by month">
                            <?php foreach ($monthlyPatientTrend as $monthlyPatientItem): ?>
                                <div class="trend-bar-group">
                                    <span class="trend-value"><?= (int) $monthlyPatientItem['count'] ?></span>
                                    <div class="trend-bar-track">
                                        <div class="trend-bar-fill" style="height: <?= max(16, (int) round(($monthlyPatientItem['count'] / $monthlyPatientMax) * 100)) ?>%;"></div>
                                    </div>
                                    <span class="trend-label"><?= h($monthlyPatientItem['short']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="hero-visual" aria-hidden="true">
                        <div class="hero-card hero-card-main">
                            <p>Doctor Panel</p>
                            <strong>Precautions</strong>
                            <span>Symptoms and care plan</span>
                        </div>
                        <div class="hero-card hero-card-side">
                            <p>Caretaker Panel</p>
                            <strong>08:00 AM</strong>
                            <span>Medicine taken status</span>
                        </div>
                    </div>
                </div>

                <div class="auth-stack">
                    <?php if ($flash): ?>
                        <div class="card flash-card flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                    <?php endif; ?>

                    <section class="login-card <?= $viewMode === 'create' ? 'hidden' : '' ?>" id="loginCard">
                        <div class="role-switch" id="roleSwitch">
                            <button class="role-chip <?= $selectedRole === 'doctor' ? 'active' : '' ?>" type="button" data-role-switch="doctor">Doctor Login</button>
                            <button class="role-chip <?= $selectedRole === 'caretaker' ? 'active' : '' ?>" type="button" data-role-switch="caretaker">Caretaker Login</button>
                            <button class="role-chip <?= $selectedRole === 'admin' ? 'active' : '' ?>" type="button" data-role-switch="admin">Admin Login</button>
                        </div>

                        <form method="post" id="loginForm">
                            <input type="hidden" name="action" value="login">
                            <input type="hidden" name="role" id="loginRoleInput" value="<?= h($selectedRole) ?>">
                            <h2 id="loginTitle"><?=
                                $selectedRole === 'doctor'
                                    ? 'Doctor Login'
                                    : ($selectedRole === 'admin' ? 'Admin Login' : 'Caretaker Login')
                            ?></h2>
                            <p class="card-text" id="loginSubtitle">
                                <?=
                                    $selectedRole === 'doctor'
                                        ? 'Doctors can sign in to manage patient health notes.'
                                        : ($selectedRole === 'admin'
                                            ? 'Administrators can review doctor and patient records.'
                                            : 'Caretakers can sign in to manage medicines and patient routines.')
                                ?>
                            </p>

                            <label for="email">Email</label>
                            <input id="email" name="email" type="email" placeholder="<?=
                                $selectedRole === 'doctor'
                                    ? 'doctor@example.com'
                                    : ($selectedRole === 'admin' ? 'admin@example.com' : 'caretaker@example.com')
                            ?>" required>

                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" placeholder="Enter password" required>

                            <button type="submit">Login</button>
                            <button class="secondary-btn auth-link-btn <?= $selectedRole === 'admin' ? 'hidden' : '' ?>" id="showCreateAccountBtn" type="button">Create Account</button>
                        </form>
                    </section>

                    <section class="login-card <?= $viewMode === 'create' ? '' : 'hidden' ?>" id="createAccountCard">
                        <h2>Create Account</h2>
                        <p class="card-text">Choose a doctor or caretaker role while creating the account.</p>

                        <div class="create-role-grid" id="createRoleGrid">
                            <button class="role-option <?= $selectedRole === 'doctor' ? 'active' : '' ?>" type="button" data-create-role="doctor">
                                <strong>Doctor Account</strong>
                                <span>Suggest medicines and precautions</span>
                            </button>
                            <button class="role-option <?= $selectedRole === 'caretaker' ? 'active' : '' ?>" type="button" data-create-role="caretaker">
                                <strong>Caretaker Account</strong>
                                <span>Track medicines and patient routine</span>
                            </button>
                        </div>

                        <form method="post" enctype="multipart/form-data" id="createAccountForm">
                            <input type="hidden" name="action" value="register">
                            <input type="hidden" name="role" id="createRoleInput" value="<?= h($registrationRole) ?>">

                            <label for="profilePicture">Profile Picture</label>
                            <input id="profilePicture" name="profile_picture" type="file" accept="image/*" required>

                            <label for="createName">Full Name</label>
                            <input id="createName" name="full_name" type="text" placeholder="Your full name" required>

                            <div id="doctorExtraFields" class="<?= $selectedRole === 'doctor' ? '' : 'hidden' ?>">
                                <label for="createAge">Doctor Age</label>
                                <input id="createAge" name="age" type="number" min="1" placeholder="Doctor age">

                                <label for="qualification">Qualification</label>
                                <input id="qualification" name="qualification" type="text" placeholder="MBBS, MD, BAMS">
                            </div>

                            <label for="createEmail">Email</label>
                            <input id="createEmail" name="email" type="email" placeholder="name@example.com" required>

                            <label for="createPassword">Password</label>
                            <input id="createPassword" name="password" type="password" placeholder="Create password" required>

                            <label for="confirmPassword">Confirm Password</label>
                            <input id="confirmPassword" name="confirm_password" type="password" placeholder="Confirm password" required>

                            <button type="submit">Create Account</button>
                            <button class="secondary-btn auth-link-btn" id="backToLoginBtn" type="button">Back to Login</button>
                        </form>
                    </section>
                </div>
            </section>
        <?php else: ?>
            <section class="dashboard">
                <header class="dashboard-header">
                    <div>
                        <p class="eyebrow">Dashboard</p>
                        <h2><?=
                            $currentUser['role'] === 'doctor'
                                ? 'Doctor Dashboard'
                                : ($currentUser['role'] === 'admin' ? 'Admin Dashboard' : 'Caretaker Dashboard')
                        ?></h2>
                        <p class="dashboard-subtitle">
                            <?=
                                $currentUser['role'] === 'doctor'
                                    ? 'Doctors can review patients, suggest medicines, add precautions, and chat with caretakers.'
                                    : ($currentUser['role'] === 'admin'
                                        ? 'Administrators can review all doctor and patient records in one place.'
                                        : 'Caretakers can manage patient details, medicine schedules, and chat updates.')
                            ?>
                        </p>
                    </div>
                    <a class="secondary-btn logout-link" href="logout.php">Logout</a>
                </header>

                <?php if ($flash): ?>
                    <div class="card flash-card flash-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                <?php endif; ?>

                <section class="profile-summary card">
                    <div class="profile-summary-media">
                        <?php if (!empty($currentUser['profile_image'])): ?>
                            <img src="<?= h($currentUser['profile_image']) ?>" alt="Profile">
                        <?php else: ?>
                            <div class="dashboard-profile-fallback"><?= h(strtoupper(substr($currentUser['full_name'], 0, 1))) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="profile-summary-content">
                        <div class="profile-summary-top">
                            <div>
                                <p class="eyebrow profile-eyebrow">Logged-In Profile</p>
                                <h3><?= h($currentUser['full_name']) ?></h3>
                            </div>
                            <?php if (in_array($currentUser['role'], ['doctor', 'admin'], true)): ?>
                                <a class="secondary-btn profile-toggle-btn" href="<?= h($profileTogglePath) ?>"><?= $isProfileEditOpen ? 'Close Edit' : 'Edit Profile' ?></a>
                            <?php endif; ?>
                        </div>
                        <div class="profile-summary-grid">
                            <p><strong><span class="section-icon" aria-hidden="true">&#128188;</span>Role:</strong> <?= h($currentUser['role']) ?></p>
                            <p><strong><span class="section-icon" aria-hidden="true">&#9993;</span>Email:</strong> <?= h($currentUser['email']) ?></p>
                            <?php if ($currentUser['role'] === 'doctor'): ?>
                                <p><strong><span class="section-icon" aria-hidden="true">&#9877;</span>Doctor Name:</strong> <?= h($currentUser['full_name']) ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#128197;</span>Age:</strong> <?= h((string) ($currentUser['age'] ?? '--')) ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#127891;</span>Qualification:</strong> <?= h($currentUser['qualification'] ?? '--') ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#128100;</span>Selected Patient:</strong> <?= h($patient['patient_name'] ?? '--') ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#129490;</span>Patient Caretaker:</strong> <?= h($patient['caretaker_name'] ?? '--') ?></p>
                            <?php elseif ($currentUser['role'] === 'caretaker'): ?>
                                <p><strong><span class="section-icon" aria-hidden="true">&#128100;</span>Patient:</strong> <?= h($patient['patient_name'] ?? '--') ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#9993;</span>Doctor Email:</strong> <?= h($patient['doctor_email'] ?? '--') ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#129490;</span>Caretaker:</strong> <?= h($patient['caretaker_name'] ?? $currentUser['full_name']) ?></p>
                            <?php else: ?>
                                <p><strong><span class="section-icon" aria-hidden="true">&#128272;</span>Panel Access:</strong> Full admin overview</p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#9877;</span>Doctors Available:</strong> <?= $adminDoctorCount ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#128100;</span>Patients Available:</strong> <?= $adminPatientCount ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#129490;</span>Caretakers Available:</strong> <?= $adminCaretakerCount ?></p>
                                <p><strong><span class="section-icon" aria-hidden="true">&#128451;</span>Current Section:</strong> <?= h(ucfirst($adminSection)) ?></p>
                            <?php endif; ?>
                            <p><strong><span class="section-icon" aria-hidden="true">&#128276;</span>Notifications:</strong> <?= $unreadChatCount ?> unread message<?= $unreadChatCount === 1 ? '' : 's' ?></p>
                            <p><strong><span class="section-icon" aria-hidden="true">&#128427;</span>Data Sync:</strong> MySQL database</p>
                        </div>

                        <?php if (in_array($currentUser['role'], ['doctor', 'admin'], true)): ?>
                            <form class="profile-edit-form <?= $isProfileEditOpen ? '' : 'hidden' ?>" id="profileEditForm" method="post" enctype="multipart/form-data">
                                <div class="card-head profile-edit-head">
                                    <h3>Edit Logged-In Profile</h3>
                                    <p>Update the logged-in account information directly from this panel.</p>
                                </div>
                                <input type="hidden" name="action" value="update_profile">

                                <div class="profile-edit-grid">
                                    <div>
                                        <label for="doctorProfileImage">Profile Picture</label>
                                        <input id="doctorProfileImage" name="profile_picture" type="file" accept="image/*">
                                    </div>

                                    <div>
                                        <label for="doctorProfileName">Full Name</label>
                                        <input id="doctorProfileName" name="full_name" type="text" value="<?= h($currentUser['full_name']) ?>" placeholder="Full name" required>
                                    </div>

                                    <div>
                                        <label for="doctorProfileAge">Age</label>
                                        <input id="doctorProfileAge" name="age" type="number" min="1" value="<?= h((string) ($currentUser['age'] ?? '')) ?>" placeholder="Age" required>
                                    </div>

                                    <div>
                                        <label for="doctorQualification">Qualification</label>
                                        <input id="doctorQualification" name="qualification" type="text" value="<?= h($currentUser['qualification'] ?? '') ?>" placeholder="Qualification or role title" required>
                                    </div>

                                    <div class="profile-edit-grid-full">
                                        <label for="doctorProfileEmail">Email</label>
                                        <input id="doctorProfileEmail" name="email" type="email" value="<?= h($currentUser['email']) ?>" placeholder="doctor@example.com" required>
                                    </div>
                                </div>

                                <div class="form-actions">
                                    <button type="submit">Save Profile</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ($currentUser['role'] === 'caretaker'): ?>
                    <section class="stats-grid">
                        <article class="stat-card highlight-card">
                            <p class="stat-label">Total Medicines</p>
                            <strong class="stat-value"><?= $totalMedicines ?></strong>
                            <span class="stat-note">Tracked by the caretaker</span>
                        </article>
                        <article class="stat-card">
                            <p class="stat-label">Taken Today</p>
                            <strong class="stat-value"><?= $takenCount ?></strong>
                            <span class="stat-note">Updated by the caretaker</span>
                        </article>
                        <article class="stat-card">
                            <p class="stat-label">Doctor Guidance</p>
                            <strong class="stat-value"><?= $doctorNoteCount ?></strong>
                            <span class="stat-note">Suggested medicines and precautions</span>
                        </article>
                        <article class="stat-card">
                            <p class="stat-label">Active Role</p>
                            <strong class="stat-value"><?= h($currentUser['role']) ?></strong>
                            <span class="stat-note">Permission-based view</span>
                        </article>
                        <article class="stat-card patient-stat-card">
                            <p class="stat-label">Linked Patients</p>
                            <strong class="stat-value"><?= count($caretakerPatients) ?></strong>
                            <span class="stat-note">Choose which patient you want to monitor</span>
                            <div class="patient-stat-list">
                                <?php if (!$caretakerPatients): ?>
                                    <div class="empty-state compact-empty-state">No patients have been added yet.</div>
                                <?php else: ?>
                                    <?php foreach ($caretakerPatients as $caretakerPatient): ?>
                                        <div class="patient-stat-item">
                                            <div>
                                                <strong><?= h($caretakerPatient['patient_name']) ?></strong>
                                                <p><?= h($caretakerPatient['disease']) ?>, age <?= h((string) $caretakerPatient['age']) ?></p>
                                            </div>
                                            <a class="secondary-btn inline-link-btn" href="index.php?patient_id=<?= (int) $caretakerPatient['id'] ?>">Open</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </article>
                    </section>
                <?php endif; ?>

                <?php if ($currentUser['role'] === 'admin'): ?>
                    <section class="stats-grid admin-overview-grid">
                        <a class="stat-card admin-nav-card <?= $adminSection === 'doctors' ? 'active-stat' : '' ?>" href="index.php?admin_section=doctors<?= $selectedAdminDoctor ? '&admin_doctor_id=' . (int) $selectedAdminDoctor['id'] : '' ?>">
                            <p class="stat-label">Doctors</p>
                            <strong class="stat-value"><?= $adminDoctorCount ?></strong>
                            <span class="stat-note">Open the full doctor directory</span>
                        </a>
                        <a class="stat-card admin-nav-card <?= $adminSection === 'patients' ? 'active-stat' : '' ?>" href="index.php?admin_section=patients<?= $selectedAdminPatient ? '&admin_patient_id=' . (int) $selectedAdminPatient['id'] : '' ?>">
                            <p class="stat-label">Patients</p>
                            <strong class="stat-value"><?= $adminPatientCount ?></strong>
                            <span class="stat-note">Open the full patient directory</span>
                        </a>
                        <a class="stat-card admin-nav-card <?= $adminSection === 'caretakers' ? 'active-stat' : '' ?>" href="index.php?admin_section=caretakers<?= $selectedAdminCaretaker ? '&admin_caretaker_id=' . (int) $selectedAdminCaretaker['id'] : '' ?>">
                            <p class="stat-label">Caretakers</p>
                            <strong class="stat-value"><?= $adminCaretakerCount ?></strong>
                            <span class="stat-note">Open the full caretaker directory</span>
                        </a>
                    </section>

                    <section class="admin-panel-grid">
                        <section class="card medicine-list-card">
                            <div class="card-head">
                                <h3><?= $adminSection === 'doctors' ? 'All Doctors' : ($adminSection === 'patients' ? 'All Patients' : 'All Caretakers') ?></h3>
                                <p><?= $adminSection === 'doctors'
                                    ? 'Select any doctor to review their profile and linked patient count.'
                                    : ($adminSection === 'patients'
                                        ? 'Select any patient to review the linked doctor, caretaker, and condition details.'
                                        : 'Select any caretaker to review their account and monitored patients.') ?></p>
                            </div>

                            <div class="admin-directory-list">
                                <?php if ($adminSection === 'doctors'): ?>
                                    <?php if (!$adminDoctors): ?>
                                        <div class="empty-state compact-empty-state">No doctors are available.</div>
                                    <?php else: ?>
                                        <?php foreach ($adminDoctors as $doctorRecord): ?>
                                            <details class="patient-stat-item admin-list-link admin-doctor-card admin-directory-item">
                                                <summary class="admin-directory-summary">
                                                    <div>
                                                        <strong><?= h($doctorRecord['full_name']) ?></strong>
                                                        <p><?= h($doctorRecord['qualification'] ?? 'Doctor') ?> | <?= (int) $doctorRecord['patient_count'] ?> patients</p>
                                                    </div>
                                                    <span class="secondary-btn inline-link-btn admin-toggle-btn">View Details</span>
                                                </summary>
                                                <div class="admin-inline-detail">
                                                        <div class="detail-grid">
                                                            <p><strong>Email:</strong> <?= h($doctorRecord['email']) ?></p>
                                                            <p><strong>Age:</strong> <?= h((string) ($doctorRecord['age'] ?? '--')) ?></p>
                                                            <p><strong>Qualification:</strong> <?= h($doctorRecord['qualification'] ?? '--') ?></p>
                                                            <p><strong>Linked Patients:</strong> <?= (int) $doctorRecord['patient_count'] ?></p>
                                                        </div>
                                                        <div class="doctor-linked-patients">
                                                            <?php if (empty($adminDoctorPatientsMap[(int) $doctorRecord['id']])): ?>
                                                                <div class="doctor-linked-patient-item">
                                                                    <strong>Status</strong>
                                                                    <p>No patients are linked to this doctor yet.</p>
                                                                </div>
                                                            <?php else: ?>
                                                                <?php foreach ($adminDoctorPatientsMap[(int) $doctorRecord['id']] as $linkedPatient): ?>
                                                                    <div class="doctor-linked-patient-item">
                                                                        <strong><?= h($linkedPatient['patient_name']) ?></strong>
                                                                        <p><?= h($linkedPatient['disease']) ?> | <?= h((string) $linkedPatient['age']) ?> years | Added on <?= h(format_date_display($linkedPatient['created_at'] ?? null)) ?></p>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php elseif ($adminSection === 'patients'): ?>
                                    <?php if (!$adminPatients): ?>
                                        <div class="empty-state compact-empty-state">No patients are available.</div>
                                    <?php else: ?>
                                        <?php foreach ($adminPatients as $patientRecord): ?>
                                            <details class="patient-stat-item admin-list-link admin-directory-item">
                                                <summary class="admin-directory-summary">
                                                    <div>
                                                        <strong><?= h($patientRecord['patient_name']) ?></strong>
                                                        <p><?= h($patientRecord['disease']) ?> | <?= h((string) $patientRecord['age']) ?> years</p>
                                                    </div>
                                                    <span class="secondary-btn inline-link-btn admin-toggle-btn">View Details</span>
                                                </summary>
                                                <div class="admin-inline-detail">
                                                        <div class="detail-panel-head">
                                                            <div>
                                                                <p class="stat-label">Patient Details</p>
                                                                <h3><?= h($patientRecord['patient_name']) ?></h3>
                                                            </div>
                                                            <span class="note-badge">Patient Record</span>
                                                        </div>

                                                        <div class="detail-panel-body">
                                                            <div class="detail-grid">
                                                                <p><strong>Patient Name:</strong> <?= h($patientRecord['patient_name']) ?></p>
                                                                <p><strong>Age:</strong> <?= h((string) $patientRecord['age']) ?></p>
                                                                <p><strong>Gender:</strong> <?= h($patientRecord['gender']) ?></p>
                                                                <p><strong>Disease:</strong> <?= h($patientRecord['disease']) ?></p>
                                                                <p><strong>Added On:</strong> <?= h(format_date_display($patientRecord['created_at'] ?? null)) ?></p>
                                                                <p><strong>Doctor:</strong> <?= h($patientRecord['doctor_name'] ?? '--') ?></p>
                                                                <p><strong>Doctor Email:</strong> <?= h($patientRecord['doctor_email'] ?? '--') ?></p>
                                                                <p><strong>Caretaker:</strong> <?= h($patientRecord['caretaker_name'] ?? '--') ?></p>
                                                                <p><strong>Caretaker Email:</strong> <?= h($patientRecord['caretaker_email'] ?? '--') ?></p>
                                                            </div>

                                                            <div class="detail-row">
                                                                <h4>Condition Summary</h4>
                                                                <p><strong>Primary Condition:</strong> <?= h($patientRecord['disease']) ?></p>
                                                            </div>
                                                        </div>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (!$adminCaretakers): ?>
                                        <div class="empty-state compact-empty-state">No caretakers are available.</div>
                                    <?php else: ?>
                                        <?php foreach ($adminCaretakers as $caretakerRecord): ?>
                                            <details class="patient-stat-item admin-list-link admin-directory-item">
                                                <summary class="admin-directory-summary">
                                                    <div>
                                                        <strong><?= h($caretakerRecord['full_name']) ?></strong>
                                                        <p><?= h($caretakerRecord['email']) ?> | <?= (int) $caretakerRecord['patient_count'] ?> patients</p>
                                                    </div>
                                                    <span class="secondary-btn inline-link-btn admin-toggle-btn">View Details</span>
                                                </summary>
                                                <div class="admin-inline-detail">
                                                    <div class="detail-grid">
                                                        <p><strong>Full Name:</strong> <?= h($caretakerRecord['full_name']) ?></p>
                                                        <p><strong>Email:</strong> <?= h($caretakerRecord['email']) ?></p>
                                                        <p><strong>Age:</strong> <?= h((string) ($caretakerRecord['age'] ?? '--')) ?></p>
                                                        <p><strong>Linked Patients:</strong> <?= (int) $caretakerRecord['patient_count'] ?></p>
                                                        <p><strong>Role:</strong> Caretaker</p>
                                                        <p><strong>Created On:</strong> <?= h((string) $caretakerRecord['created_at']) ?></p>
                                                    </div>

                                                    <div class="detail-row">
                                                        <h4>Monitored Patients</h4>
                                                        <?php if (empty($adminCaretakerPatientsMap[(int) $caretakerRecord['id']])): ?>
                                                            <p><strong>Status:</strong> No patients are linked to this caretaker yet.</p>
                                                        <?php else: ?>
                                                            <div class="doctor-linked-patients">
                                                                <?php foreach ($adminCaretakerPatientsMap[(int) $caretakerRecord['id']] as $linkedPatient): ?>
                                                                    <div class="doctor-linked-patient-item">
                                                                        <strong><?= h($linkedPatient['patient_name']) ?></strong>
                                                                        <p><?= h($linkedPatient['disease']) ?> | <?= h((string) $linkedPatient['age']) ?> years | Doctor: <?= h($linkedPatient['doctor_name'] ?? '--') ?></p>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </section>
                    </section>
                <?php endif; ?>

                <?php if ($currentUser['role'] === 'doctor' && !$isDoctorPatientView): ?>
                    <section class="doctor-home-grid">
                        <article class="stat-card patient-stat-card patient-home-card">
                            <p class="stat-label">Patients</p>
                            <strong class="stat-value"><?= $doctorPatientCount ?></strong>
                            <span class="stat-note">View every linked patient separately</span>
                            <div class="patient-stat-list">
                                <?php if (!$doctorPatients): ?>
                                    <div class="empty-state compact-empty-state">No patients linked yet.</div>
                                <?php else: ?>
                                    <?php foreach ($doctorPatients as $doctorPatient): ?>
                                        <div class="patient-stat-item">
                                            <div>
                                                <strong><?= h($doctorPatient['patient_name']) ?></strong>
                                                <p><?= h($doctorPatient['disease']) ?>, age <?= h((string) $doctorPatient['age']) ?> | Added on <?= h(format_date_display($doctorPatient['created_at'] ?? null)) ?></p>
                                            </div>
                                            <a class="secondary-btn inline-link-btn" href="index.php?patient_id=<?= (int) $doctorPatient['id'] ?>">Open Details</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </article>
                    </section>
                <?php endif; ?>

                <?php if ($currentUser['role'] === 'caretaker' || $isDoctorPatientView): ?>
                <div class="dashboard-grid">
                    <div class="forms-column">
                        <?php if ($currentUser['role'] === 'caretaker'): ?>
                            <form class="medicine-form card" method="post">
                                <div class="card-head">
                                    <h3>Patient Details</h3>
                                    <p>Fill in the patient details here, including the doctor's email.</p>
                                </div>
                                <input type="hidden" name="action" value="save_patient">
                                <input type="hidden" name="patient_id" value="<?= (int) ($patient['id'] ?? 0) ?>">

                                <label for="patientName">Patient Name</label>
                                <input id="patientName" name="patient_name" type="text" value="<?= h($patient['patient_name'] ?? '') ?>" placeholder="Patient full name" required>

                                <label for="patientAge">Patient Age</label>
                                <input id="patientAge" name="age" type="number" min="1" value="<?= h((string) ($patient['age'] ?? '')) ?>" placeholder="Age" required>

                                <label for="patientGender">Patient Gender</label>
                                <select id="patientGender" name="gender" required>
                                    <option value="">Select gender</option>
                                    <?php foreach (['Male', 'Female', 'Other'] as $gender): ?>
                                        <option value="<?= h($gender) ?>" <?= (($patient['gender'] ?? '') === $gender) ? 'selected' : '' ?>><?= h($gender) ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="patientDisease">Patient Disease</label>
                                <input id="patientDisease" name="disease" type="text" value="<?= h($patient['disease'] ?? '') ?>" placeholder="Diabetes, BP, Fever" required>

                                <label for="patientDoctorEmail">Doctor Email</label>
                                <input id="patientDoctorEmail" name="doctor_email" type="email" value="<?= h($patient['doctor_email'] ?? '') ?>" placeholder="doctor@example.com" required>

                                <div class="form-actions">
                                    <button type="submit">Save Patient Details</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if ($patient && in_array($currentUser['role'], ['doctor', 'caretaker'], true)): ?>
                            <form class="medicine-form card" method="post">
                                <div class="card-head">
                                    <h3><?= $editMedicine ? 'Edit Medicine' : 'Add Medicine' ?></h3>
                                    <p><?= $currentUser['role'] === 'doctor'
                                        ? 'Doctors can add or update medicines for this patient. Caretakers will also see the same schedule.'
                                        : 'Caretakers can manage medicine names, timings, and taken status.' ?></p>
                                </div>
                                <input type="hidden" name="action" value="save_medicine">
                                <input type="hidden" name="medicine_id" value="<?= h((string) ($editMedicine['id'] ?? 0)) ?>">

                                <label for="medicineName">Medicine Name</label>
                                <input id="medicineName" name="name" type="text" value="<?= h($editMedicine['name'] ?? '') ?>" placeholder="Paracetamol" required>

                                <label for="medicineTime">Time</label>
                                <input id="medicineTime" name="time_of_day" type="time" value="<?= h(isset($editMedicine['time_of_day']) ? substr((string) $editMedicine['time_of_day'], 0, 5) : '') ?>" required>

                                <div class="form-actions">
                                    <button type="submit"><?= $editMedicine ? 'Update Medicine' : 'Add Medicine' ?></button>
                                    <?php if ($editMedicine): ?>
                                        <a class="secondary-btn inline-link-btn" href="index.php?patient_id=<?= (int) ($patient['id'] ?? 0) ?>">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if ($currentUser['role'] === 'doctor' && $isDoctorPatientView): ?>
                            <form class="medicine-form card" method="post">
                                <div class="card-head">
                                    <h3><?= $editNote ? 'Edit Doctor Guidance' : 'Add Doctor Guidance' ?></h3>
                                    <p>Doctors can add extra prescription guidance and precautions here. The medicine schedule stays shared with the caretaker.</p>
                                </div>
                                <input type="hidden" name="action" value="save_note">
                                <input type="hidden" name="note_id" value="<?= h((string) ($editNote['id'] ?? 0)) ?>">

                                <label for="doctorSymptoms">Suggested Medicine List</label>
                                <textarea id="doctorSymptoms" name="symptoms" rows="4" placeholder="Paracetamol after meals, Vitamin C once daily" required><?= h($editNote['symptoms'] ?? '') ?></textarea>

                                <label for="doctorPrecautions">Precautions</label>
                                <textarea id="doctorPrecautions" name="precautions" rows="4" placeholder="Avoid dust, take steam" required><?= h($editNote['precautions'] ?? '') ?></textarea>

                                <div class="form-actions">
                                    <button type="submit"><?= $editNote ? 'Update Guidance' : 'Save Guidance' ?></button>
                                    <?php if ($editNote): ?>
                                        <a class="secondary-btn inline-link-btn" href="index.php?patient_id=<?= (int) $patient['id'] ?>">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="lists-column">
                        <?php if ($currentUser['role'] === 'doctor' && $isDoctorPatientView): ?>
                            <section class="card medicine-list-card">
                                <div class="detail-panel-head">
                                    <div class="patient-detail-heading">
                                        <a class="back-link" href="<?= h($doctorBasePath) ?>" aria-label="Back to doctor page">&larr;</a>
                                        <div>
                                            <p class="stat-label">Patient Details</p>
                                            <h3><?= h($patient['patient_name'] ?? 'No patient selected') ?></h3>
                                        </div>
                                    </div>
                                    <span class="note-badge"><span class="section-icon" aria-hidden="true">&#128100;</span>Active Patient</span>
                                </div>

                                <section class="stats-grid patient-stats-grid">
                                    <article class="stat-card highlight-card">
                                        <p class="stat-label"><span class="section-icon" aria-hidden="true">&#128138;</span>Total Medicines</p>
                                        <strong class="stat-value"><?= $totalMedicines ?></strong>
                                        <span class="stat-note">Medicines assigned to this patient</span>
                                    </article>
                                    <article class="stat-card">
                                        <p class="stat-label"><span class="section-icon" aria-hidden="true">&#10004;</span>Taken Today</p>
                                        <strong class="stat-value"><?= $takenCount ?></strong>
                                        <span class="stat-note">Updated by the caretaker</span>
                                    </article>
                                    <article class="stat-card">
                                        <p class="stat-label"><span class="section-icon" aria-hidden="true">&#9877;</span>Doctor Guidance</p>
                                        <strong class="stat-value"><?= $doctorNoteCount ?></strong>
                                        <span class="stat-note">Guidance entries for this patient</span>
                                    </article>
                                    <article class="stat-card">
                                        <p class="stat-label"><span class="section-icon" aria-hidden="true">&#129658;</span>Disease</p>
                                        <strong class="stat-value stat-value-text"><?= h($patient['disease']) ?></strong>
                                        <span class="stat-note">Current health issue</span>
                                    </article>
                                </section>

                                <div class="detail-panel-body">
                                    <div class="detail-grid">
                                        <p><strong><span class="section-icon" aria-hidden="true">&#128100;</span>Patient Name:</strong> <?= h($patient['patient_name']) ?></p>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#128197;</span>Age:</strong> <?= h((string) $patient['age']) ?></p>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#9893;</span>Gender:</strong> <?= h($patient['gender']) ?></p>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#129658;</span>Disease:</strong> <?= h($patient['disease']) ?></p>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#128197;</span>Added On:</strong> <?= h(format_date_display($patient['created_at'] ?? null)) ?></p>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#129490;</span>Caretaker:</strong> <?= h($patient['caretaker_name'] ?? '--') ?></p>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#9993;</span>Caretaker Email:</strong> <?= h($patient['caretaker_email'] ?? '--') ?></p>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#9877;</span>Doctor:</strong> <?= h($patient['doctor_name'] ?? $currentUser['full_name']) ?></p>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#9993;</span>Doctor Email:</strong> <?= h($patient['doctor_email'] ?? $currentUser['email']) ?></p>
                                    </div>

                                    <div class="detail-row">
                                        <h4><span class="section-icon" aria-hidden="true">&#128137;</span>Patient Condition</h4>
                                        <p><strong><span class="section-icon" aria-hidden="true">&#129658;</span>Health Issue:</strong> <?= h($patient['disease']) ?></p>
                                    </div>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php if ($currentUser['role'] === 'caretaker' || $isDoctorPatientView): ?>
                            <section class="card medicine-list-card">
                                <div class="card-head">
                                    <h3><span class="section-icon" aria-hidden="true">&#128138;</span>Medicine Schedule</h3>
                                    <p><?= $currentUser['role'] === 'doctor'
                                        ? 'Doctors can review and manage the medicine list added for this patient.'
                                        : 'Caretakers can add medicines, edit them, and mark them as taken or not taken.' ?></p>
                                </div>
                                <?php if (!$patient): ?>
                                    <div class="empty-state">Save patient details to start medicine tracking.</div>
                                <?php elseif (!$medicines): ?>
                                    <div class="empty-state">No medicines have been added yet.</div>
                                <?php else: ?>
                                    <div class="medicine-group-list">
                                        <section class="medicine-slot-group">
                                            <div class="medicine-slot-head">
                                                <h4><span class="section-icon" aria-hidden="true">&#9728;</span>Morning Medicines</h4>
                                                <span class="note-badge"><span class="section-icon" aria-hidden="true">&#9200;</span><?= count($morningMedicines) ?> items</span>
                                            </div>
                                            <?php if (!$morningMedicines): ?>
                                                <div class="empty-state compact-empty-state">No morning medicines added yet.</div>
                                            <?php else: ?>
                                                <div class="medicine-list">
                                                    <?php foreach ($morningMedicines as $medicine): ?>
                                                        <article class="medicine-item">
                                                            <div class="medicine-item-content">
                                                                <span class="time-badge"><span class="section-icon" aria-hidden="true">&#9728;</span>Morning dose at <?= h(format_time_display($medicine['time_of_day'])) ?></span>
                                                                <span class="taken-badge">
                                                                    <span class="section-icon" aria-hidden="true"><?= (int) $medicine['is_taken'] === 1 ? '&#9989;' : '&#9203;' ?></span>
                                                                    <?= (int) $medicine['is_taken'] === 1 ? 'Medicine Taken' : 'Not Taken Yet' ?>
                                                                </span>
                                                                <h4><?= h($medicine['name']) ?></h4>
                                                                <p class="medicine-meta"><span class="section-icon" aria-hidden="true">&#128101;</span>This medicine schedule is shared between the doctor and caretaker.</p>
                                                            </div>
                                                            <div class="medicine-actions">
                                                                <?php if ($currentUser['role'] === 'caretaker'): ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="toggle_medicine">
                                                                        <input type="hidden" name="medicine_id" value="<?= (int) $medicine['id'] ?>">
                                                                        <button type="submit" class="success-btn"><?= (int) $medicine['is_taken'] === 1 ? '&#8635; Mark Not Taken' : '&#10004; Mark Taken' ?></button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <a class="secondary-btn inline-link-btn" href="index.php?patient_id=<?= (int) $patient['id'] ?>&edit_medicine=<?= (int) $medicine['id'] ?>">&#9998; Edit</a>
                                                                <form method="post">
                                                                    <input type="hidden" name="action" value="delete_medicine">
                                                                    <input type="hidden" name="medicine_id" value="<?= (int) $medicine['id'] ?>">
                                                                    <button type="submit" class="danger-btn">&#128465; Delete</button>
                                                                </form>
                                                            </div>
                                                        </article>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </section>

                                        <section class="medicine-slot-group">
                                            <div class="medicine-slot-head">
                                                <h4><span class="section-icon" aria-hidden="true">&#9790;</span>Night Medicines</h4>
                                                <span class="note-badge"><span class="section-icon" aria-hidden="true">&#127769;</span><?= count($nightMedicines) ?> items</span>
                                            </div>
                                            <?php if (!$nightMedicines): ?>
                                                <div class="empty-state compact-empty-state">No night medicines added yet.</div>
                                            <?php else: ?>
                                                <div class="medicine-list">
                                                    <?php foreach ($nightMedicines as $medicine): ?>
                                                        <article class="medicine-item">
                                                            <div class="medicine-item-content">
                                                                <span class="time-badge"><span class="section-icon" aria-hidden="true">&#9790;</span>Night dose at <?= h(format_time_display($medicine['time_of_day'])) ?></span>
                                                                <span class="taken-badge">
                                                                    <span class="section-icon" aria-hidden="true"><?= (int) $medicine['is_taken'] === 1 ? '&#9989;' : '&#9203;' ?></span>
                                                                    <?= (int) $medicine['is_taken'] === 1 ? 'Medicine Taken' : 'Not Taken Yet' ?>
                                                                </span>
                                                                <h4><?= h($medicine['name']) ?></h4>
                                                                <p class="medicine-meta"><span class="section-icon" aria-hidden="true">&#128101;</span>This medicine schedule is shared between the doctor and caretaker.</p>
                                                            </div>
                                                            <div class="medicine-actions">
                                                                <?php if ($currentUser['role'] === 'caretaker'): ?>
                                                                    <form method="post">
                                                                        <input type="hidden" name="action" value="toggle_medicine">
                                                                        <input type="hidden" name="medicine_id" value="<?= (int) $medicine['id'] ?>">
                                                                        <button type="submit" class="success-btn"><?= (int) $medicine['is_taken'] === 1 ? '&#8635; Mark Not Taken' : '&#10004; Mark Taken' ?></button>
                                                                    </form>
                                                                <?php endif; ?>
                                                                <a class="secondary-btn inline-link-btn" href="index.php?patient_id=<?= (int) $patient['id'] ?>&edit_medicine=<?= (int) $medicine['id'] ?>">&#9998; Edit</a>
                                                                <form method="post">
                                                                    <input type="hidden" name="action" value="delete_medicine">
                                                                    <input type="hidden" name="medicine_id" value="<?= (int) $medicine['id'] ?>">
                                                                    <button type="submit" class="danger-btn">&#128465; Delete</button>
                                                                </form>
                                                            </div>
                                                        </article>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </section>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <?php if ($currentUser['role'] === 'doctor' && $isDoctorPatientView): ?>
                            <section class="card medicine-list-card">
                                <div class="card-head">
                                    <h3><span class="section-icon" aria-hidden="true">&#128203;</span>Doctor Guidance</h3>
                                    <p>Doctors can review extra prescription guidance and precautions for each patient here.</p>
                                </div>
                                <?php if (!$patient): ?>
                                    <div class="empty-state">No patient is linked to this doctor yet.</div>
                                <?php elseif (!$doctorNotes): ?>
                                    <div class="empty-state">No doctor guidance has been added yet.</div>
                                <?php else: ?>
                                    <div class="medicine-list">
                                        <?php foreach ($doctorNotes as $note): ?>
                                            <?php $isDietaryGuidance = strtolower(trim((string) ($note['disease'] ?? ''))) === 'dietary guidance'; ?>
                                            <article class="medicine-item">
                                                <div class="medicine-item-content">
                                                    <span class="note-badge">
                                                        <span class="section-icon" aria-hidden="true"><?= $isDietaryGuidance ? '&#127807;' : '&#9877;' ?></span>
                                                        <?= $isDietaryGuidance ? 'Dietary Guidance' : 'Doctor Guidance' ?>
                                                    </span>
                                                    <h4><?= h($patient['patient_name'] ?? 'Patient Guidance') ?></h4>
                                                    <p class="medicine-meta">
                                                        <strong><?= $isDietaryGuidance ? 'Foods to Eat:' : 'Suggested Medicines:' ?></strong>
                                                        <?= $isDietaryGuidance ? '<span class="section-icon" aria-hidden="true">&#127822;</span>' : '' ?>
                                                        <?= h($note['symptoms']) ?>
                                                    </p>
                                                    <p class="medicine-meta">
                                                        <strong><?= $isDietaryGuidance ? 'Foods to Avoid:' : 'Precautions:' ?></strong>
                                                        <?= $isDietaryGuidance ? '<span class="section-icon" aria-hidden="true">&#9888;</span>' : '' ?>
                                                        <?= h($note['precautions']) ?>
                                                    </p>
                                                </div>
                                                <div class="medicine-actions">
                                                    <a class="secondary-btn inline-link-btn" href="index.php?patient_id=<?= (int) $patient['id'] ?>&edit_note=<?= (int) $note['id'] ?>">Edit</a>
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="delete_note">
                                                        <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                                                        <button type="submit" class="danger-btn">Delete</button>
                                                    </form>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <section class="card medicine-list-card" id="chatCard" data-has-chat="<?= $patient ? '1' : '0' ?>">
                            <div class="card-head chat-card-head">
                                <div>
                                    <h3>Doctor and Caretaker Chat</h3>
                                    <p id="chatSubtitle">
                                        <?= $patient ? 'This chat is saved in MySQL and shared between the linked doctor and caretaker.' : 'Save the patient details first, then the doctor and caretaker can chat here.' ?>
                                    </p>
                                </div>
                                <div class="chat-header-actions">
                                    <span class="chat-notification-pill" id="chatNotificationBadge"><?= $unreadChatCount ?> unread</span>
                                    <button class="secondary-btn <?= $unreadChatCount > 0 ? '' : 'hidden' ?>" id="markChatReadBtn" type="button">Mark as Read</button>
                                </div>
                            </div>

                            <div class="chat-thread <?= $patient ? '' : 'empty-state' ?>" id="chatThread">
                                <?php if (!$patient): ?>
                                    The chat will appear here after the doctor and caretaker are linked.
                                <?php endif; ?>
                            </div>

                            <form class="chat-form <?= $patient ? '' : 'chat-form-disabled' ?>" id="chatForm">
                                <label for="chatMessage">Message</label>
                                <textarea id="chatMessage" name="chatMessage" rows="3" placeholder="Type an update for the other person..." <?= $patient ? '' : 'disabled' ?> required></textarea>
                                <div class="form-actions">
                                    <button type="submit" id="sendChatBtn" <?= $patient ? '' : 'disabled' ?>>Send Message</button>
                                </div>
                                <p class="form-message" id="chatMessageStatus"></p>
                            </form>
                        </section>
                    </div>
                </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <script>
        window.CARESYNC = {
            role: <?= json_encode($selectedRole, JSON_THROW_ON_ERROR) ?>,
            createRole: <?= json_encode($registrationRole, JSON_THROW_ON_ERROR) ?>,
            userLoggedIn: <?= $currentUser ? 'true' : 'false' ?>,
            patientLinked: <?= $patient ? 'true' : 'false' ?>
        };
    </script>
    <script src="app.js"></script>
</body>
</html>
