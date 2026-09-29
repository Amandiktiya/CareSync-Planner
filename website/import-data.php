<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$message = '';
$error = '';
$counts = [];

try {
    $pdo = db();

    if (isset($_GET['run']) && $_GET['run'] === '1') {
        run_sql_file($pdo, __DIR__ . '/data.sql', 'data.sql');
        $message = 'Data imported successfully from data.sql.';
    }

    foreach (['users', 'patients', 'medicines', 'doctor_notes', 'chat_messages'] as $table) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CareSync Data Import</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f7fb;
            color: #1d2a33;
        }

        .wrap {
            max-width: 760px;
            margin: 40px auto;
            background: #fff;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 18px 50px rgba(29, 42, 51, 0.08);
        }

        h1 {
            margin-top: 0;
        }

        .note,
        .success,
        .error {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 18px;
        }

        .note {
            background: #eef6ff;
        }

        .success {
            background: #eaf9f0;
            color: #11643f;
        }

        .error {
            background: #ffeceb;
            color: #a12622;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }

        th,
        td {
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #e7ecf3;
        }

        a.button {
            display: inline-block;
            margin-top: 10px;
            background: #0f8b6d;
            color: #fff;
            text-decoration: none;
            padding: 12px 18px;
            border-radius: 12px;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>CareSync Data Import</h1>

        <div class="note">
            Use this page on the other PC if phpMyAdmin shows empty tables after copying the project.
        </div>

        <?php if ($message !== ''): ?>
            <div class="success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <a class="button" href="import-data.php?run=1">Import Data Now</a>

        <table>
            <thead>
                <tr>
                    <th>Table</th>
                    <th>Rows</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($counts as $table => $count): ?>
                    <tr>
                        <td><?= htmlspecialchars($table, ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $count ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
