<?php
session_start();

// Clear all session data
$_SESSION = [];

// FIXED: session_destroy() alone only wipes the session DATA server-side —
// it never removes the actual cookie from the browser. Without this,
// after clicking Logout the browser keeps holding a cookie pointing at a
// session ID with nothing behind it, until that cookie naturally expires
// (now up to 30 days, per the persistent-login fix). Harmless in
// practice since every page correctly sees an empty $_SESSION either
// way — but explicitly expiring the cookie here makes "logged out" mean
// exactly that, immediately, instead of "empty but still lingering."
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Redirect sa landing page na nasa loob ng templates folder
header("Location: templates/landing_page.php");
exit();
?>