# SAP B1SL'de Series Numaralarını Nasıl Öğrenirsiniz?

## Yöntem 1: Mevcut Bir Item'ı Sorgulayarak

Mevcut bir Item'ın Series numarasını öğrenmek için:

```
GET /b1s/v2/Items('ITEMCODE')?$select=Series,ItemName,ItemsGroupCode
```

Örnek:
- `GET /b1s/v2/Items('20008')?$select=Series,ItemName,ItemsGroupCode`
- Response'da `Series` değerini göreceksiniz

## Yöntem 2: DocumentSeriesService Kullanarak (NOT: Bu endpoint çalışmıyor olabilir)

Items için kullanılan tüm Series numaralarını listelemek için:

```
GET /b1s/v2/DocumentSeriesService_GetDocumentSeries?document=4
```

**Not:** Bu endpoint bazı SAP sistemlerinde "Service Not Found" hatası verebilir. Bu durumda Yöntem 1 veya Yöntem 3'ü kullanın.

Eğer çalışıyorsa, response örneği:
```json
{
  "value": [
    {
      "Series": 77,
      "SeriesName": "Mamül Serisi",
      "Document": 4
    },
    {
      "Series": 3,
      "SeriesName": "Yarımamül Serisi",
      "Document": 4
    }
  ]
}
```

## Yöntem 3: Items Listesinden Filtreleme

Belirli bir ItemsGroupCode'a sahip item'ları sorgulayarak Series numaralarını görebilirsiniz:

```
GET /b1s/v2/Items?$select=Series,ItemName,ItemsGroupCode&$filter=ItemsGroupCode eq 100&$top=10
```

Bu sorgu, ItemsGroupCode=100 (Mamül) olan item'ların Series numaralarını gösterir.

## UretimSO.php'de Kullanım

Kodda şu şekilde kullanılıyor:

```php
// ItemsGroupCode'a göre Seri (Series) Belirleme
// SAP'den sorgulanan değerlere göre güncellendi (2025-12-01)
$series = 77; // Varsayılan (Mamül için en yaygın)
if ($itemsGroupCode == 100) { 
    $series = 77; // Mamül serisi (SAP'den doğrulandı - en yaygın)
    // Not: Mamül için 80, 78, 74 gibi farklı Series'ler de kullanılabiliyor
} elseif ($itemsGroupCode == 101) { 
    $series = 3; // Yarımamül serisi (SAP'den doğrulandı)
} elseif ($itemsGroupCode == 104) { 
    $series = 77; // Hammadde serisi
}
```

## Önemli Notlar

1. **Series numaraları SAP sisteminize özeldir** - Her SAP kurulumunda farklı olabilir
2. **Aynı ItemsGroupCode için farklı Series'ler kullanılabilir** - Örneğin Mamül (100) için 77, 80, 78, 74 gibi farklı Series'ler görülebilir
3. **En yaygın kullanılan Series'i seçin** - Kodda en yaygın kullanılan Series değeri kullanılıyor (Mamül için 77)
4. **Yanlış Series kullanılırsa** Item oluşturulurken hata alabilirsiniz veya yanlış numaralandırma yapılabilir

## SAP Sisteminizdeki Gerçek Değerler (2025-12-01'de Doğrulandı)

- **ItemsGroupCode 100 (Mamül)**: Series 77 (en yaygın), 80, 78, 74 de kullanılıyor
- **ItemsGroupCode 101 (Yarımamül)**: Series 3
- **ItemsGroupCode 104 (Hammadde)**: Series 77 (varsayılan)

## Insomnia'da Test Etme

1. Insomnia'da yeni bir GET request oluşturun
2. URL: `https://192.168.54.185:50000/b1s/v2/Items('20008')?$select=Series,ItemName,ItemsGroupCode`
3. Headers'a `Cookie: B1SESSION=YOUR_SESSION_ID` ekleyin
4. Send'e tıklayın
5. Response'da `Series` değerini kontrol edin

Veya:

1. URL: `https://192.168.54.185:50000/b1s/v2/DocumentSeriesService_GetDocumentSeries?document=4`
2. Tüm Items Series'lerini listeleyin
3. ItemsGroupCode ile eşleştirin


