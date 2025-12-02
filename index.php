<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MINOA - Ana Sayfa</title>
    
</head>
<body>
    <div class="navbar-container">
        <?php include 'navbar.php'; ?>
    </div>
    
    <div class="main-content">
        <div class="minoa-logo-container">
            <svg class="minoa-logo-svg" viewBox="0 0 800 200" preserveAspectRatio="xMidYMid meet">
                <text x="50%" y="50%" class="minoa-logo-text">MINOA</text>
            </svg>
        </div>
    </div>

   
</body>
</html>

