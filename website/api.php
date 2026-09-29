<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = require_login();
    $patient = get_linked_patient($user);

    if (!$patient || !can_access_patient($user, $patient)) {
        throw new RuntimeException('No linked patient found for chat.');
    }

    $action = $_GET['action'] ?? '';
    $patientId = (int) $patient['id'];

    if ($action === 'chat_fetch') {
        if ($user['role'] === 'doctor') {
            $receiverId = (int) $patient['caretaker_id'];
        } else {
            $receiverId = (int) $patient['doctor_id'];
        }

        $stmt = db()->prepare(
            'UPDATE chat_messages SET is_read = 1
             WHERE patient_id = :patient_id AND receiver_id = :receiver_id AND is_read = 0'
        );
        $stmt->execute([
            'patient_id' => $patientId,
            'receiver_id' => (int) $user['id'],
        ]);

        echo json_encode([
            'ok' => true,
            'messages' => get_chat_messages($patientId),
            'unread' => get_unread_chat_count($patientId, (int) $user['id']),
            'partnerId' => $receiverId,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'chat_send') {
        $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        $message = trim((string) ($payload['message'] ?? ''));

        if ($message === '') {
            throw new RuntimeException('Please type a message before sending.');
        }

        $receiverId = $user['role'] === 'doctor' ? (int) $patient['caretaker_id'] : (int) $patient['doctor_id'];

        $stmt = db()->prepare(
            'INSERT INTO chat_messages (patient_id, sender_id, receiver_id, message_text)
             VALUES (:patient_id, :sender_id, :receiver_id, :message_text)'
        );
        $stmt->execute([
            'patient_id' => $patientId,
            'sender_id' => (int) $user['id'],
            'receiver_id' => $receiverId,
            'message_text' => $message,
        ]);

        echo json_encode([
            'ok' => true,
            'messages' => get_chat_messages($patientId),
            'unread' => get_unread_chat_count($patientId, (int) $user['id']),
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'chat_mark_read') {
        $stmt = db()->prepare(
            'UPDATE chat_messages SET is_read = 1
             WHERE patient_id = :patient_id AND receiver_id = :receiver_id AND is_read = 0'
        );
        $stmt->execute([
            'patient_id' => $patientId,
            'receiver_id' => (int) $user['id'],
        ]);

        echo json_encode([
            'ok' => true,
            'unread' => get_unread_chat_count($patientId, (int) $user['id']),
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    throw new RuntimeException('Unsupported API action.');
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
