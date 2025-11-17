<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// URL'den parametreler
$docEntry = $_GET['docEntry'] ?? '';
$type = $_GET['type'] ?? 'incoming'; // incoming veya outgoing

if (empty($docEntry)) {
    header("Location: Transferler.php");
    exit;
}

// InventoryTransferRequests({docEntry}) çağır
$docQuery = "InventoryTransferRequests({$docEntry})";
$docData = $sap->get($docQuery);
$requestData = $docData['response'] ?? null;

if (!$requestData) {
    echo "Belge bulunamadı!";
    exit;
}

// Status mapping
function getStatusText($status) {
    $statusMap = [
        '0' => 'Onay Bekliyor',
        '1' => 'Onay Bekliyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk Edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        '0' => 'status-pending',
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[$status] ?? 'status-unknown';
}

function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

function isReceivableStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['2', '3'], true);
}

function isApprovalStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['0', '1'], true);
}

$docDate = formatDate($requestData['DocDate'] ?? '');
$dueDate = formatDate($requestData['DueDate'] ?? '');
$status = $requestData['U_ASB2B_STATUS'] ?? '0';
$statusText = getStatusText($status);
$statusClass = getStatusClass($status);
$numAtCard = $requestData['U_ASB2B_NumAtCard'] ?? '-';
$journalMemo = $requestData['JournalMemo'] ?? '-';
$fromWarehouse = $requestData['FromWarehouse'] ?? '';
$toWarehouse = $requestData['ToWarehouse'] ?? '';
$lines = $requestData['StockTransferLines'] ?? [];

// StockTransfer bilgileri (Sevk Edildi/Tamamlandı durumunda)
$stockTransferInfo = null;
$stockTransferLinesMap = [];

if ($status === '3' || $status === '4') {
    $stockTransferFilter = "BaseType eq 1250000001 and BaseEntry eq {$docEntry}";
    $stockTransferQuery = "StockTransfers?\$filter=" . urlencode($stockTransferFilter) . "&\$expand=StockTransferLines&\$orderby=DocEntry desc&\$top=1";
    $stockTransferData = $sap->get($stockTransferQuery);
    $stockTransfers = $stockTransferData['response']['value'] ?? [];
    
    if (!empty($stockTransfers)) {
        $stockTransferInfo = $stockTransfers[0];
        $stLines = $stockTransferInfo['StockTransferLines'] ?? [];
        foreach ($stLines as $stLine) {
            $itemCode = $stLine['ItemCode'] ?? '';
            $qty = (float)($stLine['Quantity'] ?? 0);
            $stockTransferLinesMap[$itemCode] = $qty;
        }
    }
}

