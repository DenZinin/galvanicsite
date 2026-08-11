<?php
/**
 * Loads environment variables from a .env file.
 * Prefers server-provided vars (Apache SetEnv, hosting panel) over file values.
 * Search order: ../.env (outside web root), then ./.env
 */
function loadEnvFile(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $equalsPos = strpos($line, '=');
        if ($equalsPos === false) {
            continue;
        }

        $name = trim(substr($line, 0, $equalsPos));
        $value = trim(substr($line, $equalsPos + 1));

        if ($name === '') {
            continue;
        }

        if (
            (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') ||
            (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'")
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) !== false) {
            continue;
        }

        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function bootstrapEnv(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $candidates = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env',
        __DIR__ . DIRECTORY_SEPARATOR . '.env',
    ];

    foreach ($candidates as $path) {
        loadEnvFile($path);
    }

    $loaded = true;
}

function requireEnv(string $name): string
{
    bootstrapEnv();

    $value = getenv($name);
    if ($value === false || $value === '') {
        error_log("Missing required environment variable: $name");
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Серверная конфигурация не настроена. Обратитесь к администратору.',
        ]);
        exit;
    }

    return $value;
}
