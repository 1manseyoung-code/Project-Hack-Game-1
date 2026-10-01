<?php
if (isset($_GET["duel"])) {
    require __DIR__ . DIRECTORY_SEPARATOR . "duel.php";
    exit;
}

session_start();

$modes = [
    "easy" => ["label" => "Лёгкий", "digits" => 3, "attempts" => 10],
    "normal" => ["label" => "Обычный", "digits" => 4, "attempts" => 7],
    "hard" => ["label" => "Сложный", "digits" => 5, "attempts" => 6],
];

$_SESSION["best"] = $_SESSION["best"] ?? [];
$_SESSION["comparison"] = $_SESSION["comparison"] ?? "";
$modeKey = isset($_SESSION["mode"], $modes[$_SESSION["mode"]]) ? $_SESSION["mode"] : "normal";

if (isset($_POST["mode"]) && is_string($_POST["mode"]) && isset($modes[$_POST["mode"]])) {
    $modeKey = $_POST["mode"];
}

$_SESSION["mode"] = $modeKey;
$settings = $modes[$modeKey];
$digitsWord = $settings["digits"] === 5 ? "цифр" : "цифры";
$_SESSION["credits"] = $_SESSION["credits"] ?? 0;
$_SESSION["round_upgrades"] = $_SESSION["round_upgrades"] ?? ["scans" => 0, "extra_attempts" => 0];
$_SESSION["revealed"] = $_SESSION["revealed"] ?? [];
$_SESSION["shop_notice"] = $_SESSION["shop_notice"] ?? "";

$shopItems = [
    "scan" => ["label" => "Сканер кода", "price" => 250],
    "attempt" => ["label" => "Доп. попытка", "price" => 180],
];

if (
    !isset($_SESSION["code"])
    || strlen($_SESSION["code"]) !== $settings["digits"]
    || isset($_POST["new_game"])
    || isset($_POST["change_mode"])
) {
    $_SESSION["code"] = substr(str_shuffle("0123456789"), 0, $settings["digits"]);
    $_SESSION["attempts"] = $settings["attempts"];
    $_SESSION["score"] = 0;
    $_SESSION["history"] = [];
    $_SESSION["message"] = "🔐 Взломай сейф!";
    $_SESSION["game_over"] = false;
    $_SESSION["comparison"] = "";
    $_SESSION["revealed"] = [];
    $_SESSION["round_upgrades"] = ["scans" => 0, "extra_attempts" => 0];
    $_SESSION["shop_notice"] = "";
}

$code = $_SESSION["code"];
$message = $_SESSION["message"];

