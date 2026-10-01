<?php
session_start();

const DUEL_ROOM_TTL = 86400;

function duel_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function duel_storage_directory(): string|false
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "hacker-game-duels";
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }

    return $directory;
}

function duel_server_lan_host(): string
{
    $requestHost = strtolower((string)($_SERVER["HTTP_HOST"] ?? ""));
    $host = parse_url("http://" . $requestHost, PHP_URL_HOST);
    $localHosts = ["localhost", "127.0.0.1", "0.0.0.0", "::1"];

    if (
        is_string($host)
        && !in_array($host, $localHosts, true)
        && preg_match("/^[a-z0-9.-]+$/", $host)
    ) {
        return $host;
    }

    $resolvedHost = gethostbyname(gethostname());
    if (
        filter_var($resolvedHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && !str_starts_with($resolvedHost, "127.")
    ) {
        return $resolvedHost;
    }

    return "localhost";
}

function duel_room_path(string $roomCode): string|false
{
    if (!preg_match("/^[A-F0-9]{8}$/", $roomCode)) {
        return false;
    }

    $directory = duel_storage_directory();
    return $directory === false ? false : $directory . DIRECTORY_SEPARATOR . $roomCode . ".json";
}

function duel_with_room(string $roomCode, callable $operation): array
{
    $path = duel_room_path($roomCode);
    if ($path === false || !is_file($path)) {
        return ["ok" => false, "error" => "Комната не найдена. Проверь код приглашения."];
    }

    $handle = @fopen($path, "c+");
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        return ["ok" => false, "error" => "Не удалось открыть комнату. Попробуй ещё раз."];
    }

    rewind($handle);
    $room = json_decode(stream_get_contents($handle), true);
    if (!is_array($room)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return ["ok" => false, "error" => "Данные комнаты повреждены."];
    }

    if (!isset($room["created_at"]) || time() - $room["created_at"] > DUEL_ROOM_TTL) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return ["ok" => false, "error" => "Срок действия комнаты истёк. Создай новую."];
    }

    $result = $operation($room);
    if (!is_array($result)) {
        $result = ["ok" => false, "error" => "Не удалось обработать запрос."];
    }

    if (!empty($result["_write"])) {
        unset($result["_write"]);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($room, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);
    return $result;
}

function duel_public_state(array $room, string $role): array
{
    $state = [
        "room_code" => $room["room_code"],
        "role" => $role,
        "status" => $room["status"],
        "attempts_left" => $room["attempts_left"],
        "attempts_total" => $room["attempts_total"],
        "history" => $room["history"],
        "message" => $room["message"],
        "version" => $room["version"],
        "attacker_connected" => $room["attacker_token"] !== null,
        "rekey_available" => $role === "defender"
            && $room["status"] === "active"
            && !$room["rekey_used"]
            && count($room["history"]) >= 2,
        "rekey_used" => $room["rekey_used"],
    ];

    if ($role === "defender" || $room["status"] !== "active") {
        $state["secret"] = $room["secret"];
    }

    return $state;
}

function duel_role_for_token(array $room, string $token): ?string
{
    if ($token !== "" && hash_equals($room["defender_token"], $token)) {
        return "defender";
    }
    if ($token !== "" && is_string($room["attacker_token"]) && hash_equals($room["attacker_token"], $token)) {
        return "attacker";
    }

    return null;
}

