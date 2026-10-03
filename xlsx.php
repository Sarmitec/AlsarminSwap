<?php
declare(strict_types=1);

const IMPORT_MAX_ROWS = 30000;
const IMPORT_MAX_COLUMNS = 256;
const IMPORT_MAX_ENTRY_BYTES = 31457280; // 30MB بعد فك الضغط

function xml_text(string $value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/**
 * يطبّع نص عنوان عمود (Header) لأغراض المطابقة فقط: يزيل التشكيل
 * والفواصل الشائعة، يوحّد الألف والتاء المربوطة والياء، ويحوّل كل
 * شيء لحروف صغيرة بلا مسافات زائدة. هذا يسمح للبرنامج بالتعرّف على
 * نفس العمود حتى لو اختلفت صياغته قليلًا بين ملف وآخر.
 */
function normalized_header(string $value): string
{
    $value = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}]/u', '', $value) ?? $value;

    $value = str_replace(['أ', 'إ', 'آ'], 'ا', $value);
    $value = str_replace('ة', 'ه', $value);
    $value = str_replace('ى', 'ي', $value);
    $value = preg_replace('/[\.,:؛\-_\/\\\\#()]+/u', ' ', $value) ?? $value;
    $value = mb_strtolower($value, 'UTF-8');

    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

/**
 * قائمة الأسماء البديلة المقبولة لكل عمود إلزامي. أي عمود في الملف
 * يطابق أحد هذه الأسماء (بعد التطبيع، تطابقًا تامًا أو احتواءً) يُعتبر
 * ذلك العمود تلقائيًا.
 */
function import_column_aliases(): array
{
    return [
        'university_id' => [
            'الرقم الجامعي', 'الرقم الجامعى', 'رقم الطالب', 'الرقم التسلسلي',
            'رقم القيد', 'الرقم الوطني', 'رقم الهوية', 'university id',
            'university_id', 'student id', 'student number', 'id', 'no', 'رقم',
        ],
        'full_name' => [
            'الاسم الثلاثي', 'الاسم الرباعي', 'اسم الطالب', 'الاسم الكامل',
            'الاسم', 'full name', 'student name', 'name',
        ],
        'category' => [
            'الفئة الحالية', 'الفئة الأصلية', 'الفئة', 'الشعبة', 'المجموعة',
            'القسم', 'الصف', 'current category', 'category', 'section', 'group',
        ],
    ];
}

function import_normalized_aliases(): array
{
    static $normalized = null;

    if ($normalized === null) {
        $normalized = array_map(
            fn(array $names): array => array_map('normalized_header', $names),
            import_column_aliases()
        );
    }

    return $normalized;
}

/**
 * يفحص مدى تطابق مجموعة عناوين أعمدة (بعد تطبيعها) مع كل فئة من
 * الفئات الإلزامية الثلاث، ويبني خريطة عمود => فهرس. يقبل تطابقًا
 * تامًا أو احتواء العنوان لاسم بديل داخل نص أطول (لمرونة أكبر مع
 * ملفات فيها صياغات غير متوقعة، مثل "الرقم الجامعي للطالب").
 */
function match_header_columns(array $headers): array
{
    $map = [];

    foreach (import_normalized_aliases() as $key => $names) {
        $bestColumn = null;
        $bestIsExact = false;

        foreach ($headers as $column => $header) {
            if ($header === '') {
                continue;
            }

            $exact = in_array($header, $names, true);
            $partial = !$exact && array_any($names, fn(string $name): bool => $name !== '' && str_contains($header, $name));

            if ($exact) {
                $bestColumn = $column;
                $bestIsExact = true;
                break;
            }

            if ($partial && $bestColumn === null) {
                $bestColumn = $column;
            }
        }

        if ($bestColumn !== null) {
            $map[$key] = $bestColumn;
        }

        unset($bestIsExact);
    }

    return $map;
}