// Проверяем попытку
if (isset($_POST["guess"]) && !$_SESSION["game_over"]) {

    $guess = is_string($_POST["guess"]) ? trim($_POST["guess"]) : "";

    if (!preg_match("/^[0-9]{" . $settings["digits"] . "}$/", $guess)) {
        $_SESSION["message"] = "⚠️ Введи код из " . $settings["digits"] . " цифр!";
    } else {
        $_SESSION["attempts"]--;

        $codeDigits = str_split($code);
        $guessDigits = str_split($guess);
        $remainingDigits = array_count_values($codeDigits);
        $feedback = array_fill(0, $settings["digits"], "🔴");

        for ($position = 0; $position < $settings["digits"]; $position++) {
            if ($guessDigits[$position] === $codeDigits[$position]) {
                $feedback[$position] = "🟢";
                $remainingDigits[$guessDigits[$position]]--;
            }
        }

        for ($position = 0; $position < $settings["digits"]; $position++) {
            if ($feedback[$position] === "🟢") {
                continue;
            }
            if (($remainingDigits[$guessDigits[$position]] ?? 0) > 0) {
                $feedback[$position] = "🟡";
                $remainingDigits[$guessDigits[$position]]--;
            }
        }

        $result = implode("", $feedback);

        if ($guess === $code) {
            $_SESSION["score"] = $_SESSION["attempts"] * 100 + $settings["digits"] * 100;
            $_SESSION["credits"] += $_SESSION["score"];
            $previousBest = $_SESSION["best"][$modeKey] ?? null;

            if ($previousBest === null || $_SESSION["score"] > $previousBest) {
                $_SESSION["best"][$modeKey] = $_SESSION["score"];
                $_SESSION["comparison"] = $previousBest === null
                    ? "Первый рекорд в режиме «" . $settings["label"] . "»!"
                    : "Новый рекорд! Предыдущий результат: " . $previousBest . " очков.";
            } elseif ($_SESSION["score"] === $previousBest) {
                $_SESSION["comparison"] = "Ты повторил личный рекорд: " . $previousBest . " очков.";
            } else {
                $_SESSION["comparison"] = "Твой результат: " . $_SESSION["score"] . " очков. До рекорда не хватило " . ($previousBest - $_SESSION["score"]) . ".";
            }

            $_SESSION["message"] = "🎉 СЕЙФ ВЗЛОМАН! Код: " . $code;
            $_SESSION["game_over"] = true;

        } else {

            $_SESSION["history"][] = [
                "guess" => $guess,
                "result" => $result
            ];

            if ($_SESSION["attempts"] <= 0) {
                $bestScore = $_SESSION["best"][$modeKey] ?? null;
                $_SESSION["score"] = 0;
                $_SESSION["comparison"] = $bestScore === null
                    ? "Пока нет рекорда. Победи, чтобы он появился."
                    : "Рекорд режима: " . $bestScore . " очков. Результат этой игры: 0.";
                $_SESSION["message"] =
                    "💀 Попытки закончились! Код был: " . $code;
                $_SESSION["game_over"] = true;
            } else {

                $_SESSION["message"] =
                    "Попробуй ещё! Осталось попыток: " .
                    $_SESSION["attempts"];
            }
        }
    }
}

