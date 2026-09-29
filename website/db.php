<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $exception) {
        $message = $exception->getMessage();

        if (str_contains($message, 'Unknown database')) {
            $pdo = bootstrap_database($options);
        } else {
            throw $exception;
        }
    }

    ensure_schema($pdo);

    return $pdo;
}

function bootstrap_database(array $options): PDO
{
    $serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($serverDsn, DB_USER, DB_PASS, $options);
    $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', DB_NAME));
    $pdo->exec(sprintf('USE `%s`', DB_NAME));

    run_schema_file($pdo);
    import_project_data_if_needed($pdo);

    return $pdo;
}

function ensure_schema(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    $hasUsersTable = $stmt->fetchColumn();

    if (!$hasUsersTable) {
        run_schema_file($pdo);
        return;
    }

    ensure_users_columns($pdo);
    ensure_users_role_enum($pdo);
    ensure_patients_constraints($pdo);
    import_project_data_if_needed($pdo);
    ensure_admin_account($pdo);
}

function ensure_users_columns(PDO $pdo): void
{
    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM users");

    foreach ($stmt->fetchAll() as $column) {
        $columns[] = $column['Field'];
    }

    if (!in_array('age', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN age INT UNSIGNED DEFAULT NULL AFTER full_name");
    }

    if (!in_array('qualification', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN qualification VARCHAR(190) DEFAULT NULL AFTER age");
    }
}

function ensure_users_role_enum(PDO $pdo): void
{
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    $type = strtolower((string) ($column['Type'] ?? ''));

    if (!str_contains($type, "'admin'")) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('doctor', 'caretaker', 'admin') NOT NULL");
    }
}

function ensure_admin_account(PDO $pdo): void
{
    $email = 'admin@caresync.local';
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);

    if ($stmt->fetch()) {
        return;
    }

    $passwordHash = '$2y$10$qgx2Z1BeA623az6YMS441O1fC9qHQRny353L59cHbawNrrkYWGfPW';
    $insert = $pdo->prepare(
        "INSERT INTO users (role, full_name, age, qualification, email, password_hash, profile_image)
         VALUES ('admin', 'CareSync Admin', 35, 'Platform Administrator', :email, :password_hash, NULL)"
    );
    $insert->execute([
        'email' => $email,
        'password_hash' => $passwordHash,
    ]);
}

function ensure_patients_constraints(PDO $pdo): void
{
    $indexes = $pdo->query("SHOW INDEX FROM patients")->fetchAll();

    foreach ($indexes as $index) {
        if (($index['Key_name'] ?? '') === 'caretaker_id' && (int) ($index['Non_unique'] ?? 1) === 0) {
            $pdo->exec("ALTER TABLE patients DROP FOREIGN KEY fk_patients_caretaker");
            $pdo->exec("ALTER TABLE patients DROP INDEX caretaker_id");
            $pdo->exec("ALTER TABLE patients ADD INDEX idx_patients_caretaker_id (caretaker_id)");
            $pdo->exec(
                "ALTER TABLE patients
                 ADD CONSTRAINT fk_patients_caretaker
                 FOREIGN KEY (caretaker_id) REFERENCES users(id) ON DELETE CASCADE"
            );
            break;
        }
    }
}

function run_schema_file(PDO $pdo): void
{
    $schemaPath = __DIR__ . '/schema.sql';

    run_sql_file($pdo, $schemaPath, 'schema.sql');
}

function import_project_data_if_needed(PDO $pdo): void
{
    if (has_project_data($pdo)) {
        return;
    }

    $dataPath = __DIR__ . '/data.sql';

    if (!is_file($dataPath)) {
        return;
    }

    run_sql_file($pdo, $dataPath, 'data.sql');
}

function has_project_data(PDO $pdo): bool
{
    $tables = ['users', 'patients', 'medicines', 'doctor_notes', 'chat_messages'];

    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");

        if (!$stmt->fetchColumn()) {
            continue;
        }

        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

        if ($count > 0) {
            if ($table === 'users' && $count === 1) {
                $adminOnly = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'admin'")->fetchColumn();

                if ($adminOnly === 0) {
                    continue;
                }
            }

            return true;
        }
    }

    return false;
}

function run_sql_file(PDO $pdo, string $path, string $label): void
{
    if (!is_file($path)) {
        throw new RuntimeException($label . ' file is missing.');
    }

    $sql = file_get_contents($path);

    if ($sql === false) {
        throw new RuntimeException('Could not read ' . $label . '.');
    }

    foreach (parse_sql_statements($sql) as $statement) {
        if ($statement === '') {
            continue;
        }

        $pdo->exec($statement);
    }
}

function parse_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $lines = preg_split("/\\r\\n|\\n|\\r/", $sql) ?: [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }

        $buffer .= ($buffer === '' ? '' : "\n") . $line;

        if (str_ends_with(rtrim($line), ';')) {
            $statement = trim($buffer);
            $statement = rtrim($statement, ';');

            if ($statement !== '') {
                $statements[] = $statement;
            }

            $buffer = '';
        }
    }

    $remaining = trim($buffer);

    if ($remaining !== '') {
        $statements[] = rtrim($remaining, ';');
    }

    return $statements;
}