if (!function_exists('array_any')) {
    function array_any(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * ينظّف قيمة خلية واحدة: يزيل BOM والمسافات الطرفية، ويعالج حالة
 * الأرقام الجامعية التي يحوّلها Excel لصيغة علمية أو عشرية (مثل
 * "1.23E+17" أو "12345.0") فيعيدها لصيغة رقم صحيح عادي بدون فقدان دقة.
 */
function xlsx_clean_value(mixed $value, bool $universityId = false): string
{
    $value = trim(str_replace("\xEF\xBB\xBF", '', (string) $value));

    if ($universityId && $value !== '' && is_numeric($value)) {
        $float = (float) $value;

        if ($float == (int) $float || str_contains(strtolower($value), 'e')) {
            $value = sprintf('%.0f', $float);
        }
    }

    return $value;
}

function xlsx_load_xml(string $xml): SimpleXMLElement
{
    if (str_starts_with($xml, "\xFF\xFE") || str_starts_with($xml, "\xFE\xFF")
        || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new RuntimeException('ملف Excel يحتوي على محتوى غير مسموح به.');
    }

    $previous = libxml_use_internal_errors(true);
    $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($element === false) {
        throw new RuntimeException('ملف Excel تالف أو بصيغة غير مدعومة.');
    }

    return $element;
}

function xlsx_sheet_rows(SimpleXMLElement $sheet, array $shared): array
{
    $rows = [];

    foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
        $values = [];

        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            preg_match('/([A-Z]+)/', (string) $cell['r'], $match);
            $column = xlsx_column_index($match[1] ?? 'A');

            if ($column >= IMPORT_MAX_COLUMNS) {
                continue;
            }

            $value = (string) $cell->v;

            if ((string) $cell['t'] === 's') {
                $value = $shared[(int) $value] ?? '';
            }

            if ((string) $cell['t'] === 'inlineStr') {
                $textNodes = $cell->is->xpath('.//*[local-name()="t"]') ?: [];
                $value = implode('', array_map('strval', $textNodes));
            }

            $values[$column] = $value;
        }

        if ($values) {
            $max = max(array_keys($values));
            $normalized = array_fill(0, $max + 1, '');

            foreach ($values as $column => $value) {
                $normalized[$column] = $value;
            }

            if (count($rows) >= IMPORT_MAX_ROWS) {
                throw new RuntimeException('عدد صفوف الملف يتجاوز الحد المسموح (' . IMPORT_MAX_ROWS . ' صف).');
            }

            $rows[] = ['number' => (int) ($row['r'] ?: count($rows) + 1), 'values' => $normalized];
        }
    }

    return $rows;
}

function xlsx_read_entry(ZipArchive $zip, string $name): string|false
{
    $stat = $zip->statName($name);

    if ($stat === false) {
        return false;
    }

    if ((int) $stat['size'] > IMPORT_MAX_ENTRY_BYTES) {
        throw new RuntimeException('ملف Excel كبير جدًا بعد فك الضغط.');
    }

    return $zip->getFromName($name);
}

/**
 * يفتح ملف xlsx ويستخرج كل الصفوف الخام من أفضل ورقة عمل متاحة
 * (الورقة التي يوجد فيها صف يطابق الأعمدة الإلزامية الثلاث كاملة،
 * وإلا أول ورقة تحتوي بيانات فعلية).
 */
