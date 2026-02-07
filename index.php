<?php
/**
 * ✅ SINGLE index.php (Telegram Bot + Website Verify in SAME file)
 *
 * ✅ BIG BOTTOM BUTTONS (Reply Keyboard) like your screenshot:
 *    - User menu buttons: Stats / Withdraw / Referral Link (+ Admin Panel for admin)
 *    - Admin menu buttons: Add Coupon / Stock / Redeems / Change Points / Back
 *
 * ✅ INLINE BUTTONS are still used where REQUIRED:
 *    - Force join + Verify flow (URLs + callback_data)
 *    - Withdraw amount options (callback_data)
 *    - Admin amount pickers (callback_data)
 *
 * ✅ Force-Join channels supported: FORCE_JOIN_1 .. FORCE_JOIN_8
 *    (If you set only 2, it will show only 2)
 *
 * ✅ Withdrawal options: 500 / 1K / 2K / 4K
 * ✅ Each option uses dynamic points from withdraw_points table
 * ✅ Admin can change points per option
 * ✅ Admin can add coupons per amount (500/1K/2K/4K)
 * ✅ Withdraw takes coupon from stock by amount
 *
 * REQUIRED ENV (Render):
 * BOT_TOKEN, ADMIN_ID, BOT_USERNAME (no @)
 * DB_HOST, DB_PORT(5432), DB_NAME(postgres), DB_USER, DB_PASS
 * FORCE_JOIN_1..FORCE_JOIN_8 (optional; set only what you want)
 *
 * REQUIRED DB:
 * - coupons must have: amount INT
 * - withdraw_points table
 * - device_links table
 */

error_reporting(0);
ini_set("display_errors", 0);

define("VERIFY_TOKEN_MINUTES", 10);
define("TG_CONNECT_TIMEOUT", 2);
define("TG_TIMEOUT", 6);

/* ================= ENV ================= */
$BOT_TOKEN    = getenv("BOT_TOKEN");
$ADMIN_ID     = getenv("ADMIN_ID");
$BOT_USERNAME = getenv("BOT_USERNAME"); // without @

$DB_HOST = getenv("DB_HOST");
$DB_PORT = getenv("DB_PORT") ?: "5432";
$DB_NAME = getenv("DB_NAME") ?: "postgres";
$DB_USER = getenv("DB_USER");
$DB_PASS = getenv("DB_PASS");

if (!$BOT_TOKEN) { http_response_code(200); echo "OK"; exit; }
$API = "https://api.telegram.org/bot{$BOT_TOKEN}";

/* ================= DB ================= */
$pdo = null;
try {
  $pdo = new PDO(
    "pgsql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};sslmode=require;connect_timeout=5",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Exception $e) {
  $pdo = null;
}
function dbReady(){ global $pdo; return $pdo instanceof PDO; }

/* ================= URL HELPERS ================= */
function baseUrlThisFile() {
  $proto = "https";
  if (!empty($_SERVER["HTTP_X_FORWARDED_PROTO"])) $proto = $_SERVER["HTTP_X_FORWARDED_PROTO"];
  elseif (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") $proto = "https";
  $host = $_SERVER["HTTP_X_FORWARDED_HOST"] ?? ($_SERVER["HTTP_HOST"] ?? "");
  $path = $_SERVER["SCRIPT_NAME"] ?? "/index.php";
  if (!$host) return "";
  return $proto . "://" . $host . $path;
}

/* ================= TELEGRAM HELPERS ================= */
function tg($method, $data = []) {
  global $API;
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $API . "/" . $method);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, TG_CONNECT_TIMEOUT);
  curl_setopt($ch, CURLOPT_TIMEOUT, TG_TIMEOUT);
  $res = curl_exec($ch);
  curl_close($ch);
  return $res ? json_decode($res, true) : null;
}

function sendMessage($chat_id, $text, $reply_markup = null) {
  $data = [
    "chat_id" => $chat_id,
    "text" => $text,
    "parse_mode" => "HTML",
    "disable_web_page_preview" => true
  ];
  if ($reply_markup) $data["reply_markup"] = json_encode($reply_markup);
  return tg("sendMessage", $data);
}

