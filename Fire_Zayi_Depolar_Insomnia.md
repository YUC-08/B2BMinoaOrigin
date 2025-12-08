# Insomnia Requests - Fire/Zayi Depoları

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

## 2. Fire Depoları (U_ASB2B_MAIN eq '3')
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
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100' and U_ASB2B_MAIN eq '3'
$orderby=WarehouseCode
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode%2CWarehouseName&$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_BRAN%20eq%20%27100%27%20and%20U_ASB2B_MAIN%20eq%20%273%27&$orderby=WarehouseCode
```

**Açıklama:**
- Fire depoları için `U_ASB2B_MAIN = '3'` olan depoları getirir
- `U_AS_OWNR = 'KT'` ve `U_ASB2B_BRAN = '100'` ile filtreler
- Expand kullanılmaz, direkt `Warehouses` endpoint'i kullanılır

---

## 3. Zayi Depoları (U_ASB2B_MAIN eq '4')
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

---

## 4. Tüm Fire/Zayi Depoları (Her İkisi Birden)
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
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100' and (U_ASB2B_MAIN eq '3' or U_ASB2B_MAIN eq '4')
$orderby=WarehouseCode
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode%2CWarehouseName&$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_BRAN%20eq%20%27100%27%20and%20%28U_ASB2B_MAIN%20eq%20%273%27%20or%20U_ASB2B_MAIN%20eq%20%274%27%29&$orderby=WarehouseCode
```

**Açıklama:**
- Hem Fire (MAIN=3) hem Zayi (MAIN=4) depolarını birlikte getirir
- Sadece WarehouseCode ve WarehouseName döner

---

## 5. Çıkış Depoları (Ana Depo - U_ASB2B_MAIN eq '1' or '2')
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
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100' and (U_ASB2B_MAIN eq '1' or U_ASB2B_MAIN eq '2')
$orderby=WarehouseCode
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode%2CWarehouseName&$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_BRAN%20eq%20%27100%27%20and%20%28U_ASB2B_MAIN%20eq%20%271%27%20or%20U_ASB2B_MAIN%20eq%20%272%27%29&$orderby=WarehouseCode
```

**Açıklama:**
- Fire/Zayi belgesi oluştururken çıkış deposu olarak kullanılacak depolar
- `U_ASB2B_MAIN = '1'` (Ana Depo) veya `U_ASB2B_MAIN = '2'` (Sevkiyat Deposu)

---

## Beklenen Response Formatı

**Başarılı Response (200 OK):**
```json
{
  "@odata.context": "https://192.168.54.185:50000/b1s/v2/$metadata#Warehouses",
  "value": [
    {
      "WarehouseCode": "100-KT-2",
      "WarehouseName": "Fire Deposu"
    },
    {
      "WarehouseCode": "100-KT-3",
      "WarehouseName": "Zayi Deposu"
    }
  ]
}
```

**Sadece WarehouseCode ve WarehouseName döner, başka alan yok.**

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

## Notlar

1. **Session Yönetimi:** Her request'te `B1SESSION` cookie'sini göndermeniz gerekir
2. **Filter Değerleri:**
   - `U_AS_OWNR`: Sahip kodu (örn: 'KT')
   - `U_ASB2B_BRAN`: Şube kodu (örn: '100')
   - `U_ASB2B_MAIN`: Depo tipi
     - `'1'` = Ana Depo
     - `'2'` = Sevkiyat Deposu
     - `'3'` = Fire Deposu
     - `'4'` = Zayi Deposu
3. **Expand Kullanımı:** Bu endpoint'lerde expand kullanılmaz, direkt `Warehouses` collection'ı sorgulanır
4. **URL Encoding:** Query parametrelerinde özel karakterler (boşluk, tek tırnak vb.) URL encode edilmelidir

