<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// GET veya POST'tan parametreleri al
$docEntry = $_GET['docEntry'] ?? $_POST['docEntry'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? ''; // 'approve' veya 'reject'
$lines = $_POST['lines'] ?? null; // POST ile gönderilen lines (şimdilik kullanılmıyor)

if (empty($docEntry) || empty($action)) {
    // JSON response döndür (AJAX istekleri için)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Eksik parametreler']);
        exit;
    }
    header("Location: Transferler.php?view=outgoing&error=missing_params");
    exit;
}

// Action'a göre status belirle
$newStatus = '';
if ($action === 'approve') {
    $newStatus = '2'; // HAZIRLANIYOR
} elseif ($action === 'reject') {
    $newStatus = '5'; // İPTAL EDİLDİ
} else {
    header("Location: Transferler.php?view=outgoing&error=invalid_action");
    exit;
}

// PATCH request ile status güncelle
$updatePayload = [
    'U_ASB2B_STATUS' => $newStatus
];

$result = $sap->patch("InventoryTransferRequests({$docEntry})", $updatePayload);

// AJAX isteği ise JSON döndür
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if ($result['status'] == 200 || $result['status'] == 204) {
        echo json_encode(['success' => true, 'message' => $action === 'approve' ? 'Transfer onaylandı' : 'Transfer iptal edildi']);
    } else {
        $errorMsg = 'Durum güncellenemedi: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        echo json_encode(['success' => false, 'message' => $errorMsg]);
    }
} else {
    // Normal GET isteği ise redirect yap
    if ($result['status'] == 200 || $result['status'] == 204) {
        $successMsg = $action === 'approve' ? 'onaylandi' : 'iptal_edildi';
        header("Location: Transferler.php?view=outgoing&msg={$successMsg}");
    } else {
        $errorMsg = 'Durum güncellenemedi: HTTP ' . ($result['status'] ?? 'NO STATUS');
        if (isset($result['response']['error'])) {
            $errorMsg .= ' - ' . json_encode($result['response']['error']);
        }
        header("Location: Transferler.php?view=outgoing&error=" . urlencode($errorMsg));
    }
}
exit;
?>