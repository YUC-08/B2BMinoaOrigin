# Zayi Depo Sorgusu - Insomnia Test Kodu

## 1. Login (Session almak için)
**Method:** POST  
**URL:** `https://192.168.54.185:50000/b1s/v2/Login`  
**Headers:**
```
Content-Type: application/json
```
**Body:**
```json
{
  "CompanyDB": "SBODEMOUS",
  "UserName": "manager",
  "Password": "1234"
}
```
**Response'dan:** `SessionId` değerini al, Cookie olarak kullan.

---

## 2. Zayi Depoları (U_ASB2B_MAIN eq '4')
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/Warehouses`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$select=WarehouseCode,WarehouseName
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100' and U_ASB2B_MAIN eq '4'
$orderby=WarehouseCode
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode%2CWarehouseName&$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_BRAN%20eq%20%27100%27%20and%20U_ASB2B_MAIN%20eq%20%274%27&$orderby=WarehouseCode
```

**Açıklama:**
- Zayi depoları için `U_ASB2B_MAIN = '4'` olan depoları getirir
- `U_AS_OWNR = 'KT'` ve `U_ASB2B_BRAN = '100'` ile filtreler
- Expand kullanılmaz, direkt `Warehouses` endpoint'i kullanılır

**NOT:** Eğer session'ınızda farklı `U_AS_OWNR` veya `U_ASB2B_BRAN` değerleri varsa, bunları değiştirin:
- `U_AS_OWNR`: Session'dan gelen değer (örn: 'KT')
- `U_ASB2B_BRAN`: Session'dan gelen branch değeri (örn: '100')

---

## Beklenen Response Formatı

**Başarılı Response (200 OK):**
```json
{
  "@odata.context": "https://192.168.54.185:50000/b1s/v2/$metadata#Warehouses",
  "value": [
    {
      "WarehouseCode": "100-KT-3",
      "WarehouseName": "Zayi Deposu"
    }
  ]
}
```

**Eğer depo bulunamazsa:**
```json
{
  "@odata.context": "https://192.168.54.185:50000/b1s/v2/$metadata#Warehouses",
  "value": []
}
```

**Hata Response (örnek):**
```json
{
  "error": {
    "code": "-5002",
    "message": {
      "lang": "en-us",
      "value": "Invalid filter expression"
    }
  }
}
```

---

---

## 3. Tüm Depoları Listele (Zayi Deposunu Bulmak İçin)
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/Warehouses`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$select=WarehouseCode,WarehouseName,U_ASB2B_MAIN,U_ASB2B_BRAN,U_AS_OWNR
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100'
$orderby=WarehouseCode
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode%2CWarehouseName%2CU_ASB2B_MAIN%2CU_ASB2B_BRAN%2CU_AS_OWNR&$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_BRAN%20eq%20%27100%27&$orderby=WarehouseCode
```

**Açıklama:**
- Bu sorgu, belirtilen `U_AS_OWNR` ve `U_ASB2B_BRAN` değerlerine sahip TÜM depoları getirir
- `U_ASB2B_MAIN` değerini gösterir, böylece Zayi deposunun hangi `U_ASB2B_MAIN` değerine sahip olduğunu görebilirsiniz
- Response'da `WarehouseCode` içinde "zayi" veya "Zayi" geçen depoyu arayın veya `U_ASB2B_MAIN` değerlerini kontrol edin

---

## Test Adımları

1. Login yapın ve `B1SESSION` cookie'sini alın
2. Yukarıdaki Zayi deposu sorgusunu çalıştırın
3. Eğer `value` array'i boşsa (`[]`), "Tüm Depoları Listele" sorgusunu çalıştırın
4. Response'da Zayi deposunu bulun:
   - `WarehouseCode` içinde "zayi" veya "Zayi" geçen depo
   - Veya `U_ASB2B_MAIN` değerini kontrol edin (belki '4' değil, başka bir değer)
5. Bulduğunuz Zayi deposunun `WarehouseCode`, `WarehouseName` ve `U_ASB2B_MAIN` değerlerini bana bildirin

