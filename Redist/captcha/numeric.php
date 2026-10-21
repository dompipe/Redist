<?php
declare(strict_types=1);

/**
 * Redist numeric discipline challenge.
 *
 * Routes:
 *   ?mode=page  HTML keypad challenge
 *   ?mode=frame&frame=0..7  PHP-GD PNG frame; same session answer in every frame
 *
 * Deploy behind the Redist policy gate. This file does not itself decide which
 * visitors must be challenged. Do not expose session data or answers to JS.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const REDIST_CAPTCHA_ALPHABET = '02345689'; // deliberately excludes 1 and 7

function redistCaptchaRounds(): int {
    return max(1, min(10, (int)(getenv('REDIST_NUMERIC_CAPTCHA_ROUNDS') ?: 3)));
}
function redistCaptchaNewCode(): string {
    $alphabet = REDIST_CAPTCHA_ALPHABET;
    $code = '';
    for ($i = 0; $i < 4; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}
function redistCaptchaEnsure(): void {
    if (!isset($_SESSION['redist_numeric_captcha']) || !is_array($_SESSION['redist_numeric_captcha'])) {
        $_SESSION['redist_numeric_captcha'] = [
            'answer' => redistCaptchaNewCode(),
            'expires' => time() + 300,
            'round' => 1,
            'attempts' => 0,
            'passed' => false,
            'nonce' => bin2hex(random_bytes(16)),
        ];
    }
}
function redistCaptchaFreshRound(): void {
    $_SESSION['redist_numeric_captcha'] = [
        'answer' => redistCaptchaNewCode(),
        'expires' => time() + 300,
        'round' => (int)($_SESSION['redist_numeric_captcha']['round'] ?? 1) + 1,
        'attempts' => 0,
        'passed' => false,
        'nonce' => bin2hex(random_bytes(16)),
    ];
}
function redistCaptchaImage(int $frame): never {
    if (!extension_loaded('gd')) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Redist numeric CAPTCHA requires PHP GD.');
    }
    redistCaptchaEnsure();
    $state = $_SESSION['redist_numeric_captcha'];
    if ((int)$state['expires'] < time() || !empty($state['passed'])) {
        redistCaptchaFreshRound();
        $state = $_SESSION['redist_numeric_captcha'];
    }
    $frame = max(0, min(7, $frame));
    $w = 340; $h = 104;
    $im = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($im, 244, 244, 238);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);
    // Low-contrast background noise and short mirage-like wave strokes.
    for ($i = 0; $i < 520; $i++) {
        $c = imagecolorallocate($im, random_int(175, 224), random_int(175, 224), random_int(175, 224));
        imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $c);
    }
    for ($i = 0; $i < 9; $i++) {
        $c = imagecolorallocatealpha($im, 110, 115, 112, 65);
        $y = random_int(8, $h - 8) + (int)(sin(($i + $frame) * 0.8) * 3);
        imageline($im, 0, $y, $w, $y + random_int(-3, 3), $c);
    }
    $digits = str_split((string)$state['answer']);
    $x = 22;
    foreach ($digits as $i => $digit) {
        // Each digit independently alternates red/black between frames.
        $red = (($frame + $i) % 2) === 0;
        $color = $red ? imagecolorallocate($im, 170, 20, 28) : imagecolorallocate($im, 18, 20, 22);
        $font = 5;
        $dx = (int)(sin(($frame + $i * 2) * 0.9) * 3);
        $dy = (int)(cos(($frame + $i) * 0.7) * 3);
        // Pipe separator is intentional and part of the visible numeric strip.
        imagestring($im, $font, $x + $dx, 35 + $dy, $digit, $color);
        $x += 54;
        if ($i < count($digits) - 1) {
            $pipe = imagecolorallocate($im, 80, 80, 80);
            imagestring($im, 5, $x - 7, 35, '|', $pipe);
        }
    }
    // Frame-specific shimmer bands; answer stays fixed for the full challenge.
    for ($i = 0; $i < 5; $i++) {
        $y = 18 + (($i * 17 + $frame * 5) % 70);
        $c = imagecolorallocatealpha($im, 255, 255, 255, 72);
        imageline($im, 8, $y, $w - 8, $y + (int)sin($frame + $i) * 2, $c);
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    imagepng($im);
    imagedestroy($im);
    exit;
}

$mode = (string)($_GET['mode'] ?? 'page');
if ($mode === 'frame') redistCaptchaImage((int)($_GET['frame'] ?? 0));
if ($mode !== 'page') { http_response_code(404); exit; }

redistCaptchaEnsure();
$state = $_SESSION['redist_numeric_captcha'];
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedNonce = (string)($_POST['nonce'] ?? '');
    $answer = preg_replace('/\D+/', '', (string)($_POST['answer'] ?? ''));
    $state = $_SESSION['redist_numeric_captcha'];
    if (!hash_equals((string)$state['nonce'], $submittedNonce)) {
        $message = 'Challenge refreshed. Please try the current code.';
    } elseif ((int)$state['expires'] < time()) {
        redistCaptchaFreshRound();
        $message = 'That challenge expired. A fresh one is ready.';
    } else {
        $_SESSION['redist_numeric_captcha']['attempts'] = (int)$state['attempts'] + 1;
        if ((int)$_SESSION['redist_numeric_captcha']['attempts'] > 8) {
            unset($_SESSION['redist_numeric_captcha']);
            http_response_code(429);
            $message = 'Too many attempts. Restart the challenge after a short pause.';
            redistCaptchaEnsure();
        } elseif (hash_equals((string)$state['answer'], (string)$answer)) {
            $round = (int)$state['round'];
            if ($round >= redistCaptchaRounds()) {
                $_SESSION['redist_numeric_captcha']['passed'] = true;
                $_SESSION['redist_numeric_captcha']['passed_at'] = time();
                $message = 'Challenge complete. Redist may now grant normal queue priority.';
            } else {
                redistCaptchaFreshRound();
                $message = 'Correct. Round ' . $round . ' of ' . redistCaptchaRounds() . ' complete; enter the next code.';
            }
        } else {
            // Wrong answers do not rotate the code immediately; limit guessing.
            $message = 'That code did not match. Try again.';
        }
    }
}
$state = $_SESSION['redist_numeric_captcha'];
$done = !empty($state['passed']) && (time() - (int)($state['passed_at'] ?? 0) <= 1800);
$round = (int)$state['round'];
$nonce = htmlspecialchars((string)$state['nonce'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Redist verification</title>
<style>
:root{color-scheme:light}body{margin:0;padding:1rem;background:#f4f5f7;color:#17191d;font:16px/1.45 system-ui,sans-serif}
main{max-width:390px;margin:1rem auto;padding:1rem;background:white;border:1px solid #d5d8dd;border-radius:12px}
h1{font-size:1.25rem;margin-top:0}.strip{display:block;width:100%;height:116px;border:0;background:#f4f4ee}
label{display:block;margin:.8rem 0 .3rem;font-weight:650}input{width:100%;box-sizing:border-box;font-size:1.3rem;padding:.65rem;border:1px solid #8c929b;border-radius:7px}
.pad{display:grid;grid-template-columns:repeat(3,1fr);gap:.45rem;margin-top:.65rem}.pad button,button[type=submit]{min-height:46px;border:1px solid #aab0b8;border-radius:7px;background:#f0f2f4;color:#17191d;font-size:1.1rem}
button[type=submit]{width:100%;margin-top:.7rem;background:#1c2735;color:white;border-color:#1c2735}.message{padding:.65rem;background:#eef2f6;border-radius:6px}.hint{font-size:.9rem;color:#424852}
</style>
</head>
<body><main>
<h1>Verify to continue</h1>
<p>Read the moving digits from left to right. The vertical bars separate digits.</p>
<iframe class="strip" title="Animated numeric challenge" src="?mode=frame&amp;frame=0" id="captcha-frame"></iframe>
<?php if ($message !== ''): ?><p class="message" role="status"><?=htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')?></p><?php endif; ?>
<?php if ($done): ?>
<p class="message">Verification passed for this session. The Redist gate must check the server-side session flag before allowing normal priority.</p>
<?php else: ?>
<p class="hint">Round <?=$round?> of <?=redistCaptchaRounds()?>. Codes expire after five minutes.</p>
<form method="post" action="?mode=page" autocomplete="off">
<input type="hidden" name="nonce" value="<?=$nonce?>">
<label for="answer">Numeric code</label>
<input id="answer" name="answer" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" required autocomplete="off">
<div class="pad" aria-label="Numeric keypad">
<?php foreach ([2,3,4,5,6,8,9,0] as $n): ?><button type="button" data-digit="<?=$n?>"><?=$n?></button><?php endforeach; ?>
<button type="button" data-clear="1">Clear</button>
</div>
<button type="submit">Verify code</button>
</form>
<script>
(() => {
 const frame = document.getElementById('captcha-frame');
 const input = document.getElementById('answer');
 let n = 0;
 // Eight actual GD-rendered frames; the answer remains stable across the animation.
 setInterval(() => { n = (n + 1) % 8; frame.src = '?mode=frame&frame=' + n + '&t=' + Date.now(); }, 180);
 document.querySelectorAll('[data-digit]').forEach(b => b.addEventListener('click', () => {
   if (input.value.length < 4) input.value += b.dataset.digit;
   input.focus();
 }));
 document.querySelector('[data-clear]').addEventListener('click', () => { input.value = ''; input.focus(); });
 document.querySelector('form').addEventListener('submit', () => { input.value = input.value.replace(/\D/g, '').slice(0,4); });
})();
</script>
<?php endif; ?>
</main></body></html>
