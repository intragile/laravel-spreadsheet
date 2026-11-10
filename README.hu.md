# Laravel Spreadsheet

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)

**Folyamatos (streaming) XLSX-, ODS- és CSV-export és vezetett import Laravelhez.** A Laravel-fejlesztők
általában két táblázatkezelő csomag közül választanak: az egyik mindent tud, de az egész munkafüzetet a
memóriába tölti, a másik gyors és takarékos, de alig van benne formázás, és nincs oszlopmegfeleltetés,
validáció vagy rekordpárosítás. Ezzel a csomaggal nem kell választani: a sorok az
[intragile/php-spreadsheet](https://github.com/intragile/php-spreadsheet) motoron folynak át, így a
memória nem nő a sorok számával, közben mégis van formázott export, sorba állítható job és validált
import. Két dolgot pedig a megszokott csomagok egyike sem ad: **automatikus oszlopmegfeleltetést** és
**rekordpárosítást** az adatbázisban már meglévő adatokkal.

[English description → README.md](README.md)

```php
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;

return Export::fromQuery(Partner::query()->where('active', true))
    ->withColumns([
        Column::make('Name'),
        Column::make('Email'),
        Column::make('Revenue', 'revenue')->format('#,##0.00'),
    ])
    ->download('partners.xlsx');
```

```php
$result = PartnerImport::from($request->file('file')->getRealPath())->run();

// $result->created, $result->updated, $result->skipped, $result->failed, $result->failedRowsPath
```

## Tartalom

- [Telepítés](#telepítés)
- [Export](#export)
- [Import](#import)
- [Oszlopmegfeleltetés és jóváhagyás](#oszlopmegfeleltetés-és-jóváhagyás)
- [Normalizálók és nyelvi adatok](#normalizálók-és-nyelvi-adatok)
- [Validáció](#validáció)
- [Hibás sorok fájlja és újratöltés](#hibás-sorok-fájlja-és-újratöltés)
- [Rekordpárosítás](#rekordpárosítás)
- [Sorba állított import, darabok és tranzakciók](#sorba-állított-import-darabok-és-tranzakciók)
- [Memória](#memória)
- [Kompatibilitás irodai alkalmazásokkal](#kompatibilitás-irodai-alkalmazásokkal)
- [Ismert korlátok](#ismert-korlátok)
- [Konfiguráció](#konfiguráció)

## Telepítés

```bash
composer require intragile/laravel-spreadsheet
```

Követelmények:

- PHP 8.3+ és Laravel 12 vagy 13.
- `ext-pdo_sqlite` (**kötelező**): import közben a már látott sorok párosítási kulcsai egy ideiglenes
  SQLite-fájlba kerülnek, így a fájlon belüli duplikátumok keresése soronként nem foglal memóriát.
- `ext-intl` (**ajánlott**): a nemzetköziesített (IDN) domainnevekhez, valamint az angoltól eltérő nyelvű
  ország- és nyelvnevekhez kell. Nélküle az ékezetek elhagyása egy beépített táblázatra támaszkodik, amely
  az európai nyelvek latin betűit lefedi.
- A core saját követelményei: `ext-zip`, `ext-xmlreader`, `ext-mbstring`, `ext-iconv`. A régi `.xls`
  feltöltések olvasásához ezen felül a `phpoffice/phpspreadsheet` 5.10-es vagy újabb változata kell (lásd a
  core README-jét).

A service provider automatikusan betöltődik. Ha a beállításokon változtatnál, publikáld a konfigurációt:

```bash
php artisan vendor:publish --tag=spreadsheet-config
```

## Export

### Láncolt (fluent) export

Az `Export::fromQuery()` Eloquent buildert vagy relációt fogad, az `Export::fromRows()` bármilyen
iterálható forrást (tömböt, generátort, `LazyCollection`-t). A formátumot a fájl kiterjesztése dönti el:
`.xlsx`, `.ods` vagy `.csv`.

```php
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;
use IntrAgile\Spreadsheet\Style;

$export = Export::fromQuery(Order::query()->where('year', 2026))
    ->withColumns([
        Column::make('Order number', 'number')->asText(),           // a "00123" szöveg marad
        Column::make('Customer', 'customer.name'),                   // data_get útvonal
        Column::make('Total', 'total')->format('#,##0.00')->width(14),
        Column::make('Ordered at', 'created_at')->format('yyyy-mm-dd'),
        Column::make('Status', fn (Order $order) => $order->status->label()),
    ])
    ->sortBy('created_at', 'desc')
    ->sheet('Orders')
    ->headerStyle(Style::make()->bold()->fill('D9E1F2'));

return $export->download('orders.xlsx');       // StreamedResponse
$path = $export->store('exports/orders.xlsx', 's3');
$rows = $export->writeTo('/tmp/orders.csv');  // helyi fájl, a sorok számát adja vissza
```

`Column::make($header, $value)`: a `$value` egy `data_get` útvonal, egy closure, amely a sort kapja, vagy
`null`, ekkor a snake_case-re alakított fejléc az útvonal (`'Email'` → `email`). Az enumokat, a
`Stringable` objektumokat, a dátumokat és a tömböket (JSON-ként) a csomag átalakítja. A `->format()`
számformátumot, a `->width()` karakterben mért szélességet, a `->style()` egy core `Style`-t állít be, az
`->asText()` pedig szövegként tárolja az értéket.

A lekérdezést **kulcs alapú lapozással** olvassa (rendezési oszlop és elsődleges kulcs, oldalanként
`export.chunk_size` sor, felülírható a `->chunkSize()`-zal), nem offsettel. Így az export közben beszúrt
sorok nem tolják el az oldalakat, és a memória nem függ az eredmény méretétől. A NULL rendezési értékű
sorok a többi után jönnek, minden adatbázison ugyanúgy. Az elsődleges kulcsot táblanévvel minősítve
hasonlítja össze, így a joinolt lekérdezések és a relációk (`BelongsToMany`, `HasManyThrough`) is helyesen
lapozódnak; explicit `select()` oszlopok nélkül csak a modell táblájának oszlopait olvassa, így egy
joinolt `id` vagy pivotoszlop nem írhatja felül a modellét. A `sortBy()` a modell egy oszlopát (ezt a
modell táblájával minősíti, ha a lekérdezés más táblát is joinol), egy `tábla.oszlop` alakot vagy egy
kiválasztott aliast fogad.

### Osztály alapú export

Örökölj az `Export` osztályból, és írd felül a `query()` (vagy `rows()`) és a `columns()` metódust. Ez a
forma sorba is állítható.

```php
use Illuminate\Database\Eloquent\Builder;
use IntrAgile\LaravelSpreadsheet\Export\Column;
use IntrAgile\LaravelSpreadsheet\Export\Export;

final class PartnersExport extends Export
{
    public function __construct(private readonly int $year) {}

    public function query(): Builder
    {
        return Partner::query()->whereYear('created_at', $this->year);
    }

    public function columns(): array
    {
        return [
            Column::make('Name'),
            Column::make('Tax number', 'tax_number')->asText(),
            Column::make('Revenue', 'revenue')->format('#,##0.00'),
        ];
    }
}

return (new PartnersExport(2026))->download('partners-2026.xlsx');
```

### Sorba állított export és események

```php
(new PartnersExport(2026))
    ->onConnection('redis')
    ->onQueue('exports')
    ->queue('exports/partners-2026.xlsx', 's3');
```

A job a diskre írja a fájlt, és `IntrAgile\LaravelSpreadsheet\Events\ExportCompleted` (`disk`, `path`,
`rows`, `exportClass`) vagy `ExportFailed` (`disk`, `path`, `exportClass`, `message`) eseményt küld. Csak
osztály alapú export állítható sorba; a láncolt export `LogicException`-t dob, mert egy query buildert,
egy generátort vagy egy oszlop-closure-t nem lehet szerializálni.

```php
use IntrAgile\LaravelSpreadsheet\Events\ExportCompleted;

Event::listen(function (ExportCompleted $event) {
    $user->notify(new ExportReady(Storage::disk($event->disk)->temporaryUrl($event->path, now()->addDay())));
});
```

### CSV Excelhez

A CSV-kimenet a `spreadsheet.export.csv` alatt állítható: `delimiter` (alapértelmezés `,`), `bom`
(alapértelmezés `false`) és `escape_formulas` (alapértelmezés `true`: a `=`, `+`, `-`, `@`, tabulátor
vagy kocsivissza karakterrel kezdődő értékek elé egy aposztróf kerül a CSV-injekció ellen). Tizedesvesszőt
használó nyelvi beállítású Excelhez állítsd be a `'delimiter' => ';'` és a `'bom' => true` értéket. Hogy
az Excel dupla kattintásra oszlopokra bontja-e az ilyen fájlt, az az operációs rendszer területi
beállításaiban megadott listaelválasztótól függ; ha nem, használd az **Adatok > Szövegből/CSV-ből**
(angolul Data > From Text/CSV) menüpontot. XLSX-nél ez a kérdés fel sem merül.

## Import

Az import egy osztály, amely leírja a célmodellt és a mezőit:

```php
use IntrAgile\LaravelSpreadsheet\Import\Field;
use IntrAgile\LaravelSpreadsheet\Import\FieldType;
use IntrAgile\LaravelSpreadsheet\Import\Import;
use IntrAgile\LaravelSpreadsheet\Matching\MatchPolicy;
use IntrAgile\LaravelSpreadsheet\Matching\MatchRule;

final class PartnerImport extends Import
{
    public function model(): string
    {
        return Partner::class;
    }

    public function fields(): array
    {
        return [
            Field::make('name')->label(['en' => 'Name', 'hu' => 'Név'])->required(),
            Field::make('email', FieldType::Email)->label(['en' => 'Email', 'hu' => 'E-mail']),
            Field::make('phone', FieldType::Phone)->synonyms('mobile', 'cell'),
            Field::make('tax_number')->label('Tax number')->sensitive(),
            Field::make('revenue', FieldType::Decimal),
            Field::make('founded_at', FieldType::Date)->column('founded_on'),
            Field::make('status', FieldType::Enum)->options([
                'active' => ['en' => 'Active', 'hu' => 'Aktív'],
                'inactive' => ['en' => 'Inactive', 'hu' => 'Inaktív'],
            ]),
        ];
    }

    public function rules(): array
    {
        return ['email' => ['email'], 'revenue' => ['nullable', 'numeric', 'min:0']];
    }

    public function matchRules(): array
    {
        return [MatchRule::sure('tax_number'), MatchRule::suspicious('name')];
    }

    public function onMatch(): MatchPolicy
    {
        return MatchPolicy::FillMissing;
    }
}
```

A `Field::make($key, $type)` a `FieldType` valamelyik esetét kapja: `Text`, `Email`, `Phone`, `Date`,
`DateTime`, `Integer`, `Decimal`, `Boolean`, `Enum`, `Country`, `Currency`, `Language`, `Domain`. A mező a
modell azonos nevű attribútumába kerül, hacsak a `->column()` nem nevez meg másikat. A `->sensitive()`
mező oszlopa üresen kerül a hibás sorok fájljába, és az értéke a hibaüzenetekben sem jelenik meg.

Futtatás:

```php
use IntrAgile\LaravelSpreadsheet\Import\ImportStatus;

$result = PartnerImport::from('uploads/partners.xlsx', 'local')   // útvonal és disk; disk nélkül helyi útvonal
    ->sheet('Partners')        // nem kötelező: index vagy név; alapból az első látható, adatot tartalmazó munkalap
    ->headerRow(3)             // nem kötelező: ha nincs megadva, a csomag felismeri
    ->chunkSize(500)           // nem kötelező: ennyi sor fut egy tranzakcióban
    ->run();

if ($result->status === ImportStatus::NeedsConfirmation) {
    // mutasd meg a felhasználónak a $result->mapping-et, lásd lent
}

$result->created;          // új rekordok
$result->updated;          // FillMissing, Overwrite vagy visszahívás alapján írt párosított rekordok
$result->skipped;          // fájlon belüli duplikátumok, Skip melletti egyezések, ellenőrzésre küldött sorok
$result->failed;           // hibás sorok
$result->failedRowsDisk;   // a hibás sorok fájljának helye (null, ha minden sor bekerült)
$result->failedRowsPath;
```

Az import osztályt a konténer hozza létre, így a konstruktor függőségei injektálódnak. XLSX-, ODS-,
CSV- (a kódolást és az elválasztót a csomag felismeri) és `phpoffice/phpspreadsheet` mellett XLS-feltöltést
is elfogad.

## Oszlopmegfeleltetés és jóváhagyás

Explicit megfeleltetés nélkül a csomag minden fájloszlopot egy mezőhöz rendel: ha van azonos
fejléc-ujjlenyomatú mentett sablon, azzal, különben a javaslattevővel. A javaslattevő normalizálja a
fejléceket (kis- és nagybetű, ékezet, szóköz, NBSP), minden fejlécet pontoz minden mező kulcsával,
címkéivel és szinonimáival szemben (pontos egyezés 100, teljes szavas tartalmazás 80, szóátfedés 60–80,
hasonló írásmód legalább 70), az első 50 mintaérték profilja alapján módosít a pontszámon (+10, ha az
értékek illenek a mező típusához, −40, ha kizárják), végül az optimális egy-az-egyhez kiosztást választja
(magyar módszer), nem mohó kiosztást. 90 pont vagy több `auto`, 60-tól 90 alatt `review`, ennél kevesebb
esetén az oszlop nincs hozzárendelve.

Ha a megfeleltetésben van `review` egyezés vagy hozzá nem rendelt kötelező mező, a `run()`
`ImportStatus::NeedsConfirmation` eredményt ad **egyetlen adatsor beolvasása előtt**, minden számláló
nullán áll. Felület nélküli (headless) folyamat:

```php
$pending = PartnerImport::from($path);

$mapping = $pending->inspect();           // ColumnMapping; adatsort nem olvas

foreach ($mapping->columns as $column) {  // ColumnMatch: column, header, field, score, confidence
    echo "{$column->header} -> ".($column->field ?? '-')." ({$column->confidence})\n";
}

$mapping->missingRequiredFields();        // ['name'], ha egyik oszlop sem a nevet tartalmazza

$confirmed = $mapping->with(3, 'phone')   // a 3. oszlop a phone mező lesz (with(3, null) törli)
    ->confirm();

$result = PartnerImport::from($path)->mapping($confirmed)->run();
```

A `ColumnMapping` megváltoztathatatlan és szerializálható (`toArray()` / `ColumnMapping::fromArray()`),
így megőrizhető a megjelenítő és a futtató kérés között. További lehetőségek:

- `->mapping([0 => 'name', 1 => 'email', 4 => 'phone'])` közvetlenül oszloppozíciókat rendel mezőkhöz; a
  kihagyott oszlopok nem töltődnek be.
- `->acceptSuggestedMapping()` a javaslatot a `review` egyezésekkel együtt elfogadja; a hozzá nem rendelt
  kötelező mezők ekkor is megállítják a futást.
- `->strict()` a státusz helyett `IntrAgile\LaravelSpreadsheet\Exceptions\MappingNeedsConfirmation`
  kivételt dob; a kivétel a `$e->mapping` mezőben hordozza a megfeleltetést.

### Megfeleltetési sablonok

A sablonok megjegyzik a jóváhagyott megfeleltetést az azonos fejlécű fájlokhoz. Kapcsold be az
adatbázis-tárolót, és publikáld a migrációját:

```php
// config/spreadsheet.php
'templates' => ['store' => 'database', 'table' => 'spreadsheet_mapping_templates'],
```

```bash
php artisan vendor:publish --tag=spreadsheet-migrations
php artisan migrate
```

```php
PartnerImport::from($path)->mapping($confirmed)->saveTemplate('Havi partnerlista')->run();
```

A sablon egy sikeresen lefutott import után mentődik. Ha egy későbbi fájl fejléc-ujjlenyomata pontosan
egyezik, a sablont a csomag automatikusan alkalmazza és jóváhagyottnak tekinti. Ha a fejléchalmaz
legalább 80%-ban hasonló (Jaccard), a sablont csak felajánlja a `$result->suggestedTemplate` mezőben. A
sablonok importonként különülnek el (`Import::key()`, alapból az osztálynév kebab-case alakja),
felhasználó vagy bérlő szerint nem: több bérlős alkalmazásban írd felül a `key()` metódust úgy, hogy a
bérlőt is tartalmazza (például `'partners:'.tenant()->id`), különben minden bérlő látja a többiek sablonjait
és fejlécneveit. A tároló a `MappingTemplateStore` interfész; saját implementáció bekötésével máshol is
tárolhatók a sablonok.

## Normalizálók és nyelvi adatok

Validáció előtt minden cella a mező típusa szerint normalizálódik:

| Típus | Mit fogad el | Mit ad vissza |
|---|---|---|
| `Date`, `DateTime` | az `import.date_formats` formátumai, perjeles dátumok, táblázatos dátumcellák | `CarbonImmutable` (`Date` esetén éjfél) |
| `Integer`, `Decimal` | `1 234,56`, `1,234.56`, `1.234,56`, NBSP-csoportosítás | `int`, illetve tizedes törtnél numerikus string (nincs lebegőpontos kerekítés) |
| `Boolean` | az `import.boolean` szavai, `1`/`0` | `bool` |
| `Enum` | opcióérték vagy címke egy beállított nyelven | az opció értéke |
| `Country` | ISO alpha-2/alpha-3 kód, angol név, alias, lokalizált név (`ext-intl` mellett) | ISO 3166-1 alpha-2 |
| `Currency` | ISO 4217 kód, `import.currency_symbols` szimbólumai, alias, angol név | ISO 4217 kód |
| `Language` | ISO 639-1/639-2 kód, angol név, alias, lokalizált név (`ext-intl` mellett) | ISO 639-1 |
| `Email` | bármilyen cím | levágott szóközök, kisbetűs domainrész |
| `Domain` | csupasz domain és URL | a hoszt `www.`, útvonal és port nélkül; IDN punycode-ra (`ext-intl` mellett) |
| `Phone`, `Text` | bármi | levágott szóközű szöveg |

Az összevetés kis- és nagybetűtől, valamint ékezettől független. A kétértelmű alakokat a csomag
oszloponként, a mintaértékek alapján dönti el: egy `03/04/2026` jellegű perjeles dátum azt a nap/hónap
sorrendet követi, amelyet az oszlop többi értéke bizonyít, az `1.234` pedig az oszlop tizedesjelét. Ha az
oszlop nem dönti el, a sor hibaüzenetet kap, a csomag nem találgat. A szimbólumból felismert pénznem
figyelmeztetést kap, mert például a `$` több pénznemé is.

A nyelvi adatok a konfigurációban vannak, nem a kódban:

```php
'import' => [
    'locales' => ['hu', 'en'],                 // ezeken a nyelveken fogad el ország-, nyelv- és enumcímkéket
    'date_formats' => ['Y-m-d', 'Y.m.d.', 'd.m.Y'],
    'boolean' => ['true' => ['yes', 'igen'], 'false' => ['no', 'nem']],
    'synonyms' => ['phone' => ['hu' => ['telefonszám', 'mobil'], '*' => ['tel']]],
    'currency_symbols' => ['Ft' => 'HUF', '€' => 'EUR'],
    'aliases' => ['country' => ['holland' => 'NL'], 'currency' => ['forint' => 'HUF'], 'language' => []],
],
```

A `synonyms` mezőkulcsonként és nyelvenként bővíti a megfeleltető által kipróbált fejlécneveket (a `*`
minden nyelvre érvényes). Egyetlen mező normalizálóját egy `Normalizer` példánnyal vagy closure-rel
cserélheted le:

```php
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizationContext;
use IntrAgile\LaravelSpreadsheet\Normalization\NormalizedValue;

Field::make('vat_rate', FieldType::Decimal)->normalizeUsing(
    fn (mixed $value, Field $field, NormalizationContext $context): NormalizedValue => is_numeric($value)
        ? NormalizedValue::ok((string) $value)
        : NormalizedValue::error('validation.numeric', ['attribute' => 'vat_rate']),
);
```

Egy teljes típus alapértelmezését az `app(NormalizerRegistry::class)->extend(FieldType::Phone, new MyPhoneNormalizer)`
hívás cseréli. Egy mező címkéi, szinonimái és opciói a `labels()`, `synonymList()` és `optionMap()`
metódussal kérdezhetők le.

## Validáció

A normalizált sorokat a Laravel validátora ellenőrzi, soronként. A `rules()` az új rekordokra, az
`updateRules()` (alapból a `rules()`) a párosított rekordokra vonatkozik, így egy részleges frissítésnek
nem kell minden kötelező oszlopot tartalmaznia. Egy mezőn a `->required()` létrehozáskor `required`
szabályt ad hozzá. A hibás sor a lefordított üzenetekkel a hibás sorok fájljába kerül, az import pedig
megy tovább.

## Hibás sorok fájlja és újratöltés

A be nem töltött sorok (hibák, fájlon belüli duplikátumok, ellenőrzésre küldött sorok) a feltöltés
formátumában (XLS-feltöltésnél XLSX-ben) folyamatosan egy fájlba íródnak: az eredeti fejlécekkel és
cellákkal, plusz két oszloppal, ezek az `Import status` (`Error`, `Skipped`, `Needs review`) és az
`Import message`. Az érzékeny mezők üresen maradnak. A fájl a `failed_rows.disk` diskre, a
`failed_rows.directory` mappába kerül, és csak akkor jön létre, ha legalább egy ilyen sor van.

A CSV formátumú hibás sorok fájlja a feltöltés elválasztójelét használja, és UTF-8 kódolással, bájtsorrend-
jelzővel (BOM) íródik, így egy Windows-1250 kódolású feltöltés is helyes ékezetekkel jön vissza. Az olyan
szöveg, amelyet egy táblázatkezelő képletként futtatna (`=`, `+`, `-`, `@`, tabulátor vagy kocsivissza az
elején), aposztróf előtagot kap, kivéve ha előjeles szám vagy telefonszám: egy előjel, amelyet csak
számjegyek, szóközök és `. , ( ) / -` követnek (például `-12,5` vagy `+36 1 234 5678`), nem hívhat
függvényt és nem hivatkozhat cellára, ezért változatlan marad. Az aposztróf a cella szövegének része: ha a
felhasználó eltávolítás nélkül tölti fel újra a fájlt, az aposztróf is betöltődik.

A felhasználó kijavítja a sorokat, és ugyanazt a fájlt tölti fel újra: a státusz- és az üzenetoszlop nem
számít bele a fejléc-ujjlenyomatba, így a mentett sablon ekkor is érvényes, és a két plusz oszlop nem
töltődik be. Ha a felhasználók átnevezik ezeket a fejléceket, a további szövegeket a
`failed_rows.headers` alatt sorold fel. A státusz- és az üzenetfejléc fordítható (`spreadsheet::messages`;
angol és magyar fordítás jár a csomaggal).

## Rekordpárosítás

A `matchRules()` adja vissza azokat a szabályokat, amelyek eldöntik, hogy egy sor egy már meglévő
rekord-e. Minden szabály egy vagy több mezőt sorol fel, ezek együtt adnak egy kulcsot; az első egyező
szabály dönt.

- `MatchRule::sure('tax_number')`: az egyezés ugyanaz a rekord; hogy mi történik, azt az `onMatch()`
  szabálya dönti el.
- `MatchRule::suspicious('name', 'city')`: az egyezés talán ugyanaz a rekord; a sor `Needs review`
  státusszal a hibás sorok fájljába kerül, és semmi sem íródik.

Ugyanezek a szabályok a **fájlon belüli duplikátumokat** is elkapják: ha egy sor kulcsát a fájl egy
korábbi sora már létrehozta, a sor `Skipped` (biztos szabály) vagy `Needs review` (gyanús szabály) lesz,
darabokon át is.

### Kanonizálók

Az értékek kanonizálás után hasonlítódnak össze; a kanonizálót a mező típusa adja, vagy szabályonként
állítható:

| Név | Összevetési kulcs | Alapértelmezés ennél |
|---|---|---|
| `email` | levágott szóközök, kisbetű | `Email` |
| `phone` | csak számjegyek | `Phone` |
| `domain` | hoszt `www.`, útvonal és port nélkül, kisbetű, IDN punycode-ra | `Domain` |
| `tax_number` | csak kisbetűk és számjegyek | (szabályonként állítsd be) |
| `text` | ékezetek nélkül, kisbetű, összevont szóközök | minden más típus |

```php
MatchRule::sure('tax')->canonicalizeUsing('tax', 'tax_number');
MatchRule::sure('sku')->canonicalizeUsing('sku', new SkuCanonicalizer);

app(CanonicalizerRegistry::class)->extend('sku', new SkuCanonicalizer);   // regisztrálás név alapján
```

Egy `Canonicalizer` két metódusból áll: `canonicalize($value): ?string` és
`prefilter($query, $column, $keys, $rawValues)`. A csomag soha nem olvassa végig a teljes táblát:
szabályonként és sorkötegenként a `prefilter` egy hordozható feltétellel (csak `LOWER`, `TRIM`,
`REPLACE`) szűkíti a lekérdezést, a jelölteket pedig PHP-ban újra kanonizálja és pontosan összeveti.
Minden kulcs és nyers érték egy-egy lekérdezési paraméter (binding), ezért a kötegek legfeljebb
`import.lookup_batch` sorból (alapból 500) és lekérdezésenként legfeljebb 1800 bindingből állnak, így
minden lekérdezés az SQL Server 2100 paraméteres korlátja alatt marad. Egy saját kanonizálónak is ezen a
kereten belül kell maradnia.

### Egyezéskor alkalmazott szabály

Az `onMatch()` dönti el, mi történjen egy biztos szabállyal megtalált rekorddal:

| Szabály | Hatás |
|---|---|
| `MatchPolicy::Skip` (alapértelmezés) | semmi sem íródik; a sor kihagyottnak számít |
| `MatchPolicy::FillMissing` | csak a rekord üres attribútumai töltődnek ki |
| `MatchPolicy::Overwrite` | a betöltött értékek felülírják a rekordot; üres cella a tárolt értéket megtartja |
| closure | `fn (Model $existing, array $values): ?array` a kiírandó attribútumokat adja vissza; `null` esetén a sor kimarad |

A frissítéseket az `updateRules()` validálja. `Overwrite` mellett üres cella soha nem töröl attribútumot;
ha az üres cellák törölték volna az értéket, használj closure-szabályt, vagy írd felül a
`fill(Model $record, array $values, bool $exists)` metódust, amely egyébként is teljes kontrollt ad.

### Kukázott (soft-deleted) rekordok

A `SoftDeletes`-t használó modelleknél a párosítás a kukázott rekordokat is figyelembe veszi. Egy kukázott
rekorddal egyező sort a csomag soha nem támaszt fel: a sor hibaként ("Matches a deleted record") kerül a
hibás sorok fájljába.

### Gyerekrekordok

A `saved(Model $record, array $values, bool $created)` minden rekord mentése után, a sor savepointján
belül fut, így a gyerekrekordok ugyanabban a tranzakcióban fésülhetők össze. A `ChildMerger` a gyakori
esetet oldja meg: egy meglévő gyereket kulcsok prioritási listája alapján párosít, és csak az üres
attribútumait tölti ki, különben új gyereket hoz létre.

```php
use IntrAgile\LaravelSpreadsheet\Matching\ChildMerger;

public function saved(Model $record, array $values, bool $created): void
{
    (new ChildMerger)->merge(
        $record->contacts(),
        ['name' => $values['name'] ?? null, 'email' => $values['email'] ?? null, 'phone' => $values['phone'] ?? null],
        ['email' => 'email', 'phone' => 'phone'],    // attribútum => kanonizáló, a legfontosabb elöl
    );
}
```

Az eredmény `ChildMergeOutcome::Added`, `Filled` vagy `Unchanged`. Egy fájl több sora is frissítheti
ugyanazt a meglévő rekordot, így egy kapcsolattartónként egy soros fájl minden kapcsolattartót egyetlen
szülőrekordhoz fésül.

### Saját párosító

Az alapértelmezett `DatabaseRecordMatcher` a modell attribútumait veti össze. Ha accessorokon,
kapcsolatokon vagy külső indexen át kell párosítani, írd felül a `matcher()` metódust, és adj vissza
saját `RecordMatcher`-t; ennek `match(Import $import, array $valuesByRow): array` metódusa egy darabot
kap, és párosított soronként egy `MatchResult`-ot ad vissza.

## Sorba állított import, darabok és tranzakciók

```php
PartnerImport::from('uploads/partners.xlsx', 's3')
    ->mapping($confirmed)
    ->onConnection('redis')
    ->onQueue('imports')
    ->queue();
```

A job `IntrAgile\LaravelSpreadsheet\Events\ImportCompleted` (`result`, `importClass`) vagy `ImportFailed`
(`importClass`, `message`) eseményt küld. Az import objektum a jobbal együtt szerializálódik, ezért a
tulajdonságaiban ne tárolj closure-t (az `onMatch()` metóduson belül hozd létre). A workernek el kell érnie a fájlt, ezért a sorba állított
import általában közös diskről olvas. A jóváhagyásra váró megfeleltetés `ImportStatus::NeedsConfirmation`
eredménnyel zárul; `->strict()` mellett hibával. Az adatbázis hibaüzeneteit egy általános, lefordított
szöveg váltja fel, hacsak az `app.debug` nincs bekapcsolva.

A sorok darabonként (`import.chunk_size`, alapból 500) egy tranzakcióban íródnak, soronként egy
savepointtal, így egy hibás sor egyedül görgetődik vissza, és hibás sorrá válik. Ha az adatbázis egy
darabot deadlock vagy más párhuzamossági hiba miatt megszakít, a darab egyszer, elölről újrafut; egy
második ilyen hiba leállítja az importot.

Az import job egyszer fut (`$tries = 1`). A worker `--timeout` értékét (vagy a job `$timeout`
tulajdonságát) és a queue-kapcsolat `retry_after` értékét állítsd a leghosszabb várható importnál
nagyobbra; különben a worker az import közepén leállítja a jobot, vagy a queue egy második workernek is
kiadja, miközben az első még fut. Az import közben leállított job az addig véglegesített darabokat az
adatbázisban hagyja, csak a folyamatban lévő darab görgetődik vissza; hibás sorok fájlja nem mentődik, az ideiglenes fájljai
pedig a rendszer ideiglenes mappájában maradnak. Ha
az importnak van biztos párosítási szabálya, ugyanannak a fájlnak az újrafuttatása biztonságos: a már
beírt sorok ekkor a rekordjukkal párosulnak, nem jönnek létre másodszor.

## Memória

Sem az export, sem az import memóriaigénye nem nő a sorok számával. A tesztkészlet ezt ellenőrzi: minden
memóriateszt N és 10×N sornál méri a csúcsmemória növekedését, és elbukik, ha a különbség eléri a 8 MB-ot.

- **Import** (`ImportMemoryTest`, CSV 10 000 és 100 000 sorral, a sorok fele hibás, így a hibás sorok
  fájlja is íródik): a csúcsmemória növekedése **10 ezer sornál 1 036 064 bájt**, **100 ezer sornál
  1 048 040 bájt** volt, a különbség nagyjából 12 KB.
- **Export** (`ExportMemoryTest`, 10 000 és 100 000 sor egy fájl alapú SQLite-táblából, kulcs alapú
  lapozással, XLSX-be): ugyanazzal a 8 MB-os szabállyal ellenőrizve.

A fájlon belüli duplikátumkulcsok egy ideiglenes SQLite-fájlban élnek, nem egy tömbben. A core saját
számaihoz és méréseihez lásd a [php-spreadsheet README-jét](https://github.com/intragile/php-spreadsheet).

## Kompatibilitás irodai alkalmazásokkal

A kiadás előtt az exportokat és a hibás sorok fájljait macOS-en megnyitottuk az Excel for Mac, a
LibreOffice és a Numbers alkalmazásban (a teljes eredmény a
[tests/office/README.md](tests/office/README.md) fájlban):

- Az XLSX mindhárom alkalmazásban javítási kérdés nélkül megnyílt; az ékezetek, a `#,##0.00` és a
  `yyyy-mm-dd` formátum és a szövegként tárolt kódok (`00123`) mindháromban rendben voltak.
- Az XLSX rögzített fejlécsorát az Excel és a LibreOffice mutatja (a Numbers nem jelzett ilyet); a
  fejléc automatikus szűrője az Excelben és a LibreOffice-ban XLSX-nél és ODS-nél is be van kapcsolva.
- A félkövér, kitöltött fejlécet az Excelben ellenőriztük XLSX-nél és ODS-nél (a másik kettőben nem
  vizsgáltuk).
- Az ODS az Excelben és a LibreOffice-ban megnyílt; a Numbers egyáltalán nem nyit meg ODS-t.
- A `;`-vel tagolt magyar CSV-t a Numbers oszlopokra bontotta; az Excel a tesztgép területi
  beállításaival nem (lásd lent), a LibreOffice-ban pedig nem ellenőriztük (a szövegimportáló ablak
  megakasztotta az automatikus vizsgálatot).

## Ismert korlátok

- **`;`-vel tagolt CSV az Excelben:** hogy az Excel dupla kattintásra oszlopokra bontja-e, az operációs
  rendszer listaelválasztójától függ. Ha nem, az **Adatok > Szövegből/CSV-ből** (Data > From Text/CSV)
  menüponttal töltsd be. A
  Numbers helyesen bontja. A CSV nem hordoz cellatípust, ezért egy alkalmazás a `00123`-at számmá
  alakíthatja (a Numbers ezt tette); ha a kódoknak szövegnek kell maradniuk, használj XLSX-et.
- **ODS:** az Excel nem veszi figyelembe az ODS-fájlok rögzített fejlécét, a Numbers pedig egyáltalán nem
  nyit meg ODS-t. Mindkettő az alkalmazások korlátja; a LibreOffice mindkettőt helyesen mutatja.
- **A sorba állított import szerializálódik:** a job az import objektumot szerializálja, ezért nem
  állítható sorba olyan import osztály, amely egy tulajdonságában closure-t (vagy más nem szerializálható
  értéket) tárol. Az `onMatch()` metóduson belül létrehozott closure-szabály rendben van, mert az a
  workeren jön létre.
- **Deadlock utáni újrapróbálás:** a darab egyszer fut újra, és csak akkor van értelme, ha a legkülső
  tranzakció az importé. Egy a saját kódod által nyitott tranzakción belül az újrapróbálás egy olyan
  tranzakcióban futna, amelyet az adatbázis már megszakított.
- **A rekordpárosítás előszűrője:** a bindingek kötegekre bomlanak, hogy az SQL Server 2100 paraméteres
  korlátja alatt maradjanak; a saját kanonizálóknak is ehhez a kerethez kell igazodniuk.
- **XLS-feltöltés:** ezt a `phpoffice/phpspreadsheet` minden menetben egészben a memóriába olvassa:
  a vizsgálatkor, a fejléc és a mintasorok beolvasásakor és magánál az importnál. Előtte a cellaszámot
  az `import.max_cells` értékével veti össze (alapból 2 000 000; ez az ODS ismételt celláinak és sorainak
  kibontását is korlátozza); a nagyobb fájl `LimitExceeded` kivétellel, betöltés előtt elbukik. Minden
  más formátum folyamatosan olvasódik.
- Az `ext-pdo_sqlite` kötelező; `ext-intl` nélkül az IDN domaineket és a nem angol ország- és nyelvneveket
  a csomag nem ismeri fel, az ékezetek elhagyása pedig a beépített táblázattal történik.

## Konfiguráció

`config/spreadsheet.php` (publikálás: `--tag=spreadsheet-config`):

| Kulcs | Alapértelmezés | Jelentés |
|---|---|---|
| `export.chunk_size` | 1000 | sorok száma lapozási oldalanként |
| `export.header_style` | félkövér, `DDDDDD` kitöltés | fejlécstílus: `bold`, `italic`, `wrap`, `fill`, `font_color`, `align`, `border` |
| `export.csv.delimiter` / `bom` / `escape_formulas` | `,` / `false` / `true` | CSV-kimenet |
| `import.chunk_size` | 500 | sorok száma tranzakciónként |
| `import.lookup_batch` | 500 | sorok száma meglévő rekordokat kereső lekérdezésenként |
| `import.max_cells` | 2 000 000 | egy XLS-munkafüzet (vagy kibontott ODS-munkalap) legnagyobb elfogadott cellaszáma |
| `import.locales` | `['en']` | ezeken a nyelveken fogad el neveket |
| `import.date_formats` | ISO és magyar/német elrendezések | egyértelmű dátumformátumok, sorrendben kipróbálva |
| `import.boolean` | angol, magyar, német, spanyol szavak | igaznak és hamisnak olvasott szavak |
| `import.synonyms` | gyakori mezők angolul, magyarul, németül, spanyolul | további fejlécnevek mezőkulcsonként |
| `import.currency_symbols` | `Ft`, `€`, `$`, `£` | szimbólum → ISO 4217 |
| `import.aliases` | ország- és pénznem-aliasok | további nevek országra, pénznemre, nyelvre |
| `failed_rows.disk` / `directory` | alapértelmezett disk / `spreadsheet/failed-rows` | a hibás sorok fájljainak helye |
| `failed_rows.headers` | `[]` | további státusz- és üzenetfejléc-szövegek |
| `templates.store` / `table` | `null` / `spreadsheet_mapping_templates` | a `'database'` érték bekapcsolja a sablontárolót |

## Fejlesztés

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## Licenc

MIT. Lásd: [LICENSE](LICENSE).

---

Fejlesztő és karbantartó: [IntrAgile Kft.](https://intragile.eu)
