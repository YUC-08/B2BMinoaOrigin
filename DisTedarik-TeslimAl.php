<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

$requestNo = $_GET['requestNo'] ?? '';
$orderNo = $_GET['orderNo'] ?? null;

if (empty($requestNo)) {
    die("Talep No bulunamadı!");
}

if (empty($orderNo)) {
    die("Sipariş No bulunamadı! Teslim almak için sipariş oluşturulmuş olmalıdır.");
}

$errorMsg = '';
$cardCode = '';
$cardName = '';
$lines = [];

// PurchaseOrder'dan detay çek
// Önce header bilgilerini çek ($expand çalışmıyor)
$orderQuery = 'PurchaseOrders(' . intval($orderNo) . ')';
$orderData = $sap->get($orderQuery);

$debugInfo = [];
$debugInfo['query'] = $orderQuery;
$debugInfo['http_status'] = $orderData['status'] ?? 'NO STATUS';
$debugInfo['has_response'] = isset($orderData['response']);
$debugInfo['error'] = $orderData['error'] ?? null;
$debugInfo['response_error'] = $orderData['response']['error'] ?? null;

if (($orderData['status'] ?? 0) == 200 && isset($orderData['response'])) {
    $purchaseOrderData = $orderData['response'];
    $cardCode = $purchaseOrderData['CardCode'] ?? '';
    $cardName = $purchaseOrderData['CardName'] ?? '';
    
    // Satırları ayrı endpoint'ten çek
    $linesQuery = 'PurchaseOrders(' . intval($orderNo) . ')/DocumentLines';
    $linesData = $sap->get($linesQuery);
    
    $debugInfo['lines_query'] = $linesQuery;
    $debugInfo['lines_http_status'] = $linesData['status'] ?? 'NO STATUS';
    
    if (($linesData['status'] ?? 0) == 200 && isset($linesData['response'])) {
        if (isset($linesData['response']['value'])) {
            $lines = $linesData['response']['value'];
        } elseif (is_array($linesData['response'])) {
            $lines = $linesData['response'];
        }
    }
} else {
    $errorMsg = "Sipariş detayları alınamadı! HTTP " . ($orderData['status'] ?? 'NO STATUS');
    if (isset($orderData['response']['error'])) {
        $errorMsg .= " - " . json_encode($orderData['response']['error']);
    }
}

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'teslim_al') {
    $deliveryLines = [];
    $teslimatNo = trim($_POST['teslimat_no'] ?? '');
    
    foreach ($lines as $index => $line) {
        $irsaliyeQty = floatval($_POST['irsaliye_qty'][$index] ?? 0);
        if ($irsaliyeQty > 0) {
            $deliveryLines[] = [
                'BaseType' => 22, // Purchase Order
                'BaseEntry' => intval($orderNo),
                'BaseLine' => intval($line['LineNum'] ?? 0),
                'Quantity' => $irsaliyeQty
            ];
        }
    }
    
    if (empty($deliveryLines)) {
        $errorMsg = "Lütfen en az bir kalem için irsaliye miktarı girin!";
    } else {
        $payload = [
            'CardCode' => $cardCode,
            'U_ASB2B_NumAtCard' => $teslimatNo,
            'DocumentLines' => $deliveryLines
        ];
        
        $result = $sap->post("PurchaseDeliveryNotes", $payload);
        
        if (($result['status'] ?? 0) == 201 || ($result['status'] ?? 0) == 200) {
            header("Location: DisTedarik.php?msg=ok");
            exit;
        } else {
            $errorMsg = "Teslim alma işlemi başarısız! HTTP " . ($result['status'] ?? 'NO STATUS');
            if (isset($result['response']['error'])) {
                error_log("[DIS_TEDARIK_TESLIM] Error: " . json_encode($result['response']['error']));
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dis Tedarik Teslim Al - MINOA</title>
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    color: #2c3e50;
    line-height: 1.6;
}

.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
}

.page-header {
    background: white;
    padding: 20px 2rem;
    border-radius: 0 0 0 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 0;
    position: sticky;
    top: 0;
    z-index: 100;
    height: 80px;
    box-sizing: border-box;
}

.page-header h2 {
    color: #1e40af;
    font-size: 1.75rem;
    font-weight: 600;
    margin: 0;
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin: 0 32px 24px 32px;
}

.alert {
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin: 0 32px 1.5rem 32px;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.alert-danger {
    background: #fee2e2;
    border: 1px solid #ef4444;
    color: #991b1b;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #374151;
}

.form-group input {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 0.875rem;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.data-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.qty-input {
    width: 100px;
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    text-align: center;
}

.qty-btn {
    padding: 0.5rem 0.75rem;
    border: 1px solid #d1d5db;
    background: white;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
}

.quantity-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.btn {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

.form-actions {
    margin-top: 2rem;
    text-align: right;
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Teslim Al - Talep No: <?= htmlspecialchars($requestNo) ?> | Sipariş No: <?= htmlspecialchars($orderNo) ?></h2>
            <button class="btn btn-secondary" onclick="window.location.href='DisTedarik.php'">← Geri Dön</button>
        </header>

        <?php if ($errorMsg): ?>
            <div class="alert alert-danger">
                <strong>Hata:</strong> <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($debugInfo) && ($errorMsg || empty($lines))): ?>
            <div class="card" style="background: #fef3c7; border: 2px solid #f59e0b; margin-bottom: 1.5rem;">
                <h3 style="color: #92400e; margin-bottom: 1rem;">🔍 Debug Bilgileri</h3>
                <div style="font-family: monospace; font-size: 0.85rem; color: #78350f;">
                    <p><strong>Request No:</strong> <?= htmlspecialchars($requestNo) ?></p>
                    <p><strong>Order No:</strong> <?= htmlspecialchars($orderNo) ?></p>
                    <p><strong>Query:</strong> <?= htmlspecialchars($debugInfo['query'] ?? 'N/A') ?></p>
                    <p><strong>HTTP Status:</strong> <?= htmlspecialchars($debugInfo['http_status'] ?? 'N/A') ?></p>
                    <p><strong>Has Response:</strong> <?= $debugInfo['has_response'] ? 'Evet' : 'Hayır' ?></p>
                    <?php if (isset($debugInfo['lines_query'])): ?>
                        <p><strong>Lines Query:</strong> <?= htmlspecialchars($debugInfo['lines_query']) ?></p>
                        <p><strong>Lines HTTP Status:</strong> <?= htmlspecialchars($debugInfo['lines_http_status'] ?? 'N/A') ?></p>
                    <?php endif; ?>
                    <?php if ($debugInfo['error']): ?>
                        <p style="color: #dc2626;"><strong>Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></p>
                    <?php endif; ?>
                    <?php if ($debugInfo['response_error']): ?>
                        <p style="color: #dc2626;"><strong>Response Error:</strong> <?= htmlspecialchars(json_encode($debugInfo['response_error'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($lines)): ?>
            <div class="card">
                <p style="color: #ef4444;">Satır bulunamadı veya sipariş oluşturulmamış!</p>
            </div>
        <?php else: ?>
            <form method="POST" action="" onsubmit="return validateForm()">
                <input type="hidden" name="action" value="teslim_al">
                
                <div class="card">
                    <div class="form-group">
                        <label>Teslimat Numarası</label>
                        <input type="text" name="teslimat_no" placeholder="İrsaliye/Teslimat numarası">
                    </div>
                </div>

                <div class="card">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Kalem Kodu</th>
                                <th>Kalem Tanımı</th>
                                <th>Sipariş Miktarı</th>
                                <th>İrsaliye Miktarı</th>
                                <th>Eksik/Fazla Miktar</th>
                                <th>Kusurlu Miktar</th>
                                <th>Not</th>
                                <th>Görsel</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $index => $line): 
                                $quantity = floatval($line['Quantity'] ?? 0);
                                $remainingQty = floatval($line['RemainingOpenQuantity'] ?? $quantity);
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($line['ItemCode'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($line['ItemDescription'] ?? '-') ?></td>
                                    <td>
                                        <input type="number" 
                                               value="<?= htmlspecialchars($quantity) ?>" 
                                               readonly 
                                               step="0.01"
                                               class="qty-input">
                                    </td>
                                    <td>
                                        <div class="quantity-controls">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'irsaliye', -1)">-</button>
                                            <input type="number" 
                                                   name="irsaliye_qty[<?= $index ?>]"
                                                   id="irsaliye_<?= $index ?>"
                                                   value="" 
                                                   min="0" 
                                                   step="0.01"
                                                   class="qty-input"
                                                   placeholder="0">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'irsaliye', 1)">+</button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="quantity-controls">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'eksik', -1)">-</button>
                                            <input type="number" 
                                                   name="eksik_fazla[<?= $index ?>]"
                                                   id="eksik_<?= $index ?>"
                                                   value="0" 
                                                   step="0.01"
                                                   class="qty-input">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'eksik', 1)">+</button>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="quantity-controls">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'kusurlu', -1)">-</button>
                                            <input type="number" 
                                                   name="kusurlu[<?= $index ?>]"
                                                   id="kusurlu_<?= $index ?>"
                                                   value="0" 
                                                   min="0"
                                                   step="0.01"
                                                   class="qty-input">
                                            <button type="button" class="qty-btn" onclick="changeQuantity(<?= $index ?>, 'kusurlu', 1)">+</button>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" 
                                               name="not[<?= $index ?>]"
                                               placeholder="Not"
                                               style="width: 100%; padding: 0.5rem; border: 1px solid #d1d5db; border-radius: 6px;">
                                    </td>
                                    <td>
                                        <input type="file" 
                                               name="gorsel[<?= $index ?>]"
                                               accept="image/*"
                                               style="font-size: 0.75rem;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='DisTedarik.php'">İptal</button>
                        <button type="submit" class="btn btn-primary">✓ Teslim Al / Onayla</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <script>
function changeQuantity(index, type, delta) {
    const input = document.getElementById(type + '_' + index);
    if (!input) return;
    
    let value = parseFloat(input.value) || 0;
    value += delta;
    if (value < 0) value = 0;
    input.value = value;
}

function validateForm() {
    const irsaliyeInputs = document.querySelectorAll('input[name^="irsaliye_qty"]');
    let hasQuantity = false;
    
    irsaliyeInputs.forEach(input => {
        if (parseFloat(input.value) > 0) {
            hasQuantity = true;
        }
    });
    
    if (!hasQuantity) {
        alert('Lütfen en az bir kalem için irsaliye miktarı girin!');
        return false;
    }
    
    return true;
}
    </script>
</body>
</html>
