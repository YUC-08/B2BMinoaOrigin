<?php
// fix_old_records.php
session_start();
include 'sap_connect.php';
$sap = new SAPConnect();

echo "<h2>🔄 Eski Kayıtları Dönüştürme Aracı</h2>";
echo "<p>U_AS_OWNR alanı 'KT' olan belgeler, depo koduna göre 'KT-100' veya 'KT-200' olarak güncelleniyor...</p><hr>";

// 1. Sadece düz "KT" olanları veya BOŞ olanları bul (Eski kayıtlar)
// Not: Hem 'KT' olanları hem de 'KT' ile başlayanları çekip kontrol edelim.
$query = "InventoryCountings?\$select=DocumentEntry,U_AS_OWNR&\$filter=U_AS_OWNR eq 'KT' or U_AS_OWNR eq null&\$orderby=DocumentEntry desc";

$response = $sap->get($query);
$docs = $response['value'] ?? [];

if (empty($docs)) {
    echo "<h3 style='color:green'>✅ Güncellenecek eski kayıt bulunamadı. Hepsi güncel görünüyor.</h3>";
    exit;
}

$count = 0;

foreach ($docs as $doc) {
    $docEntry = $doc['DocumentEntry'];
    $currentOwner = $doc['U_AS_OWNR'] ?? 'YOK';

    // 2. Bu belgenin satırlarını çek (Depo kodunu öğrenmek için)
    $linesData = $sap->get("InventoryCountings($docEntry)/InventoryCountingLines");
    $lines = $linesData['value'] ?? [];

    if (empty($lines)) {
        echo "Belge #$docEntry satırı yok, atlanıyor.<br>";
        continue;
    }

    // 3. İlk satırın deposuna bak
    $warehouseCode = $lines[0]['WarehouseCode']; // Örn: 100-KT-0
    $newOwner = '';

    // 4. Depo koduna göre YENİ SAHİPLİK kodunu belirle
    if (strpos($warehouseCode, '100-KT') !== false) {
        $newOwner = 'KT-100';
    } elseif (strpos($warehouseCode, '200-KT') !== false) {
        $newOwner = 'KT-200';
    } elseif (strpos($warehouseCode, '100-CF') !== false) {
        $newOwner = 'CF-100';
    } elseif (strpos($warehouseCode, '200-CF') !== false) {
        $newOwner = 'CF-200';
    } else {
        // Tanımsız bir depo ise (Örn: Merkez depo vs.)
        // Varsayılan olarak KT atayalım veya pas geçelim
        echo "Belge #$docEntry deposu ($warehouseCode) tanınmadı. Atlanıyor.<br>";
        continue;
    }

    // Eğer zaten doğruysa işlem yapma
    if ($currentOwner === $newOwner) {
        continue;
    }

    // 5. SAP'yi Güncelle
    $patchData = [
        'U_AS_OWNR' => $newOwner
    ];

    $res = $sap->patch("InventoryCountings($docEntry)", $patchData);

    if (($res['status'] ?? 0) == 204) {
        echo "Belge #$docEntry ($warehouseCode) -> <b style='color:blue'>$currentOwner</b> değerinden <b style='color:green'>$newOwner</b> değerine güncellendi. ✅<br>";
        $count++;
    } else {
        $err = $res['error']['message']['value'] ?? 'Hata';
        echo "Belge #$docEntry güncellenemedi: <span style='color:red'>$err</span> ❌<br>";
    }
    
    // Server'ı boğmamak için minik bir bekleme
    usleep(100000); // 0.1 saniye
}

echo "<hr><h3>İşlem Tamamlandı. Toplam $count belge güncellendi.</h3>";
echo "<a href='Stok.php'>Listeye Geri Dön</a>";
?>