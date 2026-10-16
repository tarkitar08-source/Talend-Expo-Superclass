<?php
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($status, $data)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function requestBody()
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    return is_array($body) ? $body : [];
}

function withVotesStore($callback)
{
    $directory = __DIR__ . '/data';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder penyimpanan tidak dapat dibuat.');
    }

    $lock = fopen($directory . '/votes.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Penyimpanan voting tidak dapat dikunci.');
    }

    try {
        $file = $directory . '/votes.json';
        $contents = is_file($file) ? file_get_contents($file) : '[]';
        $votes = json_decode($contents, true);
        if (!is_array($votes)) {
            throw new RuntimeException('Data voting pada server tidak valid.');
        }

        $result = $callback($votes);
        if (file_put_contents($file, json_encode(array_values($votes), JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new RuntimeException('Data voting gagal ditulis ke server.');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function requireAdmin()
{
    if (empty($_SESSION['admin_authenticated'])) {
        respond(401, ['success' => false, 'message' => 'Silakan login sebagai admin terlebih dahulu.']);
    }
}

$action = $_GET['action'] ?? '';
$body = requestBody();

try {
    if ($action === 'admin_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (($body['username'] ?? '') !== 'admin' || ($body['password'] ?? '') !== 'wbodJ_LKhkN9u0KezpaRwXrn-9GkN1ch') {
            respond(401, ['success' => false, 'message' => 'Username atau password admin salah.']);
        }
        session_regenerate_id(true);
        $_SESSION['admin_authenticated'] = true;
        respond(200, ['success' => true]);
    }

    if ($action === 'voter_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = strtolower(trim($body['username'] ?? ''));
        $password = $body['password'] ?? '';
        if (!preg_match('/^visitor[a-z0-9_-]{0,32}$/', $username) || $password !== 'pass123') {
            respond(401, ['success' => false, 'message' => 'Username atau password tidak sesuai.']);
        }

        session_regenerate_id(true);
        $_SESSION['voter_username'] = $username;
        $hasVoted = withVotesStore(function (&$votes) use ($username) {
            foreach ($votes as $vote) {
                if (($vote['username'] ?? '') === $username) {
                    return true;
                }
            }
            return false;
        });
        respond(200, ['success' => true, 'hasVoted' => $hasVoted]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        unset($_SESSION['voter_username']);
        respond(200, ['success' => true]);
    }

    if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = $_SESSION['voter_username'] ?? '';
        $submittedVotes = $body['votes'] ?? null;
        if ($username === '') {
            respond(401, ['success' => false, 'message' => 'Silakan login sebagai voter terlebih dahulu.']);
        }
        if (!is_array($submittedVotes)) {
            respond(400, ['success' => false, 'message' => 'Data voting tidak valid.']);
        }

        $votes = [];
        $total = 0;
        for ($stanId = 1; $stanId <= 12; $stanId++) {
            $count = $submittedVotes[(string) $stanId] ?? $submittedVotes[$stanId] ?? null;
            if (!is_int($count) || $count < 0 || $count > 12) {
                respond(400, ['success' => false, 'message' => 'Alokasi bintang tidak valid.']);
            }
            $votes[$stanId] = $count;
            $total += $count;
        }
        if ($total !== 12) {
            respond(400, ['success' => false, 'message' => 'Voting harus menggunakan tepat 12 bintang.']);
        }

        withVotesStore(function (&$storedVotes) use ($username, $votes) {
            foreach ($storedVotes as $vote) {
                if (($vote['username'] ?? '') === $username) {
                    respond(409, ['success' => false, 'message' => 'Akun ini sudah pernah melakukan voting.']);
                }
            }
            $storedVotes[] = [
                'username' => $username,
                'votes' => $votes,
                'timestamp' => date(DATE_ATOM),
                'totalStarsUsed' => 12,
            ];
            return true;
        });
        respond(201, ['success' => true]);
    }

    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        requireAdmin();
        $storedVotes = withVotesStore(function (&$votes) {
            return $votes;
        });
        respond(200, ['success' => true, 'votes' => $storedVotes]);
    }

    if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        requireAdmin();
        withVotesStore(function (&$votes) {
            $votes = [];
            return true;
        });
        respond(200, ['success' => true]);
    }

    respond(404, ['success' => false, 'message' => 'Endpoint tidak ditemukan.']);
} catch (Throwable $error) {
    error_log('Voting API error: ' . $error->getMessage());
    respond(500, ['success' => false, 'message' => 'Terjadi kesalahan saat mengakses penyimpanan voting.']);
}