if (isset($_POST["buy_item"]) && is_string($_POST["buy_item"])) {
    $itemKey = $_POST["buy_item"];

    if (!isset($shopItems[$itemKey])) {
        $_SESSION["shop_notice"] = "Такого улучшения нет в магазине.";
    } elseif ($_SESSION["game_over"]) {
        $_SESSION["shop_notice"] = "Покупки доступны только во время игры.";
    } elseif ($_SESSION["credits"] < $shopItems[$itemKey]["price"]) {
        $_SESSION["shop_notice"] = "Недостаточно очков для покупки.";
    } elseif (
        ($itemKey === "scan" && $_SESSION["round_upgrades"]["scans"] >= 2)
        || ($itemKey === "attempt" && $_SESSION["round_upgrades"]["extra_attempts"] >= 2)
    ) {
        $_SESSION["shop_notice"] = "Лимит этого улучшения на раунд достигнут.";
    } else {
        $_SESSION["credits"] -= $shopItems[$itemKey]["price"];

        if ($itemKey === "scan") {
            $revealedPositions = array_map("intval", array_keys($_SESSION["revealed"]));
            $availablePositions = array_values(array_diff(range(0, $settings["digits"] - 1), $revealedPositions));
            $position = $availablePositions[array_rand($availablePositions)];
            $_SESSION["revealed"][$position] = $code[$position];
            $_SESSION["round_upgrades"]["scans"]++;
            $_SESSION["shop_notice"] = "Сканер открыл позицию " . ($position + 1) . ".";
        } else {
            $_SESSION["attempts"]++;
            $_SESSION["round_upgrades"]["extra_attempts"]++;
            $_SESSION["shop_notice"] = "Добавлена одна попытка.";
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$message = $_SESSION["message"];
$bestScore = $_SESSION["best"][$modeKey] ?? null;
$canBuyScan = !$_SESSION["game_over"]
    && $_SESSION["credits"] >= $shopItems["scan"]["price"]
    && $_SESSION["round_upgrades"]["scans"] < 2;
$canBuyAttempt = !$_SESSION["game_over"]
    && $_SESSION["credits"] >= $shopItems["attempt"]["price"]
    && $_SESSION["round_upgrades"]["extra_attempts"] < 2;
?>

<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Хакерский сейф</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;

            display: flex;
            justify-content: center;
            align-items: center;

            font-family: Arial, sans-serif;

            background:
                radial-gradient(circle at top, #123c35, #061412 60%, #020706);

            color: #00ff9d;
        }

        .game {
            width: 430px;
            max-width: 95%;

            padding: 30px;

            border: 2px solid #00ff9d;
            border-radius: 20px;

            background: rgba(0, 20, 15, 0.95);

            box-shadow:
                0 0 20px #00ff9d,
                inset 0 0 30px rgba(0, 255, 157, 0.1);
        }

        h1 {
            text-align: center;

            margin-top: 0;

            font-size: 30px;

            text-shadow:
                0 0 10px #00ff9d,
                0 0 20px #00ff9d;
        }

        .subtitle {
            text-align: center;
            color: #8affcf;
            margin-bottom: 25px;
        }

        .safe {
            width: 190px;
            height: 190px;

            margin: 20px auto;

            border: 5px solid #00ff9d;
            border-radius: 50%;

            display: flex;
            justify-content: center;
            align-items: center;

            font-size: 60px;

            background: #03120e;

            box-shadow:
                0 0 20px #00ff9d,
                inset 0 0 30px rgba(0, 255, 157, 0.2);
        }

        .message {
            min-height: 50px;

            padding: 15px;

            margin: 20px 0;

            text-align: center;

            border-radius: 10px;

            background: #06251c;

            color: #ffffff;

            font-size: 17px;
        }

        .stats {
            display: flex;
            justify-content: space-between;

            margin-bottom: 20px;

            padding: 10px;

            border-radius: 10px;

            background: #031a13;
        }

        .stats span {
            color: #00ff9d;
            font-weight: bold;
        }

        input {
            width: 100%;

            padding: 15px;

            border: 2px solid #00ff9d;
            border-radius: 10px;

            background: #020b08;

            color: #00ff9d;

            text-align: center;

            font-size: 28px;
            letter-spacing: 10px;

            outline: none;
        }

        input:focus {
            box-shadow: 0 0 15px #00ff9d;
        }

        button {
            width: 100%;

            margin-top: 12px;

            padding: 14px;

            border: none;
            border-radius: 10px;

            background: #00ff9d;

            color: #00150e;

            font-size: 17px;
            font-weight: bold;

            cursor: pointer;

            transition: 0.2s;
        }

        button:hover {
            transform: scale(1.03);

            box-shadow: 0 0 20px #00ff9d;
        }

        .new-game {
            background: transparent;

            border: 2px solid #00ff9d;

            color: #00ff9d;
        }

        .rules {
            margin-top: 25px;

            padding: 15px;

            border-radius: 10px;

            background: #031a13;

            color: #b7ffe5;

            font-size: 14px;

            line-height: 1.6;
        }

        .history {
            margin-top: 20px;
        }

        .attempt {
            display: flex;
            justify-content: space-between;

            padding: 10px;

            margin-top: 7px;

            border-radius: 8px;

            background: #06251c;

            color: white;
        }

        .result {
            font-size: 20px;
            letter-spacing: 3px;
        }

        body {
            padding: 24px 0;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            background:
                radial-gradient(ellipse at 50% 0%, rgba(0, 255, 157, 0.12), transparent 48%),
                #07100e;
            color: #d8fff0;
        }

        .game {
            width: calc(100% - 32px);
            max-width: 460px;
            padding: 28px;
            border: 1px solid rgba(0, 255, 157, 0.5);
            border-radius: 16px;
            background: rgba(8, 23, 19, 0.98);
            box-shadow: 0 18px 60px rgba(0, 0, 0, 0.4), 0 0 32px rgba(0, 255, 157, 0.08);
        }

        h1 {
            font-size: 26px;
            line-height: 1.25;
            text-shadow: 0 0 18px rgba(0, 255, 157, 0.35);
        }

        .subtitle {
            color: #a5c9bb;
        }

        .network-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 12px;
            padding: 10px 14px;
            border: 1px solid rgba(116, 216, 220, 0.5);
            border-radius: 8px;
            background: #0b211d;
            color: #82e6f3;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
            transition: background-color 0.2s, box-shadow 0.2s;
        }

        .network-link:hover {
            background: #12352f;
            box-shadow: 0 0 16px rgba(116, 216, 220, 0.16);
        }

        .safe {
            width: 148px;
            height: 148px;
            border-width: 3px;
            border-color: #44e8ad;
            background: #0a1713;
            box-shadow: 0 0 28px rgba(0, 255, 157, 0.12), inset 0 0 24px rgba(0, 255, 157, 0.08);
        }

        .stats {
            gap: 12px;
            padding: 12px 14px;
            color: #a5c9bb;
        }

        .stats > div {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .stats span {
            color: #65efb6;
            font-variant-numeric: tabular-nums;
        }

        .message {
            display: grid;
            min-height: 54px;
            place-items: center;
            border: 1px solid rgba(125, 255, 203, 0.12);
            background: #0b211a;
            line-height: 1.45;
        }

        .input-label {
            display: block;
            margin: 0 0 8px;
            color: #a5c9bb;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 1px;
            text-align: center;
        }

        input {
            padding: 12px;
            border-color: #387c61;
            border-radius: 8px;
            background: #050d0b;
            color: #74ffc3;
            font-family: Consolas, monospace;
            font-size: 30px;
            letter-spacing: 0.45em;
            padding-left: 0.45em;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input:focus {
            border-color: #65efb6;
            box-shadow: 0 0 0 3px rgba(101, 239, 182, 0.14);
        }

        button {
            min-height: 48px;
            border-radius: 8px;
            background: #65efb6;
            transition: background-color 0.2s, box-shadow 0.2s;
        }

        button:hover {
            transform: none;
            background: #8affcf;
            box-shadow: 0 0 18px rgba(0, 255, 157, 0.2);
        }

        button:focus-visible {
            outline: 3px solid #ffffff;
            outline-offset: 3px;
        }

        .new-game {
            background: transparent;
            color: #74ffc3;
        }

        .rules {
            border: 1px solid rgba(125, 255, 203, 0.08);
            background: #091711;
            color: #b5cfc3;
        }

        .attempt {
            align-items: center;
            border: 1px solid rgba(125, 255, 203, 0.08);
            background: #0b211a;
        }

        .attempt > span:first-child {
            font-family: Consolas, monospace;
            font-size: 18px;
            letter-spacing: 0.2em;
        }

        .footer {
            color: #719889;
        }

        @media (max-width: 480px) {
            body {
                align-items: flex-start;
                padding: 16px 0;
            }

            .game {
                padding: 20px;
            }

            .safe {
                width: 124px;
                height: 124px;
                font-size: 48px;
            }

            .stats {
                font-size: 14px;
            }
        }

            .mode-menu,
            .record-panel {
                margin: 18px 0;
                padding: 14px;
                border: 1px solid rgba(125, 255, 203, 0.12);
                border-radius: 8px;
                background: #091711;
            }

            .mode-menu label,
            .record-title {
                display: block;
                margin-bottom: 8px;
                color: #a5c9bb;
                font-size: 12px;
                font-weight: bold;
                letter-spacing: 1px;
            }

            .mode-controls {
                display: flex;
                gap: 8px;
            }

            select {
                min-width: 0;
                flex: 1;
                padding: 10px 12px;
                border: 1px solid #387c61;
                border-radius: 8px;
                background: #050d0b;
                color: #d8fff0;
                font: inherit;
            }

            .mode-controls button {
                width: auto;
                min-height: 44px;
                margin: 0;
                padding: 10px 14px;
                font-size: 14px;
            }

            .mode-hint {
                margin-top: 8px;
                color: #8eaa9e;
                font-size: 13px;
            }

            .record-topline {
                display: flex;
                justify-content: space-between;
                align-items: baseline;
                gap: 12px;
            }

            .record-topline strong {
                color: #74ffc3;
                font-size: 20px;
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
            }

            .comparison {
                margin: 10px 0 0;
                color: #d8fff0;
                font-size: 14px;
                line-height: 1.45;
            }

            .mode-records {
                display: grid;
                gap: 7px;
                margin: 14px 0 0;
                padding: 0;
                list-style: none;
            }

            .mode-records li {
                display: flex;
                justify-content: space-between;
                gap: 12px;
                color: #a5c9bb;
                font-size: 13px;
            }

            .mode-records li.current {
                color: #74ffc3;
            }

            .mode-records strong {
                color: #d8fff0;
                font-variant-numeric: tabular-nums;
            }

            @media (max-width: 380px) {
                .mode-controls {
                    flex-direction: column;
                }

                .mode-controls button {
                    width: 100%;
                }
            }

        body {
            align-items: flex-start;
        }

        .app-shell {
            display: grid;
            grid-template-areas: "mission game shop";
            grid-template-columns: minmax(190px, 0.78fr) minmax(420px, 1.6fr) minmax(230px, 0.9fr);
            gap: 22px;
            align-items: start;
            width: min(1420px, calc(100% - 40px));
            margin: 0 auto;
        }

        .game {
            grid-area: game;
            width: 100%;
            max-width: none;
            margin: 0;
        }

        .side-panel {
            min-width: 0;
            padding-top: 12px;
        }

        .mission-panel {
            grid-area: mission;
        }

        .shop-panel {
            grid-area: shop;
        }

        .panel-kicker {
            margin: 0 0 7px;
            color: #68d9bd;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .side-panel h2 {
            margin: 0;
            color: #e0fff4;
            font-size: 21px;
            line-height: 1.2;
        }

        .radar-screen {
            margin-top: 18px;
            padding: 14px;
            border: 1px solid rgba(98, 223, 255, 0.24);
            border-radius: 8px;
            background: rgba(6, 23, 28, 0.78);
        }

        .radar {
            position: relative;
            width: min(100%, 180px);
            aspect-ratio: 1;
            margin: 0 auto 14px;
            overflow: hidden;
            border: 1px solid rgba(98, 223, 255, 0.52);
            border-radius: 50%;
            background:
                repeating-radial-gradient(circle, transparent 0 22px, rgba(98, 223, 255, 0.16) 23px 24px),
                linear-gradient(90deg, transparent 49.5%, rgba(98, 223, 255, 0.16) 50%, transparent 50.5%),
                linear-gradient(0deg, transparent 49.5%, rgba(98, 223, 255, 0.16) 50%, transparent 50.5%),
                #07191d;
            box-shadow: inset 0 0 24px rgba(38, 202, 226, 0.12);
        }

        .radar::before {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            background: conic-gradient(from 0deg, rgba(98, 223, 255, 0.38), transparent 58deg 360deg);
            content: "";
            animation: radar-sweep 5s linear infinite;
        }

        .radar::after {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #a5f3ff;
            box-shadow: 0 0 12px #62dfff;
            content: "";
            transform: translate(-50%, -50%);
        }

        @keyframes radar-sweep {
            to { transform: rotate(360deg); }
        }

        .radar-readout {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            color: #9bb9ba;
            font-size: 12px;
        }

        .radar-readout strong {
            color: #79e7f4;
            font-variant-numeric: tabular-nums;
        }

        .code-map {
            display: grid;
            grid-template-columns: repeat(var(--code-length), minmax(0, 1fr));
            gap: 6px;
            margin-top: 14px;
        }

        .code-slot {
            display: grid;
            min-width: 0;
            min-height: 54px;
            align-content: center;
            justify-items: center;
            gap: 3px;
            border: 1px solid rgba(98, 223, 255, 0.18);
            border-radius: 6px;
            background: #08191d;
        }

        .code-slot span {
            color: #6f9296;
            font-size: 9px;
        }

        .code-slot strong {
            color: #76e7f5;
            font-family: Consolas, monospace;
            font-size: 18px;
        }

        .code-slot.found {
            border-color: rgba(98, 223, 255, 0.62);
            background: rgba(17, 63, 71, 0.65);
            box-shadow: inset 0 0 14px rgba(98, 223, 255, 0.08);
        }

        .mission-copy,
        .shop-intro {
            margin: 12px 0 0;
            color: #9db7aa;
            font-size: 13px;
            line-height: 1.55;
        }

        .mission-stats {
            display: grid;
            gap: 8px;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid rgba(125, 255, 203, 0.12);
        }

        .mission-stat {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            color: #8fa99d;
            font-size: 12px;
        }

        .mission-stat strong {
            color: #d8fff0;
            font-variant-numeric: tabular-nums;
        }

        .shop-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
        }

        .wallet {
            color: #b9ff79;
            font-family: Consolas, monospace;
            font-size: 18px;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .wallet small {
            color: #8fa99d;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            font-size: 10px;
        }

        .shop-item {
            padding: 16px 0;
            border-bottom: 1px solid rgba(125, 255, 203, 0.13);
        }

        .shop-item:first-of-type {
            margin-top: 12px;
            border-top: 1px solid rgba(125, 255, 203, 0.13);
        }

        .shop-item h3 {
            margin: 0;
            color: #dcfff3;
            font-size: 15px;
        }

        .shop-item p {
            margin: 6px 0 10px;
            color: #9db7aa;
            font-size: 12px;
            line-height: 1.5;
        }

        .shop-item-meta {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            color: #b9ff79;
            font-size: 12px;
        }

        .shop-item-meta span:last-child {
            color: #91aaa0;
        }

        .shop-item button {
            min-height: 40px;
            margin-top: 10px;
            padding: 9px 12px;
            font-size: 13px;
        }

        .shop-item button:disabled {
            border: 1px solid rgba(125, 255, 203, 0.12);
            background: #15231e;
            color: #72867c;
            cursor: not-allowed;
            box-shadow: none;
        }

        .shop-notice {
            min-height: 38px;
            margin: 12px 0 0;
            color: #b6d4c6;
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width: 1120px) {
            .app-shell {
                grid-template-areas: "game game" "mission shop";
                grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
                max-width: 850px;
            }
        }

        @media (max-width: 640px) {
            .app-shell {
                grid-template-areas: "game" "shop" "mission";
                grid-template-columns: minmax(0, 1fr);
                gap: 20px;
                width: calc(100% - 28px);
            }

            .side-panel {
                padding: 0 4px;
            }

            .radar {
                width: min(100%, 160px);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .radar::before {
                animation: none;
            }
        }

        .footer {
            margin-top: 20px;

            text-align: center;

            color: #4affb6;

            font-size: 12px;
        }

    </style>
</head>

<body>

<div class="app-shell">

<aside class="side-panel mission-panel" aria-label="Сканер сейфа">
    <p class="panel-kicker">ОПЕРАТИВНЫЙ ЦЕНТР</p>
    <h2>Сканер сейфа</h2>

    <div class="radar-screen">
        <div class="radar" aria-hidden="true"></div>
        <div class="radar-readout">
            <span>ОТКРЫТО ПОЗИЦИЙ</span>
            <strong><?= count($_SESSION["revealed"]) ?> / <?= $settings["digits"] ?></strong>
        </div>
        <div class="code-map" style="--code-length: <?= $settings["digits"] ?>;" aria-label="Известные цифры кода">
            <?php for ($position = 0; $position < $settings["digits"]; $position++): ?>
                <?php $isRevealed = array_key_exists($position, $_SESSION["revealed"]); ?>
                <div class="code-slot <?= $isRevealed ? "found" : "" ?>">
                    <span>ПОЗ. <?= $position + 1 ?></span>
                    <strong><?= $isRevealed ? htmlspecialchars($_SESSION["revealed"][$position], ENT_QUOTES, "UTF-8") : "?" ?></strong>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <p class="mission-copy">
        <?= count($_SESSION["revealed"]) > 0
            ? "Сканер поймал фрагмент сигнала. Используй его при следующем вводе."
            : "Сигнал зашифрован. Купи сканер справа, чтобы открыть одну позицию кода." ?>
    </p>

    <div class="mission-stats">
        <div class="mission-stat">
            <span>Попыток в раунде</span>
            <strong><?= $_SESSION["attempts"] ?></strong>
        </div>
        <div class="mission-stat">
            <span>Сканирований</span>
            <strong><?= $_SESSION["round_upgrades"]["scans"] ?> / 2</strong>
        </div>
    </div>
</aside>

<main class="game">

    <h1>🔐 ХАКЕРСКИЙ СЕЙФ</h1>

    <div class="subtitle">
        Взломай секретный код!
        <br>
        <a class="network-link" href="?duel=1">Играть в сетевую дуэль</a>
    </div>

    <form class="mode-menu" method="POST">
        <label for="mode">ВЫБЕРИ РЕЖИМ</label>
        <div class="mode-controls">
            <select id="mode" name="mode">
                <?php foreach ($modes as $key => $mode): ?>
                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, "UTF-8") ?>" <?= $key === $modeKey ? "selected" : "" ?>>
                        <?= htmlspecialchars($mode["label"], ENT_QUOTES, "UTF-8") ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="change_mode" value="1">Применить</button>
        </div>
        <div class="mode-hint">
            <?= $settings["digits"] ?> <?= $digitsWord ?> · <?= $settings["attempts"] ?> попыток
        </div>
    </form>

    <div class="safe">
        🔒
    </div>

    <div class="stats">
        <div>
            Попытки:
            <span>
                <?= $_SESSION["attempts"] ?>
            </span>
        </div>

        <div>
            Очки:
            <span>
                <?= $_SESSION["score"] ?>
            </span>
        </div>
    </div>

    <section class="record-panel" aria-label="Рекорды">
        <div class="record-topline">
            <div>
                <span class="record-title">ЛУЧШИЙ РЕЗУЛЬТАТ · <?= htmlspecialchars($settings["label"], ENT_QUOTES, "UTF-8") ?></span>
            </div>
            <strong><?= $bestScore === null ? "—" : number_format($bestScore, 0, ",", " ") . " очков" ?></strong>
        </div>
        <?php if ($_SESSION["comparison"] !== ""): ?>
            <p class="comparison" role="status" aria-live="polite">
                <?= htmlspecialchars($_SESSION["comparison"], ENT_QUOTES, "UTF-8") ?>
            </p>
        <?php endif; ?>
        <ul class="mode-records">
            <?php foreach ($modes as $key => $mode): ?>
                <li class="<?= $key === $modeKey ? "current" : "" ?>">
                    <span><?= htmlspecialchars($mode["label"], ENT_QUOTES, "UTF-8") ?></span>
                    <strong><?= isset($_SESSION["best"][$key]) ? number_format($_SESSION["best"][$key], 0, ",", " ") . " очков" : "—" ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <div class="message" role="status" aria-live="polite">
        <?= htmlspecialchars($message) ?>
    </div>

    <?php if (!$_SESSION["game_over"]): ?>

        <form method="POST">

            <input type="hidden" name="mode" value="<?= htmlspecialchars($modeKey, ENT_QUOTES, "UTF-8") ?>">
            <label class="input-label" for="guess">ВВЕДИ КОД · <?= $settings["digits"] ?> <?= $digitsWord ?></label>

            <input
                id="guess"
                type="text"
                name="guess"
                inputmode="numeric"
                maxlength="<?= $settings["digits"] ?>"
                minlength="<?= $settings["digits"] ?>"
                pattern="[0-9]{<?= $settings["digits"] ?>}"
                placeholder="<?= str_repeat("0", $settings["digits"]) ?>"
                aria-label="Секретный код, <?= $settings["digits"] ?> <?= $digitsWord ?>"
                autocomplete="off"
                autofocus
                required
            >

            <button type="submit">
                💻 ВЗЛОМАТЬ
            </button>

        </form>

    <?php else: ?>

        <form method="POST">

            <input type="hidden" name="mode" value="<?= htmlspecialchars($modeKey, ENT_QUOTES, "UTF-8") ?>">

            <button
                type="submit"
                name="new_game"
                class="new-game"
            >
                🔄 НОВАЯ ИГРА
            </button>

        </form>

    <?php endif; ?>


    <?php if (!empty($_SESSION["history"])): ?>

        <div class="history">

            <h3>📡 История взлома</h3>

            <?php foreach (array_reverse($_SESSION["history"]) as $attempt): ?>

                <div class="attempt">

                    <span>
                        <?= htmlspecialchars($attempt["guess"]) ?>
                    </span>

                    <span class="result">
                        <?= $attempt["result"] ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <div class="rules">

        <b>📖 Как играть:</b>

        <br>

        🟢 — цифра правильная и стоит на своём месте

        <br>

        🟡 — цифра есть в коде, но стоит не там

        <br>

        🔴 — такой цифры нет в коде

        <br><br>

        Тебе нужно угадать секретный код. Длина кода —
        <b><?= $settings["digits"] ?> <?= $digitsWord ?></b>.

    </div>

    <div class="footer">
        SYSTEM ONLINE • SECURITY LEVEL: HIGH
    </div>

</main>

<aside class="side-panel shop-panel" aria-label="Магазин улучшений">
    <div class="shop-heading">
        <div>
            <p class="panel-kicker">АРСЕНАЛ</p>
            <h2>Магазин</h2>
        </div>
        <div class="wallet" aria-label="Баланс">
            <?= number_format($_SESSION["credits"], 0, ",", " ") ?> <small>PTS</small>
        </div>
    </div>
    <p class="shop-intro">Побеждай, получай очки и покупай помощь для текущего раунда.</p>

    <section class="shop-item">
        <h3>Сканер кода</h3>
        <p>Открывает случайную цифру и её позицию. До двух покупок за раунд.</p>
        <div class="shop-item-meta">
            <span><?= $shopItems["scan"]["price"] ?> очков</span>
            <span><?= $_SESSION["round_upgrades"]["scans"] ?> / 2 использовано</span>
        </div>
        <form method="POST">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($modeKey, ENT_QUOTES, "UTF-8") ?>">
            <button type="submit" name="buy_item" value="scan" <?= $canBuyScan ? "" : "disabled" ?>>Купить сканер</button>
        </form>
    </section>

    <section class="shop-item">
        <h3>Дополнительная попытка</h3>
        <p>Добавляет одну попытку в активный раунд. До двух покупок за раунд.</p>
        <div class="shop-item-meta">
            <span><?= $shopItems["attempt"]["price"] ?> очков</span>
            <span><?= $_SESSION["round_upgrades"]["extra_attempts"] ?> / 2 использовано</span>
        </div>
        <form method="POST">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($modeKey, ENT_QUOTES, "UTF-8") ?>">
            <button type="submit" name="buy_item" value="attempt" <?= $canBuyAttempt ? "" : "disabled" ?>>Купить попытку</button>
        </form>
    </section>

    <p class="shop-notice" role="status" aria-live="polite">
        <?= $_SESSION["shop_notice"] !== ""
            ? htmlspecialchars($_SESSION["shop_notice"], ENT_QUOTES, "UTF-8")
            : "Покупки доступны во время активного раунда." ?>
    </p>
</aside>

</div>

</body>
</html>
