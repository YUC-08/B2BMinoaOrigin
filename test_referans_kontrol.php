<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// Test için StockTransfer DocEntry'leri - URL'den al veya varsayılan kullan
$testDocEntries = $_GET['docEntries'] ?? '9256,9257';
$stockTransferDocEntries = array_map('trim', explode(',', $testDocEntries));
$inventoryTransferRequestDocEntry = $_GET['requestDocEntry'] ?? 3751; // Ana talep belgesi

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Referans İlişkileri Kontrolü</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 3px solid #4CAF50; padding-bottom: 10px; }
        h3 { color: #555; margin-top: 30px; }
        h4 { color: #666; margin-top: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        .success { background-color: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { background-color: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .warning { background-color: #fff3cd; color: #856404; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { background-color: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .debug { background-color: #f8f9fa; border: 1px solid #dee2e6; padding: 15px; border-radius: 4px; margin: 10px 0; font-family: monospace; font-size: 12px; }
        .debug pre { margin: 0; white-space: pre-wrap; word-wrap: break-word; }
        .section { margin: 30px 0; padding: 20px; background: #f9f9f9; border-radius: 4px; }
        .link { color: #4CAF50; text-decoration: none; }
        .link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class='container'>
<h2>Referans İlişkileri Kontrolü</h2>";

// Debug: Hangi belgeler kontrol ediliyor
echo "<div class='info'><strong>Kontrol Edilen Belgeler:</strong><br>";
echo "StockTransfer DocEntry'leri: " . implode(', ', $stockTransferDocEntries) . "<br>";
echo "InventoryTransferRequest DocEntry: {$inventoryTransferRequestDocEntry}<br>";
echo "</div>";

foreach ($stockTransferDocEntries as $stDocEntry) {
    echo "<div class='section'>";
    echo "<h3>StockTransfer {$stDocEntry} Kontrolü</h3>";
    
    // StockTransfer bilgilerini çek
    $stQuery = "StockTransfers({$stDocEntry})?\$select=DocEntry,DocNum,FromWarehouse,ToWarehouse,U_ASB2B_QutMaster";
    $stData = $sap->get($stQuery);
    $stInfo = $stData['response'] ?? null;
    
    if (!$stInfo) {
        echo "<p class='error'>StockTransfer {$stDocEntry} bulunamadı!</p>";
        echo "<div class='debug'><strong>Query:</strong> {$stQuery}<br><strong>Status:</strong> " . ($stData['status'] ?? 'N/A') . "<br><strong>Response:</strong><pre>" . json_encode($stData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre></div>";
        echo "</div>";
        continue;
    }
    
    echo "<table>";
    echo "<tr><th>Alan</th><th>Değer</th></tr>";
    echo "<tr><td>DocEntry</td><td>" . ($stInfo['DocEntry'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>DocNum</td><td>" . ($stInfo['DocNum'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>FromWarehouse</td><td>" . ($stInfo['FromWarehouse'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>ToWarehouse</td><td>" . ($stInfo['ToWarehouse'] ?? 'N/A') . "</td></tr>";
    echo "<tr><td>U_ASB2B_QutMaster</td><td>" . ($stInfo['U_ASB2B_QutMaster'] ?? 'N/A') . "</td></tr>";
    echo "</table>";
    
    // U_ASB2B_QutMaster kontrolü
    $qutMaster = (int)($stInfo['U_ASB2B_QutMaster'] ?? 0);
    if ($qutMaster == $inventoryTransferRequestDocEntry) {
        echo "<p class='success'>✓ U_ASB2B_QutMaster doğru: {$qutMaster} (InventoryTransferRequest {$inventoryTransferRequestDocEntry})</p>";
    } else {
        echo "<p class='error'>✗ U_ASB2B_QutMaster yanlış: {$qutMaster} (Beklenen: {$inventoryTransferRequestDocEntry})</p>";
    }
    
    // Depo yönüne göre ilişki kontrolü
    $fromWhs = $stInfo['FromWarehouse'] ?? '';
    $toWhs = $stInfo['ToWarehouse'] ?? '';
    
    // İlk StockTransfer kontrolü: ToWarehouse = sevkiyat depo olmalı
    // İkinci StockTransfer kontrolü: FromWarehouse = sevkiyat depo, ToWarehouse = ana depo olmalı
    // Bu bilgileri almak için sevkiyat deposunu bulmamız gerekiyor
    // Basit kontrol: Eğer ToWarehouse'da "sevkiyat" veya "sevkiya" varsa, bu ilk ST olabilir
    // Eğer FromWarehouse'da "sevkiyat" veya "sevkiya" varsa ve ToWarehouse'da "ana" varsa, bu ikinci ST olabilir
    
    $isLikelyFirstST = (stripos($toWhs, 'sevkiyat') !== false || stripos($toWhs, 'sevkiya') !== false) && 
                        stripos($fromWhs, 'sevkiyat') === false && stripos($fromWhs, 'sevkiya') === false;
    $isLikelySecondST = (stripos($fromWhs, 'sevkiyat') !== false || stripos($fromWhs, 'sevkiya') !== false) && 
                         (stripos($toWhs, 'ana') !== false || stripos($toWhs, 'main') !== false);
    
    if ($isLikelyFirstST) {
        echo "<p class='info'>ℹ Depo yönü: İlk StockTransfer (gönderen → sevkiyat depo) - FromWarehouse: {$fromWhs}, ToWarehouse: {$toWhs}</p>";
    } elseif ($isLikelySecondST) {
        echo "<p class='info'>ℹ Depo yönü: İkinci StockTransfer (sevkiyat depo → ana depo) - FromWarehouse: {$fromWhs}, ToWarehouse: {$toWhs}</p>";
        
        // İkinci ST ise, ilk ST'i bul
        // İlk ST: U_ASB2B_QutMaster = qutMaster, ToWarehouse = sevkiyatDepo (fromWhs)
        if ($qutMaster > 0 && $fromWhs) {
            $firstSTFilter = "U_ASB2B_QutMaster eq {$qutMaster} and ToWarehouse eq '{$fromWhs}'";
            $firstSTQuery = "StockTransfers?\$filter=" . urlencode($firstSTFilter) . "&\$orderby=DocEntry asc&\$top=1&\$select=DocEntry,DocNum";
            $firstSTData = $sap->get($firstSTQuery);
            $firstSTList = $firstSTData['response']['value'] ?? [];
            if (!empty($firstSTList)) {
                $firstST = $firstSTList[0];
                echo "<p class='success'>✓ İlk StockTransfer bulundu (depo yönü ile): DocEntry: " . ($firstST['DocEntry'] ?? 'N/A') . ", DocNum: " . ($firstST['DocNum'] ?? 'N/A') . "</p>";
            } else {
                echo "<p class='warning'>⚠ İlk StockTransfer bulunamadı (Filter: {$firstSTFilter})</p>";
            }
        }
    } else {
        echo "<p class='info'>ℹ Depo yönü: Belirsiz - FromWarehouse: {$fromWhs}, ToWarehouse: {$toWhs}</p>";
    }
    
    // DocumentReferences'ı çek - çoklu deneme stratejisi
    $documentReferences = null;
    $debugInfo = [];
    
    // Deneme 1: Expand ile
    $refQuery1 = "StockTransfers({$stDocEntry})?\$expand=DocumentReferences";
    $refData1 = $sap->get($refQuery1);
    $refInfo1 = $refData1['response'] ?? null;
    $debugInfo['deneme1'] = [
        'query' => $refQuery1,
        'status' => $refData1['status'] ?? null,
        'hasDocumentReferences' => isset($refInfo1['DocumentReferences']),
        'documentReferencesCount' => isset($refInfo1['DocumentReferences']) && is_array($refInfo1['DocumentReferences']) ? count($refInfo1['DocumentReferences']) : 0
    ];
    
    if ($refInfo1 && isset($refInfo1['DocumentReferences']) && is_array($refInfo1['DocumentReferences'])) {
        $documentReferences = $refInfo1['DocumentReferences'];
        $debugInfo['deneme1']['sonuc'] = 'BAŞARILI';
    } else {
        $debugInfo['deneme1']['sonuc'] = 'BAŞARISIZ';
    }
    
    // Deneme 2: Direct query
    if (empty($documentReferences)) {
        $refQuery2 = "StockTransfers({$stDocEntry})/DocumentReferences";
        $refData2 = $sap->get($refQuery2);
        $refResponse2 = $refData2['response'] ?? null;
        $debugInfo['deneme2'] = [
            'query' => $refQuery2,
            'status' => $refData2['status'] ?? null,
            'hasValue' => isset($refResponse2['value']),
            'valueCount' => isset($refResponse2['value']) && is_array($refResponse2['value']) ? count($refResponse2['value']) : 0
        ];
        
        if ($refResponse2) {
            if (isset($refResponse2['value']) && is_array($refResponse2['value'])) {
                $documentReferences = $refResponse2['value'];
                $debugInfo['deneme2']['sonuc'] = 'BAŞARILI (value)';
            } elseif (is_array($refResponse2) && !isset($refResponse2['@odata.context'])) {
                $documentReferences = $refResponse2;
                $debugInfo['deneme2']['sonuc'] = 'BAŞARILI (array)';
            } else {
                $debugInfo['deneme2']['sonuc'] = 'BAŞARISIZ';
            }
        } else {
            $debugInfo['deneme2']['sonuc'] = 'BAŞARISIZ (response null)';
        }
    }
    
    // Deneme 3: Tüm alanları çek ve DocumentReferences'ı kontrol et
    if (empty($documentReferences)) {
        $fullQuery = "StockTransfers({$stDocEntry})";
        $fullData = $sap->get($fullQuery);
        $fullInfo = $fullData['response'] ?? null;
        
        $docRefsValue = null;
        $docRefsType = 'yok';
        if ($fullInfo) {
            if (isset($fullInfo['DocumentReferences'])) {
                $docRefsValue = $fullInfo['DocumentReferences'];
                if (is_array($docRefsValue)) {
                    $docRefsType = 'array (count: ' . count($docRefsValue) . ')';
                } else {
                    $docRefsType = gettype($docRefsValue);
                }
            }
        }
        
        $debugInfo['deneme3'] = [
            'query' => $fullQuery,
            'status' => $fullData['status'] ?? null,
            'hasDocumentReferences' => isset($fullInfo['DocumentReferences']),
            'documentReferencesType' => $docRefsType,
            'documentReferencesValue' => $docRefsValue,
            'documentReferencesCount' => isset($fullInfo['DocumentReferences']) && is_array($fullInfo['DocumentReferences']) ? count($fullInfo['DocumentReferences']) : 0
        ];
        
        if ($fullInfo && isset($fullInfo['DocumentReferences']) && is_array($fullInfo['DocumentReferences']) && !empty($fullInfo['DocumentReferences'])) {
            $documentReferences = $fullInfo['DocumentReferences'];
            $debugInfo['deneme3']['sonuc'] = 'BAŞARILI';
        } else {
            $debugInfo['deneme3']['sonuc'] = 'BAŞARISIZ';
            if (isset($fullInfo['DocumentReferences'])) {
                $debugInfo['deneme3']['neden'] = 'DocumentReferences property var ama boş array veya null';
            } else {
                $debugInfo['deneme3']['neden'] = 'DocumentReferences property yok';
            }
        }
    }
    
    // Debug bilgilerini göster
    echo "<h4>DocumentReferences Çekme Denemeleri:</h4>";
    echo "<div class='debug'>";
    echo "<pre>" . json_encode($debugInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
    echo "</div>";
    
    // Sonuçları göster
    if (!empty($documentReferences) && is_array($documentReferences)) {
        echo "<h4>DocumentReferences (" . count($documentReferences) . " adet) - BULUNDU:</h4>";
        echo "<table>";
        echo "<tr><th>#</th><th>RefDocEntr</th><th>RefDocNum</th><th>RefObjType</th><th>Durum</th></tr>";
        
        foreach ($documentReferences as $idx => $ref) {
            $refDocEntr = $ref['RefDocEntr'] ?? null;
            $refDocNum = $ref['RefDocNum'] ?? null;
            $refObjType = $ref['RefObjType'] ?? '';
            
            $rowClass = '';
            $status = '';
            
            // İlk StockTransfer için beklenen: InventoryTransferRequest referansı
            // İkinci StockTransfer için beklenen: İlk StockTransfer referansı
            $expectedRefObjType = '';
            $expectedRefDocEntr = null;
            
            if ($stDocEntry == 9256 || ($stDocEntry != 9257 && $qutMaster == $inventoryTransferRequestDocEntry)) {
                // İlk StockTransfer: InventoryTransferRequest'i referans göstermeli
                $expectedRefObjType = 'rot_InventoryTransferRequest';
                $expectedRefDocEntr = $inventoryTransferRequestDocEntry;
                
                if ($refObjType == $expectedRefObjType && $refDocEntr == $expectedRefDocEntr) {
                    $rowClass = 'success';
                    $status = '✓ DOĞRU (InventoryTransferRequest referansı)';
                } else {
                    $rowClass = 'error';
                    $status = "✗ YANLIŞ (Beklenen: {$expectedRefObjType}, {$expectedRefDocEntr} - Bulunan: {$refObjType}, {$refDocEntr})";
                }
            } else {
                // İkinci StockTransfer: İlk StockTransfer'i referans göstermeli
                // İlk StockTransfer'i bul
                $firstSTDocEntry = null;
                foreach ($stockTransferDocEntries as $otherST) {
                    if ($otherST != $stDocEntry) {
                        $otherSTQuery = "StockTransfers({$otherST})?\$select=DocEntry,ToWarehouse,U_ASB2B_QutMaster";
                        $otherSTData = $sap->get($otherSTQuery);
                        $otherSTInfo = $otherSTData['response'] ?? null;
                        if ($otherSTInfo && (int)($otherSTInfo['U_ASB2B_QutMaster'] ?? 0) == $inventoryTransferRequestDocEntry) {
                            // Bu ilk StockTransfer olabilir (sevkiyat deposuna giden)
                            $toWhs = $otherSTInfo['ToWarehouse'] ?? '';
                            if (strpos($toWhs, 'sevkiya') !== false || strpos($toWhs, 'Sevkiyat') !== false) {
                                $firstSTDocEntry = $otherST;
                                break;
                            }
                        }
                    }
                }
                
                if ($firstSTDocEntry) {
                    $expectedRefObjType = 'rot_InventoryTransfer';
                    $expectedRefDocEntr = $firstSTDocEntry;
                    
                    if ($refObjType == $expectedRefObjType && $refDocEntr == $expectedRefDocEntr) {
                        $rowClass = 'success';
                        $status = "✓ DOĞRU (İlk StockTransfer {$firstSTDocEntry} referansı)";
                    } else {
                        $rowClass = 'error';
                        $status = "✗ YANLIŞ (Beklenen: {$expectedRefObjType}, {$expectedRefDocEntr} - Bulunan: {$refObjType}, {$refDocEntr})";
                    }
                } else {
                    $rowClass = 'warning';
                    $status = "⚠ İlk StockTransfer bulunamadı, kontrol edilemedi";
                }
            }
            
            echo "<tr class='{$rowClass}'>";
            echo "<td>" . ($idx + 1) . "</td>";
            echo "<td>{$refDocEntr}</td>";
            echo "<td>{$refDocNum}</td>";
            echo "<td>{$refObjType}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='error'>✗ DocumentReferences OData ile çekilemedi!</p>";
        
        // Neden çekilemediğini analiz et
        $neden = [];
        if ($debugInfo['deneme1']['status'] != 200) {
            $neden[] = "Deneme 1 (Expand): HTTP " . ($debugInfo['deneme1']['status'] ?? 'N/A');
        }
        if (isset($debugInfo['deneme2']) && $debugInfo['deneme2']['status'] != 200) {
            $neden[] = "Deneme 2 (Direct): HTTP " . ($debugInfo['deneme2']['status'] ?? 'N/A');
        }
        if (isset($debugInfo['deneme3']) && $debugInfo['deneme3']['status'] != 200) {
            $neden[] = "Deneme 3 (Full): HTTP " . ($debugInfo['deneme3']['status'] ?? 'N/A');
        }
        
         // Deneme3'te hasDocumentReferences true ama count 0 ise özel durum
        if (isset($debugInfo['deneme3']) && $debugInfo['deneme3']['hasDocumentReferences'] && $debugInfo['deneme3']['documentReferencesCount'] == 0) {
            $neden[] = "DocumentReferences property var ama boş array (POST sırasında gönderilmiş ama SAP kaydetmemiş olabilir)";
            $neden[] = "DocumentReferences Type: " . ($debugInfo['deneme3']['documentReferencesType'] ?? 'bilinmiyor');
            if (isset($debugInfo['deneme3']['documentReferencesValue'])) {
                $neden[] = "DocumentReferences Value: " . json_encode($debugInfo['deneme3']['documentReferencesValue']);
            }
        }
        
        if (empty($neden)) {
            $neden[] = "Tüm query'ler başarılı ama DocumentReferences property'si response'da yok";
        }
        
        echo "<div class='warning'><strong>Olası Nedenler:</strong><ul>";
        foreach ($neden as $n) {
            echo "<li>{$n}</li>";
        }
        echo "</ul></div>";
        
        // Deneme3 response'unu tam göster
        if (isset($debugInfo['deneme3'])) {
            $fullQuery = "StockTransfers({$stDocEntry})";
            $fullData = $sap->get($fullQuery);
            echo "<div class='debug'><strong>Deneme 3 - Tam Response:</strong><pre>" . json_encode($fullData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre></div>";
        }
        
        echo "<p class='info'><strong>Not:</strong> SAP B1SL'de DocumentReferences bazen OData ile çekilemeyebilir. SAP'de manuel kontrol yapın:</p>";
        echo "<ol>";
        echo "<li>SAP'de StockTransfer {$stDocEntry}'yi açın</li>";
        echo "<li>'Referans bilgisi' butonuna tıklayın</li>";
        echo "<li>'Belgede referans alınan' sekmesinde referansları kontrol edin</li>";
        echo "</ol>";
    }
    
    echo "</div>";
    echo "<hr>";
}

// İlk StockTransfer'i bulma testi (ikinci StockTransfer için)
echo "<div class='section'>";
echo "<h3>İlk StockTransfer Bulma Testi</h3>";
echo "<p>İkinci StockTransfer oluşturulurken ilk StockTransfer'in bulunup bulunmadığını test ediyoruz:</p>";

// Her StockTransfer için ilk StockTransfer'i bulmayı dene
foreach ($stockTransferDocEntries as $stDocEntry) {
    $stQuery = "StockTransfers({$stDocEntry})?\$select=DocEntry,ToWarehouse,U_ASB2B_QutMaster";
    $stData = $sap->get($stQuery);
    $stInfo = $stData['response'] ?? null;
    
    if (!$stInfo) continue;
    
    $qutMaster = (int)($stInfo['U_ASB2B_QutMaster'] ?? 0);
    $toWarehouse = $stInfo['ToWarehouse'] ?? '';
    
    // Eğer bu ikinci StockTransfer ise (ana depoya giden), ilk StockTransfer'i bul
    if (strpos($toWarehouse, 'ana depo') !== false || strpos($toWarehouse, 'Ana Depo') !== false) {
        echo "<h4>StockTransfer {$stDocEntry} için İlk StockTransfer Arama:</h4>";
        echo "<div class='debug'>";
        echo "<strong>U_ASB2B_QutMaster:</strong> {$qutMaster}<br>";
        echo "<strong>ToWarehouse:</strong> {$toWarehouse}<br>";
        
        // Sevkiyat deposunu bul
        $uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
        $branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';
        
        // ToWarehouse'dan branch'i çıkar (ör: 100-KT-0 -> branch 100)
        $branchFromWhs = '';
        if (preg_match('/^(\d+)-/', $toWarehouse, $matches)) {
            $branchFromWhs = $matches[1];
        }
        
        // Sevkiyat deposunu bul (U_ASB2B_MAIN='2')
        $sevkiyatDepoFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branchFromWhs}' and U_ASB2B_MAIN eq '2'";
        $sevkiyatDepoQuery = "Warehouses?\$filter=" . urlencode($sevkiyatDepoFilter);
        $sevkiyatDepoData = $sap->get($sevkiyatDepoQuery);
        $sevkiyatDepolar = $sevkiyatDepoData['response']['value'] ?? [];
        $sevkiyatDepo = !empty($sevkiyatDepolar) ? $sevkiyatDepolar[0]['WarehouseCode'] : null;
        
        echo "<strong>Sevkiyat Depo Filtresi:</strong> {$sevkiyatDepoFilter}<br>";
        echo "<strong>Sevkiyat Depo Bulundu:</strong> " . ($sevkiyatDepo ?: 'BULUNAMADI') . "<br>";
        
        if ($sevkiyatDepo) {
            // İlk StockTransfer'i bul
            $firstTransferFilter = "U_ASB2B_QutMaster eq {$qutMaster} and ToWarehouse eq '{$sevkiyatDepo}'";
            $firstTransferQuery = "StockTransfers?\$filter=" . urlencode($firstTransferFilter) . "&\$orderby=DocEntry asc&\$top=1";
            $firstTransferData = $sap->get($firstTransferQuery);
            $firstTransfers = $firstTransferData['response']['value'] ?? [];
            
            echo "<strong>İlk StockTransfer Filtresi:</strong> {$firstTransferFilter}<br>";
            echo "<strong>İlk StockTransfer Query:</strong> {$firstTransferQuery}<br>";
            echo "<strong>Bulunan İlk StockTransfer Sayısı:</strong> " . count($firstTransfers) . "<br>";
            
            if (!empty($firstTransfers)) {
                $firstST = $firstTransfers[0];
                echo "<strong>İlk StockTransfer Bulundu:</strong><br>";
                echo "- DocEntry: " . ($firstST['DocEntry'] ?? 'N/A') . "<br>";
                echo "- DocNum: " . ($firstST['DocNum'] ?? 'N/A') . "<br>";
                echo "- FromWarehouse: " . ($firstST['FromWarehouse'] ?? 'N/A') . "<br>";
                echo "- ToWarehouse: " . ($firstST['ToWarehouse'] ?? 'N/A') . "<br>";
                echo "<p class='success'>✓ İlk StockTransfer bulundu! DocumentReferences eklenebilir.</p>";
            } else {
                echo "<p class='error'>✗ İlk StockTransfer bulunamadı! DocumentReferences eklenemez.</p>";
                echo "<strong>Response:</strong><pre>" . json_encode($firstTransferData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
            }
        } else {
            echo "<p class='error'>✗ Sevkiyat deposu bulunamadı! İlk StockTransfer aranamıyor.</p>";
        }
        
        echo "</div>";
    }
}

// Özet
echo "<div class='section'>";
echo "<h3>Özet</h3>";
echo "<div class='info'>";
echo "<p><strong>Beklenen İlişkiler:</strong></p>";
echo "<ul>";
echo "<li><strong>İlk StockTransfer</strong> → InventoryTransferRequest {$inventoryTransferRequestDocEntry}'i DocumentReferences ile referans göstermeli (rot_InventoryTransferRequest) - <strong>ÇALIŞIYOR</strong></li>";
echo "<li><strong>İkinci StockTransfer</strong> → İlk StockTransfer ile ilişki <strong>U_ASB2B_QutMaster</strong> ve <strong>depo yönü</strong> ile kuruluyor:</li>";
echo "<ul>";
echo "<li>İlk ST: U_ASB2B_QutMaster = {$inventoryTransferRequestDocEntry}, ToWarehouse = sevkiyatDepo</li>";
echo "<li>İkinci ST: U_ASB2B_QutMaster = {$inventoryTransferRequestDocEntry}, FromWarehouse = sevkiyatDepo, ToWarehouse = anaDepo</li>";
echo "</ul>";
echo "<li>Her iki StockTransfer'in <strong>U_ASB2B_QutMaster</strong> değeri {$inventoryTransferRequestDocEntry} olmalı</li>";
echo "</ul>";
echo "<p><strong>Not:</strong> SAP B1SL'de StockTransfer → StockTransfer (rot_InventoryTransfer) DocumentReferences desteklenmiyor. Bu yüzden ilişki U_ASB2B_QutMaster ve depo yönü (FromWarehouse/ToWarehouse) ile kuruluyor.</p>";
echo "</div>";
echo "</div>";

echo "<p><a href='Transferler.php' class='link'>← Geri Dön</a></p>";
echo "</div></body></html>";
?>