function duel_create_room(string $secret, int $attempts): array|false
{
    $directory = duel_storage_directory();
    if ($directory === false) {
        return false;
    }

    foreach (glob($directory . DIRECTORY_SEPARATOR . "*.json") ?: [] as $oldPath) {
        $oldData = @file_get_contents($oldPath);
        $oldRoom = is_string($oldData) ? json_decode($oldData, true) : null;
        if (
            !is_array($oldRoom)
            || !isset($oldRoom["created_at"])
            || time() - $oldRoom["created_at"] > DUEL_ROOM_TTL
        ) {
            @unlink($oldPath);
        }
    }

    for ($try = 0; $try < 5; $try++) {
        $roomCode = strtoupper(bin2hex(random_bytes(4)));
        $path = $directory . DIRECTORY_SEPARATOR . $roomCode . ".json";
        $handle = @fopen($path, "x");
        if ($handle === false) {
            continue;
        }

        $defenderToken = bin2hex(random_bytes(24));
        $room = [
            "room_code" => $roomCode,
            "defender_token" => $defenderToken,
            "attacker_token" => null,
            "secret" => $secret,
            "status" => "waiting",
            "attempts_total" => $attempts,
            "attempts_left" => $attempts,
            "history" => [],
            "message" => "Ожидаем подключение взломщика.",
            "rekey_used" => false,
            "version" => 1,
            "created_at" => time(),
        ];

        flock($handle, LOCK_EX);
        fwrite($handle, json_encode($room, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return [$room, $defenderToken];
    }

    return false;
}

$inviteHost = duel_server_lan_host();

if (isset($_GET["api"])) {
    $input = json_decode(file_get_contents("php://input"), true);
    if (!is_array($input)) {
        duel_json_response(["ok" => false, "error" => "Некорректный запрос."], 400);
    }

    $action = isset($input["action"]) && is_string($input["action"]) ? $input["action"] : "";

    if ($action === "create") {
        $secret = isset($input["secret"]) && is_string($input["secret"]) ? trim($input["secret"]) : "";
        $attempts = filter_var($input["attempts"] ?? 7, FILTER_VALIDATE_INT);
        if (!preg_match("/^[0-9]{4}$/", $secret)) {
            duel_json_response(["ok" => false, "error" => "Ключ должен состоять ровно из четырёх цифр."], 400);
        }
        if (!in_array($attempts, [5, 7, 9], true)) {
            duel_json_response(["ok" => false, "error" => "Выбери доступный уровень защиты."], 400);
        }

        $created = duel_create_room($secret, $attempts);
        if ($created === false) {
            duel_json_response(["ok" => false, "error" => "Не удалось создать комнату."], 500);
        }

        [$room, $token] = $created;
        $_SESSION["duel_tokens"][$room["room_code"]] = $token;
        duel_json_response([
            "ok" => true,
            "state" => duel_public_state($room, "defender"),
        ]);
    }

    if ($action === "join") {
        $roomCode = isset($input["room_code"]) && is_string($input["room_code"])
            ? strtoupper(trim($input["room_code"]))
            : "";
        $token = bin2hex(random_bytes(24));
        $result = duel_with_room($roomCode, function (array &$room) use ($token): array {
            if ($room["status"] !== "waiting" || $room["attacker_token"] !== null) {
                return ["ok" => false, "error" => "Комната уже занята или матч начался."];
            }

            $room["attacker_token"] = $token;
            $room["status"] = "active";
            $room["message"] = "Взломщик подключился. Защита активна.";
            $room["version"]++;
            return ["ok" => true, "_write" => true, "state" => duel_public_state($room, "attacker")];
        });

        if (!empty($result["ok"])) {
            $_SESSION["duel_tokens"][$roomCode] = $token;
        }
        duel_json_response($result, !empty($result["ok"]) ? 200 : 400);
    }

    $roomCode = isset($input["room_code"]) && is_string($input["room_code"])
        ? strtoupper(trim($input["room_code"]))
        : "";
    $token = $_SESSION["duel_tokens"][$roomCode] ?? "";
    if (!is_string($token) || $token === "") {
        duel_json_response(["ok" => false, "error" => "Нет доступа к этой комнате в текущей сессии."], 403);
    }

    if ($action === "state") {
        $result = duel_with_room($roomCode, function (array &$room) use ($token): array {
            $role = duel_role_for_token($room, $token);
            if ($role === null) {
                return ["ok" => false, "error" => "Сессия игрока не найдена в этой комнате."];
            }

            return ["ok" => true, "state" => duel_public_state($room, $role)];
        });
        duel_json_response($result, !empty($result["ok"]) ? 200 : 400);
    }

    if ($action === "guess") {
        $guess = isset($input["guess"]) && is_string($input["guess"]) ? trim($input["guess"]) : "";
        if (!preg_match("/^[0-9]{4}$/", $guess)) {
            duel_json_response(["ok" => false, "error" => "Введи ровно четыре цифры."], 400);
        }

        $result = duel_with_room($roomCode, function (array &$room) use ($token, $guess): array {
            $role = duel_role_for_token($room, $token);
            if ($role !== "attacker") {
                return ["ok" => false, "error" => "Только взломщик может отправлять догадки."];
            }
            if ($room["status"] !== "active") {
                return ["ok" => false, "error" => "Матч уже завершён или ещё не начался."];
            }

            $secretDigits = str_split($room["secret"]);
            $guessDigits = str_split($guess);
            $remaining = array_count_values($secretDigits);
            $feedback = array_fill(0, 4, "🔴");

            for ($position = 0; $position < 4; $position++) {
                if ($guessDigits[$position] === $secretDigits[$position]) {
                    $feedback[$position] = "🟢";
                    $remaining[$guessDigits[$position]]--;
                }
            }
            for ($position = 0; $position < 4; $position++) {
                if ($feedback[$position] === "🟢") {
                    continue;
                }
                if (($remaining[$guessDigits[$position]] ?? 0) > 0) {
                    $feedback[$position] = "🟡";
                    $remaining[$guessDigits[$position]]--;
                }
            }

            $room["history"][] = ["guess" => $guess, "feedback" => implode("", $feedback)];
            $room["attempts_left"]--;
            if ($guess === $room["secret"]) {
                $room["status"] = "attacker_won";
                $room["message"] = "Взлом удался. Сервер открыт.";
            } elseif ($room["attempts_left"] <= 0) {
                $room["status"] = "defender_won";
                $room["message"] = "Защита устояла. Попытки взломщика закончились.";
            } else {
                $room["message"] = "Получен новый след атаки.";
            }
            $room["version"]++;

            return ["ok" => true, "_write" => true, "state" => duel_public_state($room, "attacker")];
        });
        duel_json_response($result, !empty($result["ok"]) ? 200 : 400);
    }

    if ($action === "rekey") {
        $newSecret = isset($input["secret"]) && is_string($input["secret"]) ? trim($input["secret"]) : "";
        if (!preg_match("/^[0-9]{4}$/", $newSecret)) {
            duel_json_response(["ok" => false, "error" => "Новый ключ должен состоять из четырёх цифр."], 400);
        }

        $result = duel_with_room($roomCode, function (array &$room) use ($token, $newSecret): array {
            $role = duel_role_for_token($room, $token);
            if ($role !== "defender") {
                return ["ok" => false, "error" => "Менять ключ может только защитник."];
            }
            if (
                $room["status"] !== "active"
                || $room["rekey_used"]
                || count($room["history"]) < 2
            ) {
                return ["ok" => false, "error" => "Ротация ключа сейчас недоступна."];
            }
            if ($newSecret === $room["secret"]) {
                return ["ok" => false, "error" => "Новый ключ должен отличаться от прежнего."];
            }

            $room["secret"] = $newSecret;
            $room["rekey_used"] = true;
            $room["history"] = [];
            $room["attempts_left"]--;
            if ($room["attempts_left"] <= 0) {
                $room["status"] = "defender_won";
                $room["message"] = "Защитник сменил ключ и закрыл сервер.";
            } else {
                $room["message"] = "Ключ сменён. Старые подсказки аннулированы; защита потратила один ход.";
            }
            $room["version"]++;

            return ["ok" => true, "_write" => true, "state" => duel_public_state($room, "defender")];
        });
        duel_json_response($result, !empty($result["ok"]) ? 200 : 400);
    }

    duel_json_response(["ok" => false, "error" => "Неизвестное действие."], 400);
}
?>
<!DOCTYPE html>
<html lang="ru" data-theme="matrix">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#07120f">
    <title>Сетевая дуэль · Hacker Game</title>
    <style>
        :root {
            color-scheme: dark;
            --page: #07120f;
            --surface: #0d1d17;
            --surface-raised: #11271e;
            --border: #244a37;
            --accent: #79efb2;
            --accent-soft: #143c2b;
            --secondary: #74d8dc;
            --text: #e6f7ed;
            --muted: #9bb2a4;
            --danger: #ff7878;
            --win: #b7f57b;
            --shadow: rgba(0, 0, 0, 0.28);
        }

        :root[data-theme="arctic"] {
            --page: #09121d;
            --surface: #111f30;
            --surface-raised: #182b40;
            --border: #355b75;
            --accent: #74dfff;
            --accent-soft: #173747;
            --secondary: #b1edff;
            --text: #eaf7ff;
            --muted: #a2bbca;
            --danger: #ff858b;
            --win: #b7f57b;
        }

        :root[data-theme="ember"] {
            --page: #17100f;
            --surface: #241816;
            --surface-raised: #35221d;
            --border: #704033;
            --accent: #ffae70;
            --accent-soft: #46291f;
            --secondary: #ffd18f;
            --text: #fff1e7;
            --muted: #c6a99a;
            --danger: #ff7272;
            --win: #c7ef80;
        }

        :root[data-theme="violet"] {
            --page: #100d19;
            --surface: #1b1728;
            --surface-raised: #282039;
            --border: #51416e;
            --accent: #c2a5ff;
            --accent-soft: #302548;
            --secondary: #9bd8ed;
            --text: #f2edff;
            --muted: #b2a8c8;
            --danger: #ff818b;
            --win: #c3ef88;
        }

        * { box-sizing: border-box; }

        body {
            min-width: 320px;
            min-height: 100vh;
            margin: 0;
            padding: 22px;
            background:
                linear-gradient(rgba(255, 255, 255, 0.018) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.018) 1px, transparent 1px),
                var(--page);
            background-size: 34px 34px, 34px 34px, auto;
            color: var(--text);
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            transition: background-color 0.25s, color 0.25s;
        }

        button, input, select { font: inherit; }
        button, select { cursor: pointer; }

        .site-shell { width: min(1120px, 100%); margin: 0 auto; }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--border);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--text);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
        }

        .brand-mark {
            display: grid;
            width: 34px;
            height: 34px;
            place-items: center;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--accent);
            font-family: Consolas, monospace;
        }

        .theme-control {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
        }

        select, input {
            min-width: 0;
            border: 1px solid var(--border);
            border-radius: 7px;
            outline: none;
            background: var(--surface);
            color: var(--text);
        }

        select { padding: 9px 32px 9px 10px; }
        input { width: 100%; padding: 12px 13px; }
        input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }

        .page-intro {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            padding: 30px 0 24px;
        }

        .eyebrow {
            margin: 0 0 8px;
            color: var(--secondary);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
        }

        h1 { margin: 0; font-size: 30px; line-height: 1.2; }
        .intro-copy { max-width: 500px; margin: 9px 0 0; color: var(--muted); font-size: 14px; line-height: 1.5; }

        .network-badge {
            flex: 0 0 auto;
            padding: 8px 10px;
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--accent);
            font-family: Consolas, monospace;
            font-size: 11px;
        }

        .panel {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            box-shadow: 0 18px 46px var(--shadow);
        }

        .lobby-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1px;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--border);
            box-shadow: 0 18px 46px var(--shadow);
        }

        .lobby-side {
            min-width: 0;
            padding: 24px;
            background: var(--surface);
        }

        .side-label { margin: 0 0 8px; color: var(--secondary); font-size: 11px; font-weight: 700; letter-spacing: 1px; }
        h2 { margin: 0; font-size: 21px; }
        .side-copy { margin: 8px 0 20px; color: var(--muted); font-size: 13px; line-height: 1.5; }

        .field { display: grid; gap: 7px; margin-top: 14px; }
        .field label { color: var(--muted); font-size: 12px; }
        .field-hint { color: var(--muted); font-size: 11px; }

        .primary-button, .secondary-button, .danger-button {
            min-height: 44px;
            padding: 10px 14px;
            border: 1px solid transparent;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 700;
            transition: background-color 0.18s, border-color 0.18s, transform 0.18s;
        }

        .primary-button { background: var(--accent); color: var(--page); }
        .primary-button:hover { filter: brightness(1.08); }
        .secondary-button { border-color: var(--border); background: var(--surface-raised); color: var(--text); }
        .secondary-button:hover { border-color: var(--accent); }
        .danger-button { border-color: color-mix(in srgb, var(--danger) 35%, var(--border)); background: transparent; color: var(--danger); }
        .primary-button:disabled, .secondary-button:disabled, .danger-button:disabled { opacity: 0.45; cursor: not-allowed; }
        .form-button { width: 100%; margin-top: 18px; }

        .error-message { min-height: 20px; margin: 12px 0 0; color: var(--danger); font-size: 12px; }
        .lobby-footer { display: flex; justify-content: space-between; gap: 16px; margin-top: 14px; color: var(--muted); font-size: 12px; }
        .lobby-footer a { color: var(--secondary); text-underline-offset: 3px; }

        .match-topline { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 20px 22px; border-bottom: 1px solid var(--border); }
        .room-label { margin: 0 0 5px; color: var(--muted); font-size: 10px; font-weight: 700; letter-spacing: 1px; }
        .room-code { color: var(--accent); font-family: Consolas, monospace; font-size: 23px; letter-spacing: 2px; }
        .role-chip { padding: 7px 10px; border: 1px solid var(--border); border-radius: 99px; color: var(--secondary); font-size: 11px; white-space: nowrap; }

        .match-grid { display: grid; grid-template-columns: minmax(0, 1fr) 280px; }
        .match-main { min-width: 0; padding: 22px; }
        .match-aside { padding: 22px; border-left: 1px solid var(--border); background: color-mix(in srgb, var(--surface-raised) 44%, transparent); }
        .state-banner { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-left: 3px solid var(--accent); background: var(--surface-raised); color: var(--text); font-size: 13px; line-height: 1.45; }
        .state-banner[data-status="attacker_won"] { border-color: var(--win); }
        .state-banner[data-status="defender_won"] { border-color: var(--danger); }

        .attempt-meter { display: flex; flex-wrap: wrap; gap: 6px; margin: 18px 0; }
        .attempt-pip { width: 22px; height: 6px; border-radius: 2px; background: var(--accent); }
        .attempt-pip.spent { background: var(--border); }
        .attempt-label { margin-left: auto; color: var(--muted); font-size: 12px; }

        .action-area { min-height: 104px; padding: 16px; border: 1px solid var(--border); border-radius: 7px; background: var(--page); }
        .action-title { margin: 0 0 10px; color: var(--muted); font-size: 11px; font-weight: 700; letter-spacing: 1px; }
        .guess-row { display: flex; gap: 9px; }
        .guess-input { max-width: 190px; font-family: Consolas, monospace; font-size: 22px; letter-spacing: 7px; text-align: center; }
        .guess-row button { flex: 0 0 auto; }
        .action-note { margin: 10px 0 0; color: var(--muted); font-size: 12px; line-height: 1.45; }

        .history { margin-top: 20px; }
        .history h3 { margin: 0 0 10px; font-size: 13px; }
        .history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .history-table th { padding: 8px; color: var(--muted); font-size: 10px; font-weight: 600; text-align: left; }
        .history-table td { padding: 9px 8px; border-top: 1px solid var(--border); }
        .history-table td:first-child { width: 45px; color: var(--muted); }
        .history-guess { font-family: Consolas, monospace; font-size: 16px; letter-spacing: 3px; }
        .history-signal { font-size: 18px; letter-spacing: 2px; }
        .empty-history { color: var(--muted); font-size: 12px; }

        .share-block { margin-bottom: 22px; }
        .share-link { display: flex; gap: 7px; margin-top: 10px; }
        .share-link input { padding: 9px; font-size: 11px; }
        .share-link button { flex: 0 0 auto; padding: 8px 10px; font-size: 11px; }
        .security-key { display: flex; gap: 7px; margin: 12px 0 18px; }
        .key-digit { display: grid; width: 38px; height: 46px; place-items: center; border: 1px solid var(--border); border-radius: 6px; background: var(--page); color: var(--accent); font-family: Consolas, monospace; font-size: 20px; }
        .result-line { margin-top: 18px; padding: 12px; border-radius: 6px; background: var(--accent-soft); color: var(--text); font-size: 13px; line-height: 1.5; }
        .leave-row { margin-top: 16px; }
        .site-footer { margin-top: 16px; color: var(--muted); font-size: 11px; line-height: 1.5; }

        @media (max-width: 760px) {
            body { padding: 14px; }
            .page-intro { align-items: flex-start; flex-direction: column; padding-top: 24px; }
            .lobby-grid { grid-template-columns: minmax(0, 1fr); }
            .lobby-side { padding: 20px; }
            .match-grid { grid-template-columns: minmax(0, 1fr); }
            .match-aside { border-top: 1px solid var(--border); border-left: 0; }
        }

        @media (max-width: 480px) {
            .topbar { align-items: flex-start; flex-direction: column; }
            .theme-control { width: 100%; justify-content: space-between; }
            h1 { font-size: 25px; }
            .match-topline { align-items: flex-start; flex-direction: column; padding: 16px; }
            .match-main, .match-aside { padding: 16px; }
            .guess-row { align-items: stretch; flex-direction: column; }
            .guess-input { max-width: none; }
            .guess-row button { width: 100%; }
            .attempt-label { width: 100%; margin-left: 0; }
            .lobby-footer { flex-direction: column; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body>
<div class="site-shell">
    <header class="topbar">
        <a class="brand" href="/">
            <span class="brand-mark">HG</span>
            <span>HACKER GAME <span style="color:var(--muted)">/ DUEL</span></span>
        </a>
        <label class="theme-control" for="themeSelect">
            <span>Оформление</span>
            <select id="themeSelect" aria-label="Цветовая тема">
                <option value="matrix">Матрица</option>
                <option value="arctic">Арктика</option>
                <option value="ember">Импульс</option>
                <option value="violet">Сумрак</option>
            </select>
        </label>
    </header>

    <section class="page-intro">
        <div>
            <p class="eyebrow">ЛОКАЛЬНАЯ СЕТЕВАЯ ИГРА</p>
            <h1>Сетевая дуэль</h1>
            <p class="intro-copy">Один строит защиту. Второй пытается пробить её код.</p>
        </div>
        <span class="network-badge">2 PLAYERS · LAN</span>
    </section>

    <main id="app" aria-live="polite">
        <div class="lobby-grid">
            <section class="lobby-side">
                <p class="side-label">СТОРОНА ЗАЩИТЫ</p>
                <h2>Создать сервер</h2>
                <p class="side-copy">Задай секретный ключ и настрой запас защиты.</p>
                <form id="createForm">
                    <div class="field">
                        <label for="secret">Секретный ключ</label>
                        <input id="secret" name="secret" type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="4 цифры" autocomplete="new-password" required>
                        <span class="field-hint">Ключ виден только защитнику.</span>
                    </div>
                    <div class="field">
                        <label for="attempts">Запас попыток взломщика</label>
                        <select id="attempts" name="attempts">
                            <option value="9">Мягкая защита · 9</option>
                            <option value="7" selected>Стандарт · 7</option>
                            <option value="5">Жёсткая защита · 5</option>
                        </select>
                    </div>
                    <button class="primary-button form-button" type="submit">Создать комнату</button>
                </form>
            </section>

            <section class="lobby-side">
                <p class="side-label">СТОРОНА АТАКИ</p>
                <h2>Подключиться</h2>
                <p class="side-copy">Введи код комнаты, который сообщил защитник.</p>
                <form id="joinForm">
                    <div class="field">
                        <label for="roomCodeInput">Код комнаты</label>
                        <input id="roomCodeInput" name="room_code" type="text" maxlength="8" pattern="[A-Fa-f0-9]{8}" placeholder="Например, A4C91F2B" autocomplete="off" required>
                    </div>
                    <button class="secondary-button form-button" type="submit">Войти как взломщик</button>
                </form>
                <p id="formError" class="error-message" role="status" aria-live="polite"></p>
            </section>
        </div>
        <div class="lobby-footer">
            <span>Комната работает на PHP-сервере, внешний сервис не нужен.</span>
            <a href="/">Вернуться в одиночную игру</a>
        </div>
    </main>

    <footer class="site-footer">Подключение второго игрока доступно в одной локальной сети, если оба открыли адрес этого сервера.</footer>
</div>

<script>
(() => {
    const app = document.getElementById('app');
    const themeSelect = document.getElementById('themeSelect');
    const apiUrl = `${location.pathname}?duel=1&api=1`;
    const serverLanHost = <?= json_encode($inviteHost, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    let currentRoom = '';
    let lastVersion = -1;
    let pollTimer = null;
    let requestBusy = false;

    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);

    const setTheme = (theme) => {
        const allowedThemes = ['matrix', 'arctic', 'ember', 'violet'];
        const selected = allowedThemes.includes(theme) ? theme : 'matrix';
        document.documentElement.dataset.theme = selected;
        themeSelect.value = selected;
        try { localStorage.setItem('hacker-game-theme', selected); } catch (_) {}
    };

    try { setTheme(localStorage.getItem('hacker-game-theme') || 'matrix'); } catch (_) { setTheme('matrix'); }
    themeSelect.addEventListener('change', () => setTheme(themeSelect.value));

    async function api(payload) {
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            cache: 'no-store'
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'Ошибка соединения.');
        return data;
    }

    function showFormError(message) {
        const error = document.getElementById('formError');
        if (error) error.textContent = message;
    }

    function connect(state, force = false) {
        currentRoom = state.room_code;
        try {
            localStorage.setItem('hacker-game-duel-room', currentRoom);
            const invite = new URL(location.href);
            if (['localhost', '127.0.0.1', '0.0.0.0', '::1'].includes(invite.hostname)) {
                invite.hostname = serverLanHost;
            }
            invite.search = '';
            invite.searchParams.set('duel', '1');
            invite.searchParams.set('room', currentRoom);
            localStorage.setItem('hacker-game-duel-invite', invite.toString());
        } catch (_) {}
        if (force || state.version !== lastVersion) renderRoom(state);
        lastVersion = state.version;
        startPolling();
    }

    function startPolling() {
        if (pollTimer) clearTimeout(pollTimer);
        const poll = async () => {
            if (!currentRoom) return;
            try {
                const response = await api({ action: 'state', room_code: currentRoom });
                if (response.state.version !== lastVersion) {
                    lastVersion = response.state.version;
                    renderRoom(response.state);
                }
            } catch (error) {
                showFormError(error.message);
            }
            pollTimer = setTimeout(poll, 1300);
        };
        pollTimer = setTimeout(poll, 900);
    }

    function renderRoom(state) {
        const roleName = state.role === 'defender' ? 'ЗАЩИТНИК' : 'ВЗЛОМЩИК';
        const statusLabel = state.status === 'waiting'
            ? 'Ожидание игрока'
            : state.status === 'active'
                ? 'Матч идёт'
                : state.status === 'attacker_won'
                    ? 'Сервер взломан'
                    : 'Защита устояла';
        const pips = Array.from({ length: state.attempts_total }, (_, index) =>
            `<span class="attempt-pip ${index >= state.attempts_left ? 'spent' : ''}"></span>`
        ).join('');
        const history = state.history.length
            ? `<table class="history-table"><thead><tr><th>#</th><th>Код</th><th>Сигнал</th></tr></thead><tbody>${state.history.map((entry, index) =>
                `<tr><td>${index + 1}</td><td class="history-guess">${escapeHtml(entry.guess)}</td><td class="history-signal">${escapeHtml(entry.feedback)}</td></tr>`
            ).join('')}</tbody></table>`
            : '<p class="empty-history">Атак пока нет.</p>';
        const inviteUrl = (() => {
            try { return localStorage.getItem('hacker-game-duel-invite') || ''; } catch (_) { return ''; }
        })();
        const actionArea = state.role === 'attacker' && state.status === 'active'
            ? `<form id="guessForm"><p class="action-title">ПОДОБРАТЬ КОД</p><div class="guess-row"><input class="guess-input" name="guess" type="text" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="0000" aria-label="Код из четырёх цифр" autocomplete="off" required><button class="primary-button" type="submit">Атаковать</button></div></form>`
            : state.role === 'defender' && state.status === 'active'
                ? `<form id="rekeyForm"><p class="action-title">РЕЗЕРВНЫЙ ПРОТОКОЛ</p><div class="guess-row"><input class="guess-input" name="secret" type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="Новый ключ" aria-label="Новый секретный ключ" autocomplete="new-password" required><button class="danger-button" type="submit" ${state.rekey_available ? '' : 'disabled'}>Сменить ключ</button></div><p class="action-note">Доступно после двух атак. Ротация аннулирует подсказки и отнимает у взломщика одну попытку.</p></form>`
                : state.status === 'waiting'
                    ? '<p class="action-title">ЗАЩИТНЫЙ КОНТУР</p><p class="action-note">Передай код комнаты второму игроку. Матч начнётся после его подключения.</p>'
                    : `<p class="action-title">СЕССИЯ ЗАВЕРШЕНА</p><p class="action-note">${state.status === 'attacker_won' ? 'Взломщик победил.' : 'Защитник удержал сервер.'}</p><button class="secondary-button leave-row" type="button" data-action="leave">Вернуться в меню</button>`;

        const secretPanel = state.role === 'defender' || state.status !== 'active'
            ? `<p class="action-title">${state.status === 'active' ? 'ТЕКУЩИЙ СЕКРЕТНЫЙ КЛЮЧ' : 'КЛЮЧ СЕРВЕРА'}</p><div class="security-key" aria-label="Секретный ключ">${String(state.secret || '').split('').map((digit) => `<span class="key-digit">${escapeHtml(digit)}</span>`).join('')}</div>`
            : '<p class="action-title">ЗАДАЧА</p><p class="action-note">Подбери четырёхзначный ключ до того, как закончатся попытки.</p>';
        const sharePanel = state.role === 'defender'
            ? `<div class="share-block"><p class="action-title">КОД ПРИГЛАШЕНИЯ</p><div class="room-code">${escapeHtml(state.room_code)}</div>${state.status === 'waiting' ? `<div class="share-link"><input id="inviteInput" value="${escapeHtml(inviteUrl)}" readonly aria-label="Ссылка приглашения"><button class="secondary-button" type="button" data-action="copy" aria-label="Скопировать приглашение">Копировать</button></div>` : ''}</div>`
            : `<div class="share-block"><p class="action-title">КОМНАТА</p><div class="room-code">${escapeHtml(state.room_code)}</div></div>`;
        const resultPanel = state.status === 'active' && state.role === 'defender'
            ? `<div class="result-line">${state.rekey_used ? 'Резервный ключ уже использован.' : state.history.length >= 2 ? 'Резервный протокол готов.' : `До ротации ключа: ${2 - state.history.length} атак.`}</div>`
            : state.status === 'attacker_won' || state.status === 'defender_won'
                ? `<div class="result-line">${escapeHtml(state.status === 'attacker_won' ? 'Взломщик победил.' : 'Защитник победил.')}</div>`
                : '';

        app.innerHTML = `
            <section class="panel">
                <div class="match-topline">
                    <div><p class="room-label">${statusLabel.toUpperCase()}</p><div class="room-code">${escapeHtml(state.room_code)}</div></div>
                    <span class="role-chip">${roleName}</span>
                </div>
                <div class="match-grid">
                    <section class="match-main">
                        <div class="state-banner" data-status="${escapeHtml(state.status)}" role="status" aria-live="polite">${escapeHtml(state.message)}</div>
                        <p id="formError" class="error-message" role="status" aria-live="polite"></p>
                        <div class="attempt-meter" aria-label="Осталось попыток">${pips}<span class="attempt-label">${state.attempts_left} / ${state.attempts_total} попыток</span></div>
                        <div class="action-area">${actionArea}</div>
                        <section class="history"><h3>Журнал атак</h3>${history}</section>
                    </section>
                    <aside class="match-aside">
                        ${sharePanel}
                        ${secretPanel}
                        ${resultPanel}
                    </aside>
                </div>
            </section>`;
    }

    async function loadRoom(roomCode) {
        try {
            const response = await api({ action: 'state', room_code: roomCode });
            connect(response.state, true);
        } catch (_) {
            try { localStorage.removeItem('hacker-game-duel-room'); } catch (_) {}
        }
    }

    app.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!form.matches('form')) return;
        event.preventDefault();
        if (requestBusy) return;
        requestBusy = true;
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;
        showFormError('');

        try {
            if (form.id === 'createForm') {
                const response = await api({
                    action: 'create',
                    secret: form.elements.secret.value,
                    attempts: Number(form.elements.attempts.value)
                });
                connect(response.state, true);
            } else if (form.id === 'joinForm') {
                const response = await api({ action: 'join', room_code: form.elements.room_code.value.trim() });
                connect(response.state, true);
            } else if (form.id === 'guessForm') {
                const response = await api({ action: 'guess', room_code: currentRoom, guess: form.elements.guess.value });
                connect(response.state, true);
            } else if (form.id === 'rekeyForm') {
                const response = await api({ action: 'rekey', room_code: currentRoom, secret: form.elements.secret.value });
                connect(response.state, true);
            }
        } catch (error) {
            showFormError(error.message);
            if (submitButton) submitButton.disabled = false;
        } finally {
            requestBusy = false;
        }
    });

    app.addEventListener('click', async (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        if (button.dataset.action === 'leave') {
            if (pollTimer) clearTimeout(pollTimer);
            currentRoom = '';
            lastVersion = -1;
            try {
                localStorage.removeItem('hacker-game-duel-room');
                localStorage.removeItem('hacker-game-duel-invite');
            } catch (_) {}
            location.reload();
        }
        if (button.dataset.action === 'copy') {
            const input = document.getElementById('inviteInput');
            if (!input) return;
            input.select();
            try {
                await navigator.clipboard.writeText(input.value);
                button.textContent = 'Скопировано';
            } catch (_) {
                button.textContent = 'Ссылка выделена';
            }
        }
    });

    const queryRoom = new URLSearchParams(location.search).get('room');
    if (queryRoom) document.getElementById('roomCodeInput').value = queryRoom.toUpperCase();
    let savedRoom = '';
    try { savedRoom = localStorage.getItem('hacker-game-duel-room') || ''; } catch (_) {}
    if (savedRoom) loadRoom(savedRoom);
})();
</script>
</body>
</html>