## Yöntem 1: Mevcut Bir Item'ı Sorgulayarak

Mevcut bir Item'ın Series numarasını öğrenmek için:

```
GET /b1s/v2/Items('ITEMCODE')?$select=Series,ItemName,ItemsGroupCode
```

Örnek:
- `GET /b1s/v2/Items('20008')?$select=Series,ItemName,ItemsGroupCode`
- Response'da `Series` değerini göreceksiniz

## Yöntem 2: DocumentSeriesService Kullanarak (NOT: Bu endpoint çalışmıyor olabilir)

Items için kullanılan tüm Series numaralarını listelemek için:

```
GET /b1s/v2/DocumentSeriesService_GetDocumentSeries?document=4
```

**Not:** Bu endpoint bazı SAP sistemlerinde "Service Not Found" hatası verebilir. Bu durumda Yöntem 1 veya Yöntem 3'ü kullanın.

Eğer çalışıyorsa, response örneği:
```json
{
  "value": [
    {
      "Series": 77,
      "SeriesName": "Mamül Serisi",
      "Document": 4
    },
    {
      "Series": 3,
      "SeriesName": "Yarımamül Serisi",
      "Document": 4
    }
  ]
}
```

## Yöntem 3: Items Listesinden Filtreleme

Belirli bir ItemsGroupCode'a sahip item'ları sorgulayarak Series numaralarını görebilirsiniz:

```
GET /b1s/v2/Items?$select=Series,ItemName,ItemsGroupCode&$filter=ItemsGroupCode eq 100&$top=10
```

Bu sorgu, ItemsGroupCode=100 (Mamül) olan item'ların Series numaralarını gösterir.

## UretimSO.php'de Kullanım

Kodda şu şekilde kullanılıyor:

```php
// ItemsGroupCode'a göre Seri (Series) Belirleme
// SAP'den sorgulanan değerlere göre güncellendi (2025-12-01)
$series = 77; // Varsayılan (Mamül için en yaygın)
if ($itemsGroupCode == 100) { 
    $series = 77; // Mamül serisi (SAP'den doğrulandı - en yaygın)
    // Not: Mamül için 80, 78, 74 gibi farklı Series'ler de kullanılabiliyor
} elseif ($itemsGroupCode == 101) { 
    $series = 3; // Yarımamül serisi (SAP'den doğrulandı)
} elseif ($itemsGroupCode == 104) { 
    $series = 77; // Hammadde serisi
}
```

## Önemli Notlar

1. **Series numaraları SAP sisteminize özeldir** - Her SAP kurulumunda farklı olabilir
2. **Aynı ItemsGroupCode için farklı Series'ler kullanılabilir** - Örneğin Mamül (100) için 77, 80, 78, 74 gibi farklı Series'ler görülebilir
3. **En yaygın kullanılan Series'i seçin** - Kodda en yaygın kullanılan Series değeri kullanılıyor (Mamül için 77)
4. **Yanlış Series kullanılırsa** Item oluşturulurken hata alabilirsiniz veya yanlış numaralandırma yapılabilir

## SAP Sisteminizdeki Gerçek Değerler (2025-12-01'de Doğrulandı)

- **ItemsGroupCode 100 (Mamül)**: Series 77 (en yaygın), 80, 78, 74 de kullanılıyor
- **ItemsGroupCode 101 (Yarımamül)**: Series 3
- **ItemsGroupCode 104 (Hammadde)**: Series 77 (varsayılan)

## Insomnia'da Test Etme

1. Insomnia'da yeni bir GET request oluşturun
2. URL: `https://192.168.54.185:50000/b1s/v2/Items('20008')?$select=Series,ItemName,ItemsGroupCode`
3. Headers'a `Cookie: B1SESSION=YOUR_SESSION_ID` ekleyin
4. Send'e tıklayın
5. Response'da `Series` değerini kontrol edin

Veya:

1. URL: `https://192.168.54.185:50000/b1s/v2/DocumentSeriesService_GetDocumentSeries?document=4`
2. Tüm Items Series'lerini listeleyin
3. ItemsGroupCode ile eşleştirin


