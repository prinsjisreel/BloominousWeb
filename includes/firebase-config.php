<?php
/**
 * BLOOMINOUS - Firebase Hub Configuration
 * Ito ang nagsisilbing tulay ng iyong PHP Web Spoke sa Firebase Hub.
 */

// 1. FRONTEND CONFIGURATION (Para sa JavaScript/Real-time)
// I-include ito sa iyong header.php para magamit ang Firebase sa browser.
$firebaseConfig = [
    "apiKey" => "AIzaSyCnfoiasHySwrtAnx9pB_Xjucxz5JfW3Qw",
    "authDomain" => "ar-flower-shop-app.firebaseapp.com",
    "projectId" => "ar-flower-shop-app",
    "storageBucket" => "ar-flower-shop-app.firebasestorage.app",
    "messagingSenderId" => "627989159362",
    "appId" => "1:627989159362:android:e44aefb6d573e078ae0a5b"
];

// 2. BACKEND CONFIGURATION (Para sa PHP Server-side)
// Kung gagamit ka ng Kreait Firebase PHP SDK (rekomendado para sa Hub and Spoke)
// Siguraduhin na na-download mo ang iyong Service Account JSON mula sa Firebase Console:
// Project Settings > Service Accounts > Generate New Private Key
define('FIREBASE_SERVICE_ACCOUNT_JSON', __DIR__ . '/../serviceAccountKey.json');
define('FIREBASE_DATABASE_URL', 'https://ar-flower-shop-app-default-rtdb.firebaseio.com');

/**
 * Helper function para i-inject ang Firebase JS SDK sa iyong templates.
 * Gamitin ito sa header.php: <?php echo getFirebaseJS(); ?>
 */
function getFirebaseJS() {
    global $firebaseConfig;
    $jsonConfig = json_encode($firebaseConfig);
    
    return "
    <!-- Firebase App (the core Firebase SDK) -->
    <script src='https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js'></script>
    <!-- Add Firebase products that you want to use -->
    <script src='https://www.gstatic.com/firebasejs/9.22.0/firebase-auth-compat.js'></script>
    <script src='https://www.gstatic.com/firebasejs/9.22.0/firebase-firestore-compat.js'></script>

    <script>
        // Initialize Firebase
        const firebaseConfig = $jsonConfig;
        firebase.initializeApp(firebaseConfig);
        
        // Initialize Firestore
        const db = firebase.firestore();
        const auth = firebase.auth();
        
        console.log('BLOOM: Connected to Firebase Hub');
    </script>
    ";
}

/**
 * Halimbawa ng pag-sync ng Inventory mula PHP papuntang Firebase Hub
 */
function syncProductToHub($productId, $data) {
    // Dito papasok ang logic para i-update ang Firestore gamit ang REST API 
    // o ang Admin SDK kung naka-install na ito via Composer.
    // Ito ang magsisiguro na nakikita ng Flutter App ang changes sa Web.
}
?>