<?php
session_start();
include '../sap_connect.php';

// HATA DURUMU JS'YE GİTSİN DİYE EKLEDİK
$status = '';

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
        $status = 'error'; // <-- sadece bu eklendi
    } else {
        // Minoa kullanıcı login işlemi
        $loginResult = $sap->minoaUserLogin($sapUser, $sapPass, $company, $userB2B, $passB2B);

        if ($loginResult !== false && isset($loginResult['success']) && $loginResult['success']) {
            // Başarılı doğrulamada session'a kaydet
            $_SESSION["U_AS_OWNR"] = $loginResult['U_AS_OWNR'] ?? null; // Sektör Kodu
            $_SESSION["WhsCode"] = $loginResult['BranchCode'] ?? null; // Şube Kodu (Branch2.Name - WhsCode)
            $_SESSION["UserName"] = $userB2B; // Kullanıcı adı
            
            // Kullanıcı adı ve soyadı
            $_SESSION["FirstName"] = $loginResult['FirstName'] ?? '';
            $_SESSION["LastName"] = $loginResult['LastName'] ?? '';
            
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
            $status = 'error'; // <-- sadece bu eklendi
            
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
  <meta charset="UTF-8" />
  <title>MINOA Giriş</title>
  <!-- <link rel="stylesheet" href="../styles.css"> -->

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: #0f172a;
    }

    .login-wrapper {
      background: #0b1220;
      border-radius: 24px;
      padding: 32px 40px;
      display: flex;
      gap: 32px;
      align-items: center;
      box-shadow: 0 20px 60px rgba(15, 23, 42, 0.8);
      border: 1px solid rgba(148, 163, 184, 0.25);
      max-width: 720px;
      width: 100%;
    }

    /* HATA KUTUSU */
    .error-box {
      width: 100%;
      margin-bottom: 12px;
      padding: 10px 12px;
      border-radius: 12px;
      background: rgba(248, 113, 113, 0.12);
      color: #fecaca;
      font-size: 0.85rem;
      border: 1px solid rgba(248, 113, 113, 0.4);
    }

    .error-box small {
      display: block;
      margin-top: 4px;
      color: #fca5a5;
      font-size: 0.75rem;
    }

    .error-box code {
      font-size: 0.72rem;
      opacity: 0.75;
    }

    /* KARAKTER (KİTAP) */

    .character {
      width: 180px;
      height: 220px;
      position: relative;
      transition: all 0.3s ease;
    }

    /* Kitap gövdesi */
    .book {
      width: 150px;
      height: 200px;
      border-radius: 18px;
      background: linear-gradient(135deg, #1d4ed8, #3b82f6);
      position: relative;
      margin: 0 auto;
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.45);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: visible;
    }

    /* Kitabın sayfa ve cilt detayları */
    .book::before {
      content: "";
      position: absolute;
      top: 6px;
      right: -6px;
      width: 10px;
      height: 188px;
      border-radius: 0 14px 14px 0;
      background: linear-gradient(180deg, #e5e7eb, #cbd5f5);
      box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.4);
    }

    .book::after {
      content: "";
      position: absolute;
      top: -8px;
      left: 0;
      right: 0;
      margin: 0 auto;
      width: 70%;
      height: 10px;
      border-radius: 12px 12px 0 0;
      background: #0f172a;
      opacity: 0.25;
    }

    .eyes {
      display: flex;
      gap: 26px;
      align-items: center;
      justify-content: center;
      transform: translateY(-18px);
      position: relative;
      z-index: 2;
    }

    .eye {
      width: 36px;
      height: 46px;
      border-radius: 50%;
      background: radial-gradient(circle at 100% 35%, #fff 0%, #fefce8 25%, #9ca3af 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      position: relative;
      box-shadow: 0 0 8px rgba(255, 255, 255, 0.4);
      transition: transform 0.2s ease;
    }

    .pupil {
      width: 14px;
      height: 14px;
      border-radius: 50%;
      background: #020617;
      transition: transform 0.25s ease;
    }

    /* Göz kapağı (kapama animasyonu için) */
    .eyelid {
      position: absolute;
      top: -100%;
      left: 0;
      right: 0;
      height: 100%;
      background: linear-gradient(135deg, #1d4ed8, #2563eb);
      transition: transform 0.25s ease;
      transform: translateY(0);
    }

    /* Eller (kitabın yanlarından çıkan eller gibi) */

    .hand {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      background: radial-gradient(circle at 30% 20%, #fed7aa, #f97316);
      position: absolute;
      bottom: -8px;
      box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
      transition: transform 0.25s ease;
      z-index: 3;
    }

    .hand-left {
      left: 8px;
      transform-origin: top right;
    }

    .hand-right {
      right: 8px;
      transform-origin: top left;
    }

    /* Ağız (kitabın kapağında gülümseme) */

    .mouth {
      position: absolute;
      bottom: 52px;
      left: 50%;
      transform: translateX(-50%);
      width: 60px;
      height: 20px;
      border-radius: 0 0 60px 60px;
      border: 4px solid #0b1120;
      border-top: none;
      background: rgba(248, 250, 252, 0.9);
      overflow: hidden;
      z-index: 2;
      transition: all 0.3s ease;
    }

    /* Karakter state'leri */

    /* Kullanıcı adı: gözler form tarafına kayıyor */
    .character.look-username .pupil {
      transform: translateX(6px);
    }

    /* Şifre: eller gözleri kapatıyor + göz kapakları iniyor */
    .character.cover-eyes .hand-left {
      transform: translate(16px, -92px) rotate(-18deg);
    }

    .character.cover-eyes .hand-right {
      transform: translate(-16px, -92px) rotate(18deg);
    }

    .character.cover-eyes .eyelid {
      transform: translateY(100%);
    }

    @keyframes smileGrow {
      from { height: 20px; border-radius: 0 0 50px 50px; }
      to { height: 35px; border-radius: 50%; }
    }

    /* ❌ Hatalı giriş (kaş çatma) */
    .character.error .eye {
      transform: rotate(8deg);
    }
    .character.error .mouth {
      width: 50px;
      height: 6px;
      background: #f87171;
      border-radius: 3px;
      border: none;
    }

    /* FORM TARAFI */

    .login-form {
      min-width: 260px;
      display: flex;
      flex-direction: column;
      gap: 16px;
      color: #e5e7eb;
      flex: 1;
    }

    .login-form h1 {
      font-size: 1.4rem;
      margin-bottom: 4px;
    }

    .login-form p {
      font-size: 0.9rem;
      color: #9ca3af;
      margin-bottom: 12px;
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .field label {
      font-size: 0.85rem;
      color: #cbd5f5;
    }

    .field input {
      padding: 10px 12px;
      border-radius: 10px;
      border: 1px solid #1f2937;
      background: #020617;
      color: #e5e7eb;
      font-size: 0.95rem;
      outline: none;
      transition: border 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .field input:focus {
      border-color: #3b82f6;
      box-shadow: 0 0 0 1px #1d4ed8;
      background: #020617;
    }

    .login-btn {
      margin-top: 8px;
      padding: 10px 12px;
      border-radius: 12px;
      border: none;
      background: linear-gradient(135deg, #3b82f6, #2563eb);
      color: white;
      font-weight: 600;
      cursor: pointer;
      font-size: 0.95rem;
      box-shadow: 0 12px 30px rgba(37, 99, 235, 0.4);
      transition: transform 0.15s ease, box-shadow 0.15s ease;
      width: 100%;
    }

    .login-btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 40px rgba(37, 99, 235, 0.55);
    }

    .login-btn:active {
      transform: translateY(0);
      box-shadow: 0 8px 18px rgba(37, 99, 235, 0.35);
    }

    @media (max-width: 640px) {
      .login-wrapper {
        flex-direction: column;
        padding: 24px;
      }

      .character {
        order: -1;
      }
    }
  </style>
</head>
<body data-status="<?= htmlspecialchars($status) ?>">
  <div class="login-wrapper">
    <!-- KARAKTER (KİTAP) -->
    <div class="character" id="character">
      <div class="book">
        <div class="eyes">
          <div class="eye">
            <div class="pupil"></div>
            <div class="eyelid"></div>
          </div>
          <div class="eye">
            <div class="pupil"></div>
            <div class="eyelid"></div>
          </div>
        </div>
        <div class="mouth"></div>
        <div class="hand hand-left"></div>
        <div class="hand hand-right"></div>
      </div>
    </div>

    <!-- FORM -->
    <form method="POST" class="login-form">
      <h1>MINOA'ya Hoş geldin 👋</h1>
      <p>Giriş yaparken kitap da sana eşlik etsin…</p>

      <?php if (!empty($error)): ?>
        <div class="error-box">
          <strong><?= htmlspecialchars($error) ?></strong>
          <?php if (!empty($errorDetails)): ?>
            <small><?= htmlspecialchars($errorDetails) ?></small>
          <?php endif; ?>
          <?php if (!empty($errorCode)): ?>
            <small><code><?= htmlspecialchars($errorCode) ?></code></small>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="field">
        <label for="username">Kullanıcı Adı</label>
        <input 
          type="text"
          id="username"
          name="username"
          required
          autocomplete="username"
          placeholder="Kullanıcı adınızı girin"
          value="<?= htmlspecialchars($userB2B ?? '') ?>"
        />
      </div>

      <div class="field">
        <label for="password">Şifre</label>
        <input
          type="password"
          id="password" 
          name="password"
          required
          autocomplete="current-password"
          placeholder="Şifrenizi girin"
        />
      </div>

      <button class="login-btn" type="submit">Giriş Yap</button>
    </form>
  </div>

  <script>
  const character = document.getElementById("character");
  const usernameInput = document.getElementById("username");
  const passwordInput = document.getElementById("password");
  const status = document.body.dataset.status || "";
  const pupils = character.querySelectorAll(".pupil");
  const loginWrapper = document.querySelector(".login-wrapper");

  let usernameFocused = false;

  function setEyes(x, y) {
    pupils.forEach(p => p.style.transform = `translate(${x}px, ${y}px)`);
  }

  function resetEyes() {
    setEyes(0, 0);
  }

  // Fare takibi (sadece username focus değilken)
  loginWrapper.addEventListener("mousemove", (e) => {
    if (usernameFocused) return;
    const rect = character.getBoundingClientRect();
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    const dx = e.clientX - centerX;
    const dy = e.clientY - centerY;
    const maxDist = 80, maxShift = 10;
    const clampedX = Math.max(-maxDist, Math.min(maxDist, dx));
    const clampedY = Math.max(-maxDist, Math.min(maxDist, dy));
    const offsetX = (clampedX / maxDist) * maxShift;
    const offsetY = (clampedY / maxDist) * maxShift;
    setEyes(offsetX, offsetY);
  });

  loginWrapper.addEventListener("mouseleave", () => {
    if (usernameFocused) return;
    resetEyes();
  });

  // Kullanıcı adını yazarken gözlerin sola daha çok bakması
  function updateEyesForUsername() {
    if (!usernameFocused) return;

    const rect = usernameInput.getBoundingClientRect();
    let caretIndex = usernameInput.selectionStart ?? usernameInput.value.length;
    const maxChars = 20;
    const ratio = Math.min(1, caretIndex / maxChars);

    const maxShiftX = 12; // genel hareket aralığı
    const offsetY = 2;    // hafif aşağı bakış

    // 🧭 Gözlerin başlangıç konumunu sola kaydırıyoruz
    const baseOffset = 6; // negatif = sola bakış
    const offsetX = baseOffset + (maxShiftX * ratio);

    setEyes(offsetX, offsetY);
  }

  usernameInput.addEventListener("focus", () => {
    usernameFocused = true;
    character.classList.add("look-username");
    character.classList.remove("cover-eyes");
    updateEyesForUsername();
  });

  usernameInput.addEventListener("input", updateEyesForUsername);
  usernameInput.addEventListener("keyup", updateEyesForUsername);

  usernameInput.addEventListener("blur", () => {
    usernameFocused = false;
    resetEyes();
  });

  passwordInput.addEventListener("focus", () => {
    usernameFocused = false;
    character.classList.remove("look-username");
    character.classList.add("cover-eyes");
    resetEyes();
  });

  document.addEventListener("click", (e) => {
    const isOnInputs =
      e.target === usernameInput || e.target === passwordInput;
    if (!isOnInputs) {
      usernameFocused = false;
      character.classList.remove("look-username", "cover-eyes");
      resetEyes();
    }
  });

  if (status === "error") {
    character.classList.add("error");
    setTimeout(() => character.classList.remove("error"), 1200);
  }
</script>


</body>
</html>
