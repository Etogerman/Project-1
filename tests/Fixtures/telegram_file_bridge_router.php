<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (is_string($path) && str_ends_with($path, '/getFile')) {
    header('Content-Type: application/json');

    $fileId = (string) ($_GET['file_id'] ?? '');
    $result = match ($fileId) {
        'ownership-file-id' => [
            'file_path' => '/var/lib/telegram-bot-api/videos/ownership.bin',
            'file_size' => strlen('ownership-stays-open'),
        ],
        'slow-file-id' => [
            'file_path' => '/var/lib/telegram-bot-api/videos/slow.bin',
            'file_size' => 65536,
        ],
        'large-file-id' => [
            'file_path' => '/var/lib/telegram-bot-api/videos/51m.bin',
            'file_size' => 51 * 1024 * 1024,
        ],
        default => null,
    };

    if ($result === null) {
        http_response_code(404);
        echo json_encode(['ok' => false], JSON_THROW_ON_ERROR);

        return;
    }

    echo json_encode([
        'ok' => true,
        'result' => $result,
    ], JSON_THROW_ON_ERROR);

    return;
}

if ($path === '/files/videos/ownership.bin') {
    $payload = 'ownership-stays-open';
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.strlen($payload));
    echo $payload;

    return;
}

if ($path === '/files/videos/slow.bin') {
    header('Content-Type: application/octet-stream');
    header('Content-Length: 65536');
    header('X-Accel-Buffering: no');

    for ($chunk = 0; $chunk < 16; $chunk++) {
        echo str_repeat('x', 4096);
        flush();
        usleep(100_000);
    }

    return;
}

if ($path === '/files/videos/51m.bin') {
    header('Content-Type: video/mp4');
    header('Content-Length: '.(51 * 1024 * 1024));
    header('X-Accel-Buffering: no');

    for ($chunk = 0; $chunk < 51; $chunk++) {
        echo str_repeat('z', 1024 * 1024);
        flush();
    }

    return;
}

http_response_code(404);