function xlsx_extract_rows(string $path): array
{
    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        $signature = file_get_contents($path, false, null, 0, 8);

        if ($signature === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('صيغة الملف قديمة (.xls). يرجى فتحه في Excel واختيار "حفظ باسم" ثم اختيار صيغة .xlsx أو .csv، ثم إعادة الرفع.');
        }

        throw new RuntimeException('تعذر فتح ملف Excel. تأكد أن الملف غير تالف وأنه فعلًا بصيغة .xlsx.');
    }

    try {
        if ($zip->numFiles > 2000) {
            throw new RuntimeException('ملف Excel غير صالح (عدد الملفات الداخلية كبير جدًا).');
        }

        $shared = [];
        $sharedXml = xlsx_read_entry($zip, 'xl/sharedStrings.xml');

        if ($sharedXml !== false) {
            $xml = xlsx_load_xml($sharedXml);

            foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
                $parts = $item->xpath('.//*[local-name()="t"]') ?: [];
                $shared[] = implode('', array_map('strval', $parts));
            }
        }

        $sheetFiles = [];
        $workbookXml = xlsx_read_entry($zip, 'xl/workbook.xml');
        $relsXml = xlsx_read_entry($zip, 'xl/_rels/workbook.xml.rels');

        if ($workbookXml !== false && $relsXml !== false) {
            $workbook = xlsx_load_xml($workbookXml);
            $rels = xlsx_load_xml($relsXml);
            $targets = [];

            foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $relation) {
                $targets[(string) $relation['Id']] = (string) $relation['Target'];
            }

            foreach ($workbook->xpath('//*[local-name()="sheet"]') ?: [] as $sheet) {
                $rid = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                $target = $targets[$rid] ?? '';

                if ($target !== '') {
                    $sheetFiles[] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                }
            }
        }

        if (!$sheetFiles) {
            $sheetFiles = ['xl/worksheets/sheet1.xml'];
        }

        $normalizedAliases = import_normalized_aliases();
        $selectedRows = null;
        $firstNonEmptyRows = null;

        foreach ($sheetFiles as $sheetFile) {
            $sheetXml = xlsx_read_entry($zip, $sheetFile);

            if ($sheetXml === false) {
                continue;
            }

            $rows = xlsx_sheet_rows(xlsx_load_xml($sheetXml), $shared);

            if (!$rows) {
                continue;
            }

            if ($firstNonEmptyRows === null) {
                $firstNonEmptyRows = $rows;
            }

            foreach (array_slice($rows, 0, 15) as $candidate) {
                $headers = array_map('normalized_header', $candidate['values']);
                $matches = 0;

                foreach ($normalizedAliases as $names) {
                    if (array_intersect($headers, $names)) {
                        $matches++;
                    }
                }

                if ($matches === 3) {
                    $selectedRows = $rows;
                    break 2;
                }
            }
        }

        $selectedRows ??= $firstNonEmptyRows;
    } finally {
        $zip->close();
    }

    return $selectedRows ?? [];
}

/**
 * يفتح ملف CSV ويحوّله لنفس بنية الصفوف التي ينتجها قارئ xlsx، مع
 * اكتشاف تلقائي للفاصل المستخدم (فاصلة، فاصلة منقوطة، أو Tab) ودعم
 * ترميز UTF-8 مع BOM.
 */