function answerCallback($callback_id, $text = "", $alert = false) {
  return tg("answerCallbackQuery", [
    "callback_query_id" => $callback_id,
    "text" => $text,
    "show_alert" => $alert ? "true" : "false"
  ]);
}

function isAdmin($tg_id) {
  global $ADMIN_ID;
  return (string)$tg_id === (string)$ADMIN_ID;
}

function normalizeChannel($s) {
  $s = trim((string)$s);
  if ($s === "") return "";
  if ($s[0] !== "@") $s = "@".$s;
  return $s;
}

function botUsername() {
  global $BOT_USERNAME;
  $u = ltrim((string)$BOT_USERNAME, "@");
  if ($u) return $u;
  $me = tg("getMe");
  return $me["result"]["username"] ?? "";
}

/* ================= FORCE JOIN (UP TO 8) ================= */
function channelsList() {
  return array_values(array_filter([
    normalizeChannel(getenv("FORCE_JOIN_1")),
    normalizeChannel(getenv("FORCE_JOIN_2")),
    normalizeChannel(getenv("FORCE_JOIN_3")),
    normalizeChannel(getenv("FORCE_JOIN_4")),
    normalizeChannel(getenv("FORCE_JOIN_5")),
    normalizeChannel(getenv("FORCE_JOIN_6")),
    normalizeChannel(getenv("FORCE_JOIN_7")),
    normalizeChannel(getenv("FORCE_JOIN_8")),
  ]));
}

function joinMarkup() {
  $chs = channelsList();
  $rows = [];
  $i = 1;
  foreach ($chs as $ch) {
    $rows[] = [[
      "text" => "➕ Join $i",
      "url"  => "https://t.me/" . ltrim($ch, "@")
    ]];
    $i++;
  }
  $rows[] = [[ "text" => "✅ Check Verification", "callback_data" => "check_join" ]];
  return ["inline_keyboard" => $rows];
}

function checkMember($user_id, $chat) {
  $r = tg("getChatMember", ["chat_id" => $chat, "user_id" => $user_id]);
  if (!$r || empty($r["ok"])) return false;
  $status = $r["result"]["status"] ?? "";
  return in_array($status, ["member", "administrator", "creator"], true);
}

function allJoined($tg_id) {
  $chs = channelsList();
  foreach ($chs as $ch) {
    if (!$ch) continue;
    if (!checkMember($tg_id, $ch)) return false;
  }
  return true;
}

/* ================= BIG BUTTONS (REPLY KEYBOARD) ================= */
function userReplyKeyboard($isAdmin = false) {
  $kb = [
    "keyboard" => [
      [ ["text"=>"📊 Stats"], ["text"=>"🎉 Withdraw"] ],
      [ ["text"=>"🔗 My Referral Link"] ],
    ],
    "resize_keyboard" => true,
    "is_persistent" => true,
    "one_time_keyboard" => false
  ];
  if ($isAdmin) {
    $kb["keyboard"][] = [ ["text"=>"🛠 Admin Panel"] ];
  }
  return $kb;
}

function adminReplyKeyboard() {
  return [
    "keyboard" => [
      [ ["text"=>"➕ Add Coupon"], ["text"=>"📦 Coupon Stock"] ],
      [ ["text"=>"🗂 Redeems Log"], ["text"=>"⚙ Change Withdraw Points"] ],
      [ ["text"=>"⬅️ Back"] ],
    ],
    "resize_keyboard" => true,
    "is_persistent" => true,
    "one_time_keyboard" => false
  ];
}

/* ================= INLINE MENUS ================= */
function verifyMenuMarkup($verifyUrl) {
  return ["inline_keyboard" => [
    [[ "text" => "✅ Verify Now", "url" => $verifyUrl ]],
    [[ "text" => "✅ Check Verification", "callback_data" => "check_verified" ]]
  ]];
}

/* ================= STATE ================= */
function stateDir() {
  $d = __DIR__ . "/state";
  if (!is_dir($d)) @mkdir($d, 0777, true);
  return $d;
}
function setState($tg_id, $state) { file_put_contents(stateDir()."/{$tg_id}.txt", $state); }
function getState($tg_id) {
  $f = stateDir()."/{$tg_id}.txt";
  return file_exists($f) ? trim((string)file_get_contents($f)) : "";
}
function clearState($tg_id) {
  $f = stateDir()."/{$tg_id}.txt";
  if (file_exists($f)) @unlink($f);
}