## Yöntem 1: Mevcut Bir Item'ı Sorgulayarak

Mevcut bir Item'ın Series numarasını öğrenmek için:

```
GET /b1s/v2/Items('ITEMCODE')?$select=Series,ItemName,ItemsGroupCode
```

Örnek:
- `GET /b1s/v2/Items('20008')?$select=Series,ItemName,ItemsGroupCode`
- Response'da `Series` değerini göreceksiniz

## Yöntem 2: DocumentSeriesService Kullanarak (NOT: Bu endpoint çalışmıyor olabilir)

Items için kullanılan tüm Series numaralarını listelemek için:

```
GET /b1s/v2/DocumentSeriesService_GetDocumentSeries?document=4
```

**Not:** Bu endpoint bazı SAP sistemlerinde "Service Not Found" hatası verebilir. Bu durumda Yöntem 1 veya Yöntem 3'ü kullanın.

Eğer çalışıyorsa, response örneği:
```json
{
  "value": [
    {
      "Series": 77,
      "SeriesName": "Mamül Serisi",
      "Document": 4
    },
    {
      "Series": 3,
      "SeriesName": "Yarımamül Serisi",
      "Document": 4
    }
  ]
}
```

## Yöntem 3: Items Listesinden Filtreleme

Belirli bir ItemsGroupCode'a sahip item'ları sorgulayarak Series numaralarını görebilirsiniz:

```
GET /b1s/v2/Items?$select=Series,ItemName,ItemsGroupCode&$filter=ItemsGroupCode eq 100&$top=10
```

Bu sorgu, ItemsGroupCode=100 (Mamül) olan item'ların Series numaralarını gösterir.

## UretimSO.php'de Kullanım

Kodda şu şekilde kullanılıyor:

```php
// ItemsGroupCode'a göre Seri (Series) Belirleme
// SAP'den sorgulanan değerlere göre güncellendi (2025-12-01)
$series = 77; // Varsayılan (Mamül için en yaygın)
if ($itemsGroupCode == 100) { 
    $series = 77; // Mamül serisi (SAP'den doğrulandı - en yaygın)
    // Not: Mamül için 80, 78, 74 gibi farklı Series'ler de kullanılabiliyor
} elseif ($itemsGroupCode == 101) { 
    $series = 3; // Yarımamül serisi (SAP'den doğrulandı)
} elseif ($itemsGroupCode == 104) { 
    $series = 77; // Hammadde serisi
}
```

## Önemli Notlar

1. **Series numaraları SAP sisteminize özeldir** - Her SAP kurulumunda farklı olabilir
2. **Aynı ItemsGroupCode için farklı Series'ler kullanılabilir** - Örneğin Mamül (100) için 77, 80, 78, 74 gibi farklı Series'ler görülebilir
3. **En yaygın kullanılan Series'i seçin** - Kodda en yaygın kullanılan Series değeri kullanılıyor (Mamül için 77)
4. **Yanlış Series kullanılırsa** Item oluşturulurken hata alabilirsiniz veya yanlış numaralandırma yapılabilir

## SAP Sisteminizdeki Gerçek Değerler (2025-12-01'de Doğrulandı)

- **ItemsGroupCode 100 (Mamül)**: Series 77 (en yaygın), 80, 78, 74 de kullanılıyor
- **ItemsGroupCode 101 (Yarımamül)**: Series 3
- **ItemsGroupCode 104 (Hammadde)**: Series 77 (varsayılan)

## Insomnia'da Test Etme

1. Insomnia'da yeni bir GET request oluşturun
2. URL: `https://192.168.54.185:50000/b1s/v2/Items('20008')?$select=Series,ItemName,ItemsGroupCode`
3. Headers'a `Cookie: B1SESSION=YOUR_SESSION_ID` ekleyin
4. Send'e tıklayın
5. Response'da `Series` değerini kontrol edin

Veya:

1. URL: `https://192.168.54.185:50000/b1s/v2/DocumentSeriesService_GetDocumentSeries?document=4`
2. Tüm Items Series'lerini listeleyin
3. ItemsGroupCode ile eşleştirin