function csv_extract_rows(string $path): array
{
    $content = file_get_contents($path, false, null, 0, 10 * 1024 * 1024 + 1);

    if ($content === false) {
        throw new RuntimeException('تعذر فتح ملف CSV.');
    }

    if (str_starts_with($content, "\xEF\xBB\xBF")) {
        $content = substr($content, 3);
    } elseif (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
        throw new RuntimeException('ترميز UTF-16 غير مدعوم في CSV. احفظ الملف بصيغة CSV UTF-8 أو استخدم صيغة .xlsx.');
    }

    if (!mb_check_encoding($content, 'UTF-8')) {
        if (!function_exists('iconv')) {
            throw new RuntimeException('ترميز ملف CSV غير مدعوم. احفظه بصيغة CSV UTF-8 أو استخدم صيغة .xlsx.');
        }

        $converted = @iconv('Windows-1256', 'UTF-8//IGNORE', $content);

        if ($converted === false) {
            throw new RuntimeException('تعذر قراءة ترميز ملف CSV. احفظه بصيغة CSV UTF-8 أو استخدم صيغة .xlsx.');
        }

        $content = $converted;
    }

    $handle = fopen('php://temp', 'r+b');

    if ($handle === false) {
        throw new RuntimeException('تعذر فتح ملف CSV.');
    }

    fwrite($handle, $content);
    rewind($handle);

    $sample = fread($handle, 4096) ?: '';
    rewind($handle);

    $delimiters = [',', ';', "\t"];
    $delimiter = ',';
    $bestCount = 0;

    foreach ($delimiters as $candidate) {
        $count = substr_count($sample, $candidate);

        if ($count > $bestCount) {
            $bestCount = $count;
            $delimiter = $candidate;
        }
    }

    $rows = [];
    $lineNumber = 0;

    while (($line = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        $lineNumber++;

        if ($line === [null]) {
            continue;
        }

        $values = array_map(
            fn($value): string => str_replace("\xEF\xBB\xBF", '', (string) $value),
            array_slice($line, 0, IMPORT_MAX_COLUMNS)
        );

        if (!array_filter($values, fn(string $v): bool => trim($v) !== '')) {
            continue;
        }

        if (count($rows) >= IMPORT_MAX_ROWS) {
            fclose($handle);
            throw new RuntimeException('عدد صفوف الملف يتجاوز الحد المسموح (' . IMPORT_MAX_ROWS . ' صف).');
        }

        $rows[] = ['number' => $lineNumber, 'values' => $values];
    }

    fclose($handle);

    return $rows;
}

/**
 * المنطق المشترك بين قارئ xlsx وقارئ csv: يحدّد صف العناوين الأنسب
 * (يفحص أول 15 صفًا ويختار الصف الذي يطابق أكبر عدد من الأعمدة
 * الإلزامية)، يبني خريطة الأعمدة، ثم يبني قائمة الطلاب مع تسجيل أي
 * صفوف بها أخطاء بدل رفض الملف بالكامل.
 */
function map_import_rows(array $selectedRows): array
{
    if (!$selectedRows) {
        throw new RuntimeException('الملف فارغ أو لا يحتوي بيانات قابلة للقراءة.');
    }

    $headerIndex = 0;
    $bestMatches = -1;
    $map = [];

    foreach (array_slice($selectedRows, 0, 15, true) as $index => $candidate) {
        $headers = array_map('normalized_header', $candidate['values']);
        $candidateMap = match_header_columns($headers);

        if (count($candidateMap) > $bestMatches) {
            $bestMatches = count($candidateMap);
            $headerIndex = $index;
            $map = $candidateMap;
        }

        if ($bestMatches === 3) {
            break;
        }
    }

    $missing = array_diff(['university_id', 'full_name', 'category'], array_keys($map));

    if ($missing) {
        $labels = [
            'university_id' => 'الرقم الجامعي',
            'full_name' => 'اسم الطالب',
            'category' => 'الفئة',
        ];
        $missingLabels = implode('، ', array_map(fn(string $key): string => $labels[$key], $missing));

        throw new RuntimeException(
            "تعذّر التعرّف تلقائيًا على العمود/الأعمدة التالية في الملف: {$missingLabels}. " .
            'تأكد أن الصف الأول (أو أحد أول 15 صفًا) يحتوي على عناوين واضحة لهذه الأعمدة.'
        );
    }

    $headerValues = array_map('normalized_header', $selectedRows[$headerIndex]['values']);
    $result = [];
    $errors = [];
    $seen = [];
    $extraColumns = [];

    foreach ($headerValues as $index => $header) {
        if ($header !== '' && !in_array($index, $map, true)) {
            $extraColumns[$index] = $header;
        }
    }

    foreach (array_slice($selectedRows, $headerIndex + 1) as $row) {
        $values = $row['values'];
        $raw = array_map(fn(string $value): string => xlsx_clean_value($value), $values);

        if (!array_filter($raw, fn(string $value): bool => $value !== '')) {
            continue;
        }

        $line = $row['number'];
        $id = xlsx_clean_value($values[$map['university_id']] ?? '', true);
        $name = xlsx_clean_value($values[$map['full_name']] ?? '');
        $category = xlsx_clean_value($values[$map['category']] ?? '');
        $idKey = mb_strtolower($id, 'UTF-8');
        $reasons = [];

        if ($id === '') {
            $reasons[] = 'الرقم الجامعي مفقود';
        } elseif (mb_strlen($id, 'UTF-8') > 191) {
            $reasons[] = 'الرقم الجامعي أطول من 191 حرفًا';
        }

        if ($name === '') {
            $reasons[] = 'الاسم مفقود';
        } elseif (mb_strlen($name, 'UTF-8') > 255) {
            $reasons[] = 'الاسم أطول من 255 حرفًا';
        }

        if ($category === '') {
            $reasons[] = 'الفئة مفقودة';
        } elseif (mb_strlen($category, 'UTF-8') > 100) {
            $reasons[] = 'الفئة أطول من 100 حرف';
        }

        if ($id !== '' && isset($seen[$idKey])) {
            $reasons[] = 'الرقم الجامعي مكرر في الملف';
        }

        if ($reasons) {
            $errors[] = ['line' => $line, 'reason' => implode('، ', $reasons)];
            continue;
        }

        $extra = [];

        foreach ($extraColumns as $index => $header) {
            $extra[$header] = xlsx_clean_value($values[$index] ?? '');
        }

        $encodedExtra = json_encode($extra, JSON_UNESCAPED_UNICODE);

        if ($encodedExtra === false || strlen($encodedExtra) > 60000) {
            $errors[] = ['line' => $line, 'reason' => 'بيانات الأعمدة الإضافية غير صالحة أو كبيرة جدًا'];
            continue;
        }

        $seen[$idKey] = true;

        $result[] = [
            'university_id' => $id,
            'full_name' => $name,
            'category' => $category,
            'extra_fields' => $extra,
        ];
    }

    return ['rows' => $result, 'errors' => $errors];
}

/**
 * نقطة الدخول الموحّدة لقراءة ملف مستورد (xlsx أو csv) وتحويله لقائمة
 * طلاب جاهزة للحفظ في قاعدة البيانات.
 */
function read_import_file(string $path, string $extension): array
{
    $extension = strtolower($extension);

    $rows = match ($extension) {
        'xlsx' => xlsx_extract_rows($path),
        'csv' => csv_extract_rows($path),
        default => throw new RuntimeException('صيغة الملف غير مدعومة. يُقبل فقط ملفات .xlsx أو .csv.'),
    };

    return map_import_rows($rows);
}

function xlsx_column_index(string $letters): int
{
    $index = 0;

    foreach (str_split($letters) as $letter) {
        $index = $index * 26 + (ord($letter) - 64);
    }

    return $index - 1;
}

function xlsx_column(int $number): string
{
    $letters = '';

    while ($number > 0) {
        $remainder = ($number - 1) % 26;
        $letters = chr(65 + $remainder) . $letters;
        $number = intdiv($number - 1, 26);
    }

    return $letters;
}

function xlsx_export(array $headers, array $rows, string $filename): never
{
    $sheetRows = '';

    foreach ([$headers, ...$rows] as $rowNumber => $row) {
        $cells = '';

        foreach (array_values($row) as $index => $value) {
            $numeric = preg_match('/^[1-9][0-9]{0,14}$/', (string) $value) === 1;
            $type = $numeric ? '' : ' t="inlineStr"';
            $cell = $numeric
                ? '<v>' . xml_text((string) $value) . '</v>'
                : '<is><t>' . xml_text((string) $value) . '</t></is>';

            $cells .= '<c r="' . xlsx_column($index + 1) . ($rowNumber + 1) . '"' . $type . '>' . $cell . '</c>';
        }

        $sheetRows .= '<row r="' . ($rowNumber + 1) . '">' . $cells . '</row>';
    }

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Alsarmini Swap" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>',
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'alsarmini_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }

    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    readfile($tmp);
    unlink($tmp);
    exit;
}
