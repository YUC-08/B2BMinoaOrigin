<?php
session_start();
include '../sap_connect.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sap = new SAPConnect();

    // SAP B1 default kullanıcı bilgileri
    $sapUser = 'manager';
    $sapPass = '1234';
    $company = 'CREMMA_CANLI_2209';

    // B2B kullanıcı bilgileri (formdan alınır)
    $userB2B = trim($_POST['username'] ?? ''); 
    $passB2B = trim($_POST['password'] ?? '');

    if (empty($userB2B) || empty($passB2B)) {
        $error = "Kullanıcı adı ve şifre gereklidir!";
    } else {
        // Minoa kullanıcı login işlemi
        $loginResult = $sap->minoaUserLogin($sapUser, $sapPass, $company, $userB2B, $passB2B);

        if ($loginResult !== false && isset($loginResult['success']) && $loginResult['success']) {
            // Başarılı doğrulamada session'a kaydet
            $_SESSION["U_AS_OWNR"] = $loginResult['U_AS_OWNR'] ?? null; // Sektör Kodu
            $_SESSION["WhsCode"] = $loginResult['BranchCode'] ?? null; // Şube Kodu (Branch2.Name - WhsCode)
            $_SESSION["UserName"] = $userB2B; // Kullanıcı adı
            
            // Ek bilgiler (isteğe bağlı)
            if (isset($loginResult['Branch2'])) {
                $_SESSION["Branch2"] = $loginResult['Branch2'];
            }
            
            header("Location: ../index.php");
            exit;
        } else {
            // Hata mesajı
            $error = "Kullanıcı adı veya şifre hatalı!";
            $errorDetails = "";
            $errorCode = "";
            
            if (is_array($loginResult)) {
                $errorCode = $loginResult['error_code'] ?? '';
                $errorStage = $loginResult['stage'] ?? '';
                
                // Hata kodlarına göre kullanıcı dostu mesajlar
                switch ($errorCode) {
                    case 'ERR-001':
                        $error = "SAP sunucusuna bağlanılamadı!";
                        $errorDetails = "Bağlantı hatası oluştu.";
                        break;
                    case 'ERR-002':
                    case 'ERR-003':
                        $error = "SAP oturum açılamadı!";
                        break;
                    case 'ERR-101':
                    case 'ERR-102':
                    case 'ERR-103':
                        $error = "Sistem hatası oluştu!";
                        break;
                    case 'ERR-104':
                        $error = "Kullanıcı adı veya şifre hatalı!";
                        break;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>CREMMAVERSE Giriş</title>
    <link rel="stylesheet" href="../styles.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;height:100vh;background:#f8f9fa;">
    <form method="POST" class="card" style="width:320px;">
        <h2 style="text-align:center;margin-bottom:20px;">Kullanıcı Girişi</h2>
        <?php if (isset($error)): ?>
            <div style="color:red;margin-bottom:10px;text-align:center;padding:10px;background:#ffe6e6;border-radius:4px;">
                <strong><?= htmlspecialchars($error) ?></strong>
                <?php if (isset($errorDetails) && !empty($errorDetails)): ?>
                    <div style="font-size:12px;margin-top:5px;color:#cc0000;">
                        <?= htmlspecialchars($errorDetails) ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($errorCode)): ?>
                    <div style="font-size:11px;margin-top:5px;color:#999;">
                        Hata Kodu: <?= htmlspecialchars($errorCode) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <label>Kullanıcı Adı</label>
        <input type="text" name="username" required class="filter-input" placeholder="Kullanıcı adınızı girin" autocomplete="username" value="<?= htmlspecialchars($userB2B ?? '') ?>">

        <label style="margin-top:15px;">Şifre</label>
        <input type="password" name="password" required class="filter-input" placeholder="Şifrenizi girin" autocomplete="current-password">

        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:15px;" >Giriş Yap</button> 
    </form>
</body>
</html>