/* ================= DB HELPERS ================= */
function getUser($tg_id) {
  global $pdo;
  $st = $pdo->prepare("SELECT * FROM users WHERE tg_id=:tg LIMIT 1");
  $st->execute([":tg" => $tg_id]);
  return $st->fetch();
}

function upsertUser($tg_id, $referred_by = null) {
  global $pdo;
  $u = getUser($tg_id);
  if ($u) return $u;
  $pdo->prepare("INSERT INTO users (tg_id, referred_by) VALUES (:tg, :ref)")
      ->execute([":tg" => $tg_id, ":ref" => $referred_by]);
  return getUser($tg_id);
}

function isVerifiedUser($tg_id) {
  $u = getUser($tg_id);
  return $u && !empty($u["verified"]);
}

function getWithdrawPoints($amount) {
  global $pdo;
  try {
    $st = $pdo->prepare("SELECT points FROM withdraw_points WHERE amount=:a");
    $st->execute([":a" => (int)$amount]);
    $r = $st->fetch();
    return $r ? (int)$r["points"] : 0;
  } catch (Exception $e) { return 0; }
}

function makeVerifyLink($tg_id) {
  global $pdo;
  $token = bin2hex(random_bytes(16));
  $pdo->prepare("UPDATE users
                 SET verify_token=:t,
                     verify_token_expires=NOW() + (:m || ' minutes')::interval
                 WHERE tg_id=:tg")
      ->execute([":t" => $token, ":m" => VERIFY_TOKEN_MINUTES, ":tg" => $tg_id]);

  $base = baseUrlThisFile();
  return $base . "?mode=verify&uid=" . urlencode($tg_id) . "&token=" . urlencode($token);
}

/* ================= WEBSITE VERIFY (GET) ================= */
function htmlVerifyUI($title, $msg, $doUrl) {
  $btn = $doUrl ? '<a class="btn" href="'.htmlspecialchars($doUrl).'">✅ Verify Now</a>' : '';
  return '<!doctype html>
<html><head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>'.htmlspecialchars($title).'</title>
<style>
  body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:#0b1220;font-family:system-ui;color:#fff;}
  .card{width:min(560px,92vw);background:#111827;border-radius:18px;padding:22px;box-shadow:0 20px 60px rgba(0,0,0,.45);}
  .h{font-size:26px;font-weight:800;margin:0 0 10px}
  .p{opacity:.85;line-height:1.4;margin:0 0 16px;font-size:16px}
  .btn{display:block;text-align:center;background:#22c55e;color:#000;padding:14px 16px;border-radius:12px;text-decoration:none;font-weight:800;font-size:18px}
</style>
</head><body><div class="card">
<div class="h">🔐 '.htmlspecialchars($title).'</div>
<div class="p">'.htmlspecialchars($msg).'</div>
'.$btn.'
</div></body></html>';
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  $mode  = $_GET["mode"] ?? "";
  if ($mode !== "verify") { echo "OK"; exit; }

  if (!dbReady()) { echo htmlVerifyUI("DB Error", "Database not connected.", null); exit; }

  $uid   = (int)($_GET["uid"] ?? 0);
  $token = trim($_GET["token"] ?? "");
  $step  = $_GET["step"] ?? "";

  if (!$uid || !$token) { echo htmlVerifyUI("Invalid", "Invalid verification link.", null); exit; }

  if ($step !== "do") {
    $doUrl = baseUrlThisFile()."?mode=verify&uid=".$uid."&token=".urlencode($token)."&step=do";
    echo htmlVerifyUI("Verification", "Tap below to verify.", $doUrl);
    exit;
  }

  // device token cookie
  $cookieName = "device_token";
  if (empty($_COOKIE[$cookieName]) || strlen($_COOKIE[$cookieName]) < 20) {
    $dt = bin2hex(random_bytes(16));
    setcookie($cookieName, $dt, time() + 3600*24*365, "/", "", true, true);
    $_COOKIE[$cookieName] = $dt;
  }
  $deviceToken = $_COOKIE[$cookieName];

  // Ensure user exists
  $pdo->prepare("INSERT INTO users (tg_id) VALUES (:tg) ON CONFLICT (tg_id) DO NOTHING")
      ->execute([":tg" => $uid]);

  // Validate token + expiry
  $st = $pdo->prepare("SELECT verified, verify_token, verify_token_expires FROM users WHERE tg_id=:tg LIMIT 1");
  $st->execute([":tg" => $uid]);
  $u = $st->fetch();

  if (!$u) { echo htmlVerifyUI("Error", "User not found.", null); exit; }

  if (!empty($u["verified"])) {
    header("Location: https://t.me/".botUsername());
    exit;
  }

  if (($u["verify_token"] ?? "") !== $token) {
    echo htmlVerifyUI("Invalid", "This link is invalid. Press Check Verification again.", null);
    exit;
  }

  $exp = $u["verify_token_expires"] ?? "";
  if (!$exp || strtotime($exp) < time()) {
    echo htmlVerifyUI("Expired", "Link expired. Press Check Verification again.", null);
    exit;
  }

  // device lock
  $st = $pdo->prepare("SELECT tg_id FROM device_links WHERE device_token=:dt LIMIT 1");
  $st->execute([":dt" => $deviceToken]);
  $existing = $st->fetch();
  if ($existing && (int)$existing["tg_id"] !== $uid) {
    echo htmlVerifyUI("Blocked", "❌ This device is already linked to another Telegram ID.", null);
    exit;
  }

  // link device
  $pdo->prepare("INSERT INTO device_links (device_token, tg_id) VALUES (:dt,:tg)
                 ON CONFLICT (device_token) DO UPDATE SET tg_id=EXCLUDED.tg_id")
      ->execute([":dt" => $deviceToken, ":tg" => $uid]);

  // mark verified
  $pdo->prepare("UPDATE users
                 SET verified=true, verified_at=NOW(), verify_token=NULL, verify_token_expires=NULL
                 WHERE tg_id=:tg")
      ->execute([":tg" => $uid]);

  header("Location: https://t.me/".botUsername());
  exit;
}

/* ================= WEBHOOK (POST) ================= */
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { http_response_code(200); echo "OK"; exit; }

if (!dbReady()) {
  if (isset($update["message"])) {
    $chat_id = $update["message"]["chat"]["id"];
    sendMessage($chat_id, "⚠️ Database not connected.\nCheck Render ENV DB_HOST/DB_USER/DB_PASS and redeploy.");
  }
  http_response_code(200); echo "OK"; exit;
}

/* ================= MESSAGE HANDLER ================= */
if (isset($update["message"])) {
  $m = $update["message"];
  $chat_id = $m["chat"]["id"];
  $from_id = $m["from"]["id"];
  $text = trim($m["text"] ?? "");

  // create user if missing (no ref here)
  upsertUser($from_id, null);

  // ---- ADMIN STATES (text input) ----
  $state = getState($from_id);

  // Admin: waiting coupon codes for amount
  if (isAdmin($from_id) && preg_match("/^await_coupon_(500|1000|2000|4000)$/", $state, $mm) && $text !== "" && strpos($text, "/") !== 0) {
    $amount = (int)$mm[1];
    $codes = preg_split("/\r\n|\n|\r|,|\s+/", $text);
    $codes = array_values(array_filter(array_map("trim", $codes)));
    $added = 0;

    foreach ($codes as $c) {
      if ($c === "") continue;
      try {
        $pdo->prepare("INSERT INTO coupons (code, amount, added_by) VALUES (:c,:amt,:a)")
            ->execute([":c" => $c, ":amt" => $amount, ":a" => $from_id]);
        $added++;
      } catch (Exception $e) {}
    }

    clearState($from_id);
    sendMessage($chat_id, "✅ Added <b>{$added}</b> coupon(s) for <b>{$amount}</b>.", adminReplyKeyboard());
    http_response_code(200); echo "OK"; exit;
  }

  // Admin: waiting points number for amount
  if (isAdmin($from_id) && preg_match("/^setp_(500|1000|2000|4000)$/", $state, $mm) && $text !== "" && strpos($text, "/") !== 0) {
    $amount = (int)$mm[1];
    $pts = (int)$text;
    if ($pts < 0) $pts = 0;

    $pdo->prepare("INSERT INTO withdraw_points (amount, points)
                   VALUES (:a,:p)
                   ON CONFLICT (amount) DO UPDATE SET points=EXCLUDED.points")
        ->execute([":a"=>$amount, ":p"=>$pts]);

    clearState($from_id);
    sendMessage($chat_id, "✅ Points updated for <b>{$amount}</b> → <b>{$pts}</b> points.", adminReplyKeyboard());
    http_response_code(200); echo "OK"; exit;
  }

  // ---- /start with referral ----
  if (strpos($text, "/start") === 0) {
    $parts = explode(" ", $text, 2);
    $ref = null;
    if (count($parts) === 2 && ctype_digit(trim($parts[1]))) $ref = (int)trim($parts[1]);

    $existing = getUser($from_id);

    // If user is NEW, apply referral once
    if (!$existing) {
      $referred_by = null;
      if ($ref && $ref != $from_id) {
        $refUser = getUser($ref);
        if ($refUser) $referred_by = $ref;
      }
      upsertUser($from_id, $referred_by);
      if ($referred_by) {
        try {
          $pdo->prepare("UPDATE users SET points=points+1, total_referrals=total_referrals+1 WHERE tg_id=:r")
              ->execute([":r" => $referred_by]);
        } catch (Exception $e) {}
      }
    }

    if (isVerifiedUser($from_id)) {
      sendMessage($chat_id, "🏠 <b>Main Menu</b>", userReplyKeyboard(isAdmin($from_id)));
    } else {
      sendMessage($chat_id, "✅ <b>Join all channels</b> then verify.\n\nAfter joining, press <b>Check Verification</b>.", joinMarkup());
    }

    http_response_code(200); echo "OK"; exit;
  }

  // ---- if not verified, always show join buttons ----
  if (!isVerifiedUser($from_id)) {
    sendMessage($chat_id, "✅ Join all channels then verify.\nPress <b>Check Verification</b>.", joinMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // ================= USER REPLY BUTTONS =================
  if ($text === "📊 Stats") {
    $u = getUser($from_id);
    sendMessage(
      $chat_id,
      "📊 <b>Your Stats</b>\n\n⭐ Points: <b>{$u['points']}</b>\n👥 Referrals: <b>{$u['total_referrals']}</b>",
      userReplyKeyboard(isAdmin($from_id))
    );
    http_response_code(200); echo "OK"; exit;
  }

  if ($text === "🔗 My Referral Link") {
    $bot = botUsername();
    $link = $bot ? "https://t.me/{$bot}?start={$from_id}" : "Set BOT_USERNAME in ENV";
    sendMessage($chat_id, "🔗 <b>Your Referral Link</b>\n\n<code>{$link}</code>", userReplyKeyboard(isAdmin($from_id)));
    http_response_code(200); echo "OK"; exit;
  }

  if ($text === "🎉 Withdraw") {
    $p500 = getWithdrawPoints(500);
    $p1k  = getWithdrawPoints(1000);
    $p2k  = getWithdrawPoints(2000);
    $p4k  = getWithdrawPoints(4000);

    sendMessage($chat_id, "🎉 <b>Choose withdraw option</b>", [
      "inline_keyboard" => [
        [[ "text" => "🎉 500 (need {$p500} pts)", "callback_data" => "wd_500" ]],
        [[ "text" => "🎉 1K (need {$p1k} pts)",  "callback_data" => "wd_1000" ]],
        [[ "text" => "🎉 2K (need {$p2k} pts)",  "callback_data" => "wd_2000" ]],
        [[ "text" => "🎉 4K (need {$p4k} pts)",  "callback_data" => "wd_4000" ]],
      ]
    ]);
    http_response_code(200); echo "OK"; exit;
  }

  // ================= ADMIN REPLY BUTTONS =================
  if ($text === "🛠 Admin Panel") {
    if (!isAdmin($from_id)) {
      sendMessage($chat_id, "❌ Not allowed.", userReplyKeyboard(false));
      http_response_code(200); echo "OK"; exit;
    }
    sendMessage($chat_id, "🛠 <b>Admin Panel</b>", adminReplyKeyboard());
    http_response_code(200); echo "OK"; exit;
  }

  if (isAdmin($from_id)) {
    if ($text === "⬅️ Back") {
      sendMessage($chat_id, "🏠 <b>Main Menu</b>", userReplyKeyboard(true));
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "➕ Add Coupon") {
      sendMessage($chat_id, "➕ <b>Select coupon amount</b>", [
        "inline_keyboard" => [
          [[ "text"=>"500",  "callback_data"=>"admin_add_500" ]],
          [[ "text"=>"1K",   "callback_data"=>"admin_add_1000" ]],
          [[ "text"=>"2K",   "callback_data"=>"admin_add_2000" ]],
          [[ "text"=>"4K",   "callback_data"=>"admin_add_4000" ]],
        ]
      ]);
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "📦 Coupon Stock") {
      $rows = $pdo->query("SELECT amount, COUNT(*) c FROM coupons WHERE used=false GROUP BY amount ORDER BY amount")->fetchAll();
      $msg = "📦 <b>Coupon Stock</b>\n\n";
      if (!$rows) $msg .= "No coupons available.";
      else foreach ($rows as $r) $msg .= "🎉 <b>{$r['amount']}</b>: <b>{$r['c']}</b>\n";
      sendMessage($chat_id, $msg, adminReplyKeyboard());
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "🗂 Redeems Log") {
      $rows = $pdo->query("SELECT tg_id, coupon_code, created_at, points_deducted FROM withdrawals ORDER BY id DESC LIMIT 15")->fetchAll();
      $msg = "🗂 <b>Last 15 Redeems</b>\n\n";
      if (!$rows) $msg .= "No redeems yet.";
      else {
        foreach ($rows as $r) {
          $msg .= "👤 <code>{$r['tg_id']}</code>\n🎟 <code>{$r['coupon_code']}</code>\n⭐ <b>{$r['points_deducted']}</b>\n🕒 <code>{$r['created_at']}</code>\n\n";
        }
      }
      sendMessage($chat_id, $msg, adminReplyKeyboard());
      http_response_code(200); echo "OK"; exit;
    }

    if ($text === "⚙ Change Withdraw Points") {
      sendMessage($chat_id, "⚙ <b>Select amount</b>", [
        "inline_keyboard" => [
          [[ "text"=>"500", "callback_data"=>"setp_500" ]],
          [[ "text"=>"1K",  "callback_data"=>"setp_1000" ]],
          [[ "text"=>"2K",  "callback_data"=>"setp_2000" ]],
          [[ "text"=>"4K",  "callback_data"=>"setp_4000" ]],
        ]
      ]);
      http_response_code(200); echo "OK"; exit;
    }
  }

  // default
  sendMessage($chat_id, "🏠 <b>Main Menu</b>", userReplyKeyboard(isAdmin($from_id)));
  http_response_code(200); echo "OK"; exit;
}

/* ================= CALLBACK HANDLER ================= */
if (isset($update["callback_query"])) {
  $cq = $update["callback_query"];
  $data = $cq["data"] ?? "";
  $from_id = $cq["from"]["id"];
  $chat_id = $cq["message"]["chat"]["id"];

  // ACK fast (prevents webhook timeout)
  answerCallback($cq["id"], "…");

  upsertUser($from_id, null);

  // ---- Force join check ----
  if ($data === "check_join") {
    if (allJoined($from_id)) {
      sendMessage($chat_id, "✅ <b>Channel join verified!</b>\nNow verify on website.");

      $url = makeVerifyLink($from_id);
      sendMessage(
        $chat_id,
        "🔐 <b>Verification</b>\nTap below to verify.",
        verifyMenuMarkup($url)
      );
    } else {
      sendMessage($chat_id, "❌ <b>Join check failed.</b>\nJoin all channels then try again.", joinMarkup());
    }
    http_response_code(200); echo "OK"; exit;
  }

  // ---- Check verified ----
  if ($data === "check_verified") {
    if (isVerifiedUser($from_id)) {
      sendMessage($chat_id, "✅ <b>Verified Successfully!</b>", userReplyKeyboard(isAdmin($from_id)));
    } else {
      $url = makeVerifyLink($from_id);
      sendMessage(
        $chat_id,
        "❌ <b>Not verified yet.</b>\n\n1) Tap ✅ Verify Now\n2) Complete verification\n3) Come back and tap ✅ Check Verification",
        verifyMenuMarkup($url)
      );
    }
    http_response_code(200); echo "OK"; exit;
  }

  // block if not verified
  if (!isVerifiedUser($from_id)) {
    sendMessage($chat_id, "🔐 Please verify first.\nJoin channels then press <b>Check Verification</b>.", joinMarkup());
    http_response_code(200); echo "OK"; exit;
  }

  // ---- Withdraw process ----
  if (preg_match("/^wd_(500|1000|2000|4000)$/", $data, $m)) {
    $amount = (int)$m[1];
    $need = getWithdrawPoints($amount);
    $u = getUser($from_id);

    if ($need <= 0) {
      sendMessage($chat_id, "⚠️ Points not set for {$amount}. Ask admin.", userReplyKeyboard(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }

    if ((int)$u["points"] < $need) {
      sendMessage($chat_id, "❌ Not enough points.\nYou have <b>{$u['points']}</b>, need <b>{$need}</b>.", userReplyKeyboard(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }

    try {
      $pdo->beginTransaction();

      // take coupon by amount
      $st = $pdo->prepare("SELECT id, code FROM coupons WHERE used=false AND amount=:amt ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED");
      $st->execute([":amt"=>$amount]);
      $coupon = $st->fetch();

      if (!$coupon) {
        $pdo->rollBack();
        sendMessage($chat_id, "⚠️ Out of stock for <b>{$amount}</b>. Try later.", userReplyKeyboard(isAdmin($from_id)));
        http_response_code(200); echo "OK"; exit;
      }

      $st = $pdo->prepare("UPDATE users SET points = points - :need WHERE tg_id=:tg AND points >= :need");
      $st->execute([":need" => $need, ":tg" => $from_id]);
      if ($st->rowCount() < 1) {
        $pdo->rollBack();
        sendMessage($chat_id, "❌ Not enough points.", userReplyKeyboard(isAdmin($from_id)));
        http_response_code(200); echo "OK"; exit;
      }

      $pdo->prepare("UPDATE coupons SET used=true, used_by=:tg, used_at=NOW() WHERE id=:id")
          ->execute([":tg" => $from_id, ":id" => $coupon["id"]]);

      $pdo->prepare("INSERT INTO withdrawals (tg_id, coupon_code, points_deducted) VALUES (:tg,:c,:d)")
          ->execute([":tg" => $from_id, ":c" => $coupon["code"], ":d" => $need]);

      $pdo->commit();

      sendMessage($chat_id, "🎉 <b>Coupon Redeemed!</b>\n\n<code>{$coupon['code']}</code>", userReplyKeyboard(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;

    } catch (Exception $e) {
      if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
      sendMessage($chat_id, "⚠️ Error. Try again.", userReplyKeyboard(isAdmin($from_id)));
      http_response_code(200); echo "OK"; exit;
    }
  }

  // ---- Admin: pick coupon amount to add ----
  if (preg_match("/^admin_add_(500|1000|2000|4000)$/", $data, $m) && isAdmin($from_id)) {
    $amount = (int)$m[1];
    setState($from_id, "await_coupon_".$amount);
    sendMessage($chat_id, "➕ Send coupon codes for <b>{$amount}</b>\n(Separate by newline / space / comma)", adminReplyKeyboard());
    http_response_code(200); echo "OK"; exit;
  }

  // ---- Admin: pick amount to change points ----
  if (preg_match("/^setp_(500|1000|2000|4000)$/", $data, $m) && isAdmin($from_id)) {
    $amount = (int)$m[1];
    setState($from_id, "setp_".$amount);
    sendMessage($chat_id, "✏️ Send new points for <b>{$amount}</b>:", adminReplyKeyboard());
    http_response_code(200); echo "OK"; exit;
  }

  http_response_code(200); echo "OK"; exit;
}

http_response_code(200);
echo "OK";
