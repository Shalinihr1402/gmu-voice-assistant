<?php

function loadLocalEnvFile($filePath) {
    static $loadedFiles = [];

    if (isset($loadedFiles[$filePath]) || !is_file($filePath) || !is_readable($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        $separatorPosition = strpos($trimmed, '=');
        if ($separatorPosition === false) {
            continue;
        }

        $key = trim(substr($trimmed, 0, $separatorPosition));
        $value = trim(substr($trimmed, $separatorPosition + 1));

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $loadedFiles[$filePath] = true;
}

loadLocalEnvFile(__DIR__ . '/../.env');
