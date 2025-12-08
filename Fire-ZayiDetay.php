<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

include 'sap_connect.php';
$sap = new SAPConnect();

// DocEntry parametresi gerekli
$docEntry = isset($_GET['DocEntry']) ? intval($_GET['DocEntry']) : null;

if (empty($docEntry)) {
    header("Location: Fire-Zayi.php");
    exit;
}

// StockTransfer belgesini çek
$transferQuery = "StockTransfers({$docEntry})";
$transferData = $sap->get($transferQuery);

if (($transferData['status'] ?? 0) != 200) {
    die("Fire/Zayi belgesi bulunamadı veya erişilemedi.");
}

$transfer = $transferData['response'] ?? $transferData;
$lines = [];

// Header response'undan StockTransferLines'ı al
if (isset($transfer['StockTransferLines']) && is_array($transfer['StockTransferLines'])) {
    $lines = $transfer['StockTransferLines'];
}

// Eğer hala boşsa, direkt collection path'i dene
if (empty($lines)) {
    $linesQuery = "StockTransfers({$docEntry})/StockTransferLines";
    $linesData = $sap->get($linesQuery);
    
    if (($linesData['status'] ?? 0) == 200) {
        $linesResponse = $linesData['response'] ?? $linesData;
        
        // Farklı response yapılarını kontrol et
        if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
            $lines = $linesResponse['value'];
        } elseif (isset($linesResponse['StockTransferLines']) && is_array($linesResponse['StockTransferLines'])) {
            $lines = $linesResponse['StockTransferLines'];
        } elseif (is_array($linesResponse)) {
            $lines = $linesResponse;
        }
    }
}

// Tür mapping
function getTypeText($lost) {
    if ($lost == '1') return 'Fire';
    if ($lost == '2') return 'Zayi';
    return '-';
}

function getTypeClass($lost) {
    if ($lost == '1') return 'status-fire';
    if ($lost == '2') return 'status-zayi';
    return '';
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

// Sayı formatlama
function formatNumber($num) {
    if (empty($num) && $num !== 0) return '-';
    return number_format((float)$num, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fire/Zayi Detay - MINOA</title>
    <?php include 'navbar.php'; ?>
    <link rel="stylesheet" href="styles.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #111827;
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
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            margin-bottom: 24px;
            overflow: visible;
        }

        .card-header {
            padding: 20px 24px 0 24px;
        }

        .card-header h3 {
            color: #1e40af;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0;
        }

        .card-body {
            padding: 16px 24px 24px 24px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .info-label {
            font-size: 12px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 15px;
            color: #111827;
            font-weight: 600;
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

        .status-fire {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-zayi {
            background: #fef3c7;
            color: #92400e;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            table-layout: fixed;
        }

        .data-table thead {
            background: #f8fafc;
        }

        .data-table th {
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e5e7eb;
        }

        .data-table th.text-right {
            text-align: right;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.15s;
        }

        .data-table tbody tr:hover {
            background: #f9fafb;
        }

        .data-table td {
            padding: 12px 16px;
            color: #374151;
            text-align: left;
        }

        .data-table td.text-right {
            text-align: right;
        }

        .text-right {
            text-align: right;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: white;
            color: #3b82f6;
            border: 2px solid #3b82f6;
        }

        .btn-secondary:hover {
            background: #f0f9ff;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: flex-end;
        }

        .empty-message {
            text-align: center;
            padding: 3rem;
            color: #9ca3af;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <main class="main-content">
        <header class="page-header">
            <h2>Fire/Zayi Detay</h2>
            <button class="btn btn-secondary" onclick="window.location.href='Fire-Zayi.php'">← Geri Dön</button>
        </header>

        <div class="content-wrapper">
            <!-- Üst Bilgiler -->
            <section class="card">
                <div class="card-header">
                    <h3>Üst Bilgiler</h3>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Belge No</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['DocEntry'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Seri</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['Series'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Tarih</span>
                            <span class="info-value"><?= formatDate($transfer['DocDate'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Tür</span>
                            <span class="info-value">
                                <span class="status-badge <?= getTypeClass($transfer['U_ASB2B_LOST'] ?? '') ?>">
                                    <?= getTypeText($transfer['U_ASB2B_LOST'] ?? '') ?>
                                </span>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Çıkış Depo</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['FromWarehouse'] ?? $transfer['FromWarehouseCode'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Giriş Depo</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['ToWarehouse'] ?? $transfer['ToWarehouseCode'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Şube</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['U_ASB2B_BRAN'] ?? '-') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Sahip</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['U_AS_OWNR'] ?? '-') ?></span>
                        </div>
                        <?php if (!empty($transfer['Comments'] ?? '')): ?>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <span class="info-label">Açıklama</span>
                            <span class="info-value"><?= htmlspecialchars($transfer['Comments'] ?? '') ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <!-- Satır Listesi -->
            <section class="card">
                <div class="card-header">
                    <h3>Satır Listesi</h3>
                </div>
                <div class="card-body">
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Satır No</th>
                                    <th>Ürün Kodu</th>
                                    <th>Ürün Adı</th>
                                    <th>Birim</th>
                                    <th class="text-right">Miktar</th>
                                    <th class="text-right">Birim Fiyat</th>
                                    <th class="text-right">Toplam</th>
                                </tr>
                            </thead>
                            <tbody id="linesTableBody">
                                <?php if (empty($lines)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; padding: 40px; color: #6b7280;">
                                        Satır bulunmamaktadır.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php 
                                $grandTotal = 0;
                                foreach ($lines as $line): 
                                    $lineNum = $line['LineNum'] ?? $line['LineNumber'] ?? '';
                                    $itemCode = $line['ItemCode'] ?? '';
                                    $itemName = $line['ItemDescription'] ?? '';
                                    $uomCode = $line['UoMCode'] ?? '';
                                    $quantity = floatval($line['Quantity'] ?? 0);
                                    $unitPrice = floatval($line['UnitPrice'] ?? 0);
                                    $total = $quantity * $unitPrice;
                                    $grandTotal += $total;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($lineNum) ?></td>
                                    <td><strong><?= htmlspecialchars($itemCode) ?></strong></td>
                                    <td><?= htmlspecialchars($itemName) ?></td>
                                    <td><?= htmlspecialchars($uomCode) ?></td>
                                    <td class="text-right"><?= formatNumber($quantity) ?></td>
                                    <td class="text-right"><?= formatNumber($unitPrice) ?> ₺</td>
                                    <td class="text-right"><strong><?= formatNumber($total) ?> ₺</strong></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (!empty($lines)): ?>
                                <tr style="background: #f8fafc; font-weight: 600; border-top: 2px solid #e5e7eb;">
                                    <td colspan="4" style="padding: 16px; border: none;"></td>
                                    <td style="padding: 16px; border: none;"></td>
                                    <td class="text-right" style="padding: 16px; font-size: 14px; color: #6b7280; border: none;">GENEL TOPLAM:</td>
                                    <td class="text-right" style="padding: 16px; font-size: 16px; color: #1e40af; border: none;">
                                        <strong><?= formatNumber($grandTotal) ?> ₺</strong>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Butonlar -->
            <div class="action-buttons">
                <button class="btn btn-secondary" onclick="window.location.href='Fire-Zayi.php'">Geri Dön</button>
                <button class="btn btn-primary" onclick="window.print()">Yazdır</button>
            </div>
        </div>
    </main>
</body>
</html>


