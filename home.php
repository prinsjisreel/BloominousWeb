<?php
/**
 * BLOOMINOUS - Site Root Entry Point
 *
 * Apache serves this file (via DirectoryIndex in .htaccess) whenever someone
 * opens the bare root URL. It never shows any HTML itself; it only decides
 * where to send the visitor:
 *   - Already logged in  -> index.php (which routes by role: admin/staff to
 *                           admin.php, delivery to delivery_status.php,
 *                           customers to templates/shop.php)
 *   - Not logged in      -> templates/landing_page.php
 *
 * index.php is untouched and remains the login page, so every existing
 * header("Location: index.php") across the project still lands on login.
 */

// Only peek at the session if the browser actually sent a session cookie.
// A first-time visitor has no cookie, so we skip session_start() entirely
// instead of creating an empty session for every anonymous guest.
if (isset($_COOKIE[session_name()])) {

    // Mirror index.php's 30-day session lifetime BEFORE starting the session.
    // Without this, PHP's default cleanup timer (24 minutes) could treat
    // active 30-day sessions as expired and delete them.
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));

    session_start();

    // Same "am I logged in?" check index.php uses: admins/staff have
    // admin_id, customers have user_id (set by includes/set_session.php).
    $isLoggedIn = isset($_SESSION['admin_id']) || isset($_SESSION['user_id']);

    // We only needed to READ the session, so release it immediately.
    session_write_close();

    if ($isLoggedIn) {
        // Reuse index.php's existing role-based routing instead of copying it.
        header("Location: index.php");
        exit();
    }
}

// Default path: guests (and expired sessions) see the public landing page.
header("Location: templates/landing_page.php");
exit();