$canReceive = isReceivableStatus($status);
$canApprove = isApprovalStatus($status);
$showNewRequestButton = ($type === 'incoming'); // Sadece gelen transferler için
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Detayı - CREMMAVERSE</title>
    <link rel="stylesheet" href="navbar.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            color: #1f2937;
        }

        .main-content {
            width: 100%;
            padding: 0 32px 48px 32px;
            margin-left: 70px;
        }

        .sidebar.expanded ~ .main-content {
            margin-left: 240px;
        }

        .content-wrapper {
            max-width: 1400px;
            margin: 0 auto;
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
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 12px 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 20px rgba(37, 99, 235, 0.15);
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-secondary {
            background: #6b7280;
            color: #fff;
        }

        .btn-receive {
            background: #10b981;
            color: white;
        }

        .btn-approve {
            background: #f59e0b;
            color: white;
        }

        .card {
            margin-top: 24px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 25px 50px rgba(15, 23, 42, 0.08);
            padding: 32px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .detail-item label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-item .detail-value {
            font-size: 1rem;
            color: #1f2937;
            font-weight: 500;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-processing {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-shipped {
            background: #bfdbfe;
            color: #1e3a8a;
        }

        .status-completed {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 24px;
        }

        .data-table thead {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            color: white;
        }

        .data-table th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
        }

        .data-table tbody tr:hover {
            background: #f8fafc;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <div class="content-wrapper">
            <header class="page-header">
                <h2>Transfer Detayı #<?= htmlspecialchars($docEntry) ?></h2>
                <div class="header-actions">
                    <?php if ($showNewRequestButton): ?>
                        <a href="TransferlerSO.php" class="btn btn-primary">+ Yeni Talep Oluştur</a>
                    <?php endif; ?>
                    <?php if ($canReceive): ?>
                        <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($docEntry) ?>" class="btn btn-receive">✓ Teslim Al</a>
                    <?php endif; ?>
                    <?php if ($canApprove && $type === 'outgoing'): ?>
                        <a href="Transferler-Onayla.php?docEntry=<?= urlencode($docEntry) ?>" class="btn btn-approve">✓ Onayla</a>
                    <?php endif; ?>
                    <a href="Transferler.php" class="btn btn-secondary">← Geri</a>
                </div>
            </header>

            <div class="card">
                <div class="detail-grid">
                    <div class="detail-item">
                        <label>Transfer No</label>
                        <div class="detail-value" style="font-weight: 700; color: #1e40af;"><?= htmlspecialchars($docEntry) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Talep Tarihi</label>
                        <div class="detail-value"><?= htmlspecialchars($docDate) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Vade Tarihi</label>
                        <div class="detail-value"><?= htmlspecialchars($dueDate) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Durum</label>
                        <div class="detail-value">
                            <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <label>Teslimat Belge No</label>
                        <div class="detail-value"><?= htmlspecialchars($numAtCard) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Not</label>
                        <div class="detail-value"><?= htmlspecialchars($journalMemo) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Gönderen Depo</label>
                        <div class="detail-value"><?= htmlspecialchars($fromWarehouse) ?></div>
                    </div>
                    <div class="detail-item">
                        <label>Alıcı Depo</label>
                        <div class="detail-value"><?= htmlspecialchars($toWarehouse) ?></div>
                    </div>
                </div>

                <?php if ($stockTransferInfo): ?>
                    <div style="margin-top: 32px; padding-top: 32px; border-top: 2px solid #e5e7eb;">
                        <h3 style="font-size: 1.25rem; color: #1e40af; margin-bottom: 16px;">Sevk Bilgileri</h3>
                        <div class="detail-grid">
                            <div class="detail-item">
                                <label>StockTransfer DocEntry</label>
                                <div class="detail-value"><?= htmlspecialchars($stockTransferInfo['DocEntry'] ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <label>StockTransfer DocNum</label>
                                <div class="detail-value"><?= htmlspecialchars($stockTransferInfo['DocNum'] ?? '-') ?></div>
                            </div>
                            <div class="detail-item">
                                <label>Sevk Tarihi</label>
                                <div class="detail-value"><?= formatDate($stockTransferInfo['DocDate'] ?? '') ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kalem Numarası</th>
                            <th>Kalem Tanımı</th>
                            <th>Talep Miktarı</th>
                            <th>Teslimat Miktarı</th>
                            <th>Birim</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lines)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    Kalem bulunamadı.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lines as $line): 
                                $itemCode = $line['ItemCode'] ?? '';
                                $itemName = $line['ItemDescription'] ?? '';
                                $quantity = (float)($line['Quantity'] ?? 0);
                                $uomCode = $line['UoMCode'] ?? '';
                                
                                // Teslimat miktarı
                                $delivered = 0;
                                if ($status === '3' || $status === '4') {
                                    $delivered = $stockTransferLinesMap[$itemCode] ?? 0;
                                }
                            ?>
                                <tr>
                                    <td style="font-weight: 600; color: #1e40af;"><?= htmlspecialchars($itemCode) ?></td>
                                    <td><?= htmlspecialchars($itemName) ?></td>
                                    <td><?= number_format($quantity, 2) ?></td>
                                    <td><?= number_format($delivered, 2) ?></td>
                                    <td><?= htmlspecialchars($uomCode) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>

