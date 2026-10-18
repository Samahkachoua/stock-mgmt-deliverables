<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reads exported files back in tests.
 */
final class Exports
{
    /**
     * The data rows of an .xlsx download (the header row left out), and the header.
     *
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: bool} header, rows, right-to-left
     */
    public static function xlsx(TestResponse $response): array
    {
        $base = $response->baseResponse;
        expect($base)->toBeInstanceOf(BinaryFileResponse::class);

        /** @var BinaryFileResponse $base */
        return self::xlsxFile($base->getFile()->getPathname());
    }

    /**
     * What a Livewire action handed the browser to download (e.g. a report's
     * export()): its file name and bytes.
     *
     * @return array{name: string, content: string}
     */
    public static function fromDownload(Testable $component): array
    {
        $download = data_get($component->effects, 'download');
        expect($download)->not->toBeNull('The action did not download a file.');

        return ['name' => (string) $download['name'], 'content' => (string) base64_decode((string) $download['content'], true)];
    }

    /**
     * Like xlsx(), for the bytes of a Livewire download.
     *
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: bool} header, rows, right-to-left
     */
    public static function xlsxContent(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);

        try {
            return self::xlsxFile($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>, 2: bool}
     */
    private static function xlsxFile(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray(null, false, false);
        $header = array_shift($rows);

        return [$header, array_values($rows), $sheet->getRightToLeft()];
    }

    /**
     * The text mPDF wrote, in the order it is drawn: every string shown with
     * Tj / TJ, decoded from the font's 16-bit codes. Arabic comes out in visual
     * order, with joined letters as presentation forms (U+FB50–U+FEFF).
     */
    public static function pdfText(string $pdf): string
    {
        $content = '';

        // Content streams are compressed (FlateDecode). Each is read by its
        // /Length: compressed bytes may themselves end in \r or \n.
        preg_match_all('/\/Length (\d+)(?:\s[^>]*)?>>\s*stream\r?\n/', $pdf, $streams, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($streams as $stream) {
            $bytes = substr($pdf, $stream[0][1] + strlen($stream[0][0]), (int) $stream[1][0]);
            $inflated = @gzuncompress($bytes);
            $content .= ($inflated === false ? $bytes : $inflated)."\n";
        }

        $text = [];
        preg_match_all('/\[(.*?)\]\s*TJ|\((.*?)(?<!\\\\)\)\s*Tj/s', $content, $shown, PREG_SET_ORDER);

        foreach ($shown as $match) {
            preg_match_all('/\((.*?)(?<!\\\\)\)/s', $match[1] !== '' ? $match[1] : '('.$match[2].')', $parts);
            $bytes = implode('', array_map(fn (string $part) => self::unescape($part), $parts[1]));
            $text[] = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }

        return implode("\n", $text);
    }

    /**
     * A PDF literal string's bytes: \n \r \t \b \f \( \) \\ and octal escapes.
     */
    private static function unescape(string $string): string
    {
        return preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', function (array $m) {
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                '(' => '(', ')' => ')', '\\' => '\\',
                default => chr(octdec($m[1])),
            };
        }, $string) ?? $string;
    }
}
