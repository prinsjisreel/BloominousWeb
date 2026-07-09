<?php
session_start();
session_unset();
session_destroy();

// Redirect sa landing page na nasa loob ng templates folder
header("Location: templates/landing_page.php");
exit();
?>