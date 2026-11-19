<?php

namespace Tests\Feature;

use App\Models\BookkeepingStatement;
use App\Models\BookkeepingTemplate;
use App\Models\User;
use App\Services\Bookkeeping\AccountDetector;
use App\Services\Bookkeeping\PdfText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Step 1 (Nov 19): one drop zone for all statements; the account is detected per file and can be corrected. */
class BookkeepingStatementDetectTest extends TestCase
{
    use RefreshDatabase;

    private const ACCTS = ['8455', '9529', '9511'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels,schedule,compliance,bookkeeping', 'pbp.modules_off' => '']);
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
    }

    private function csv(string $name, string $body): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    private function pdf(string $name, string $body = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** PDF text from a fake extractor (null = the server has no pdftotext). */
    private function pdfText(?string $text): void
    {
        $this->app->instance(PdfText::class, new class($text) extends PdfText {
            public function __construct(private ?string $t) {}
            public function available(): bool { return $this->t !== null; }
            public function extract(string $bytes): ?string { return $this->t; }
        });
    }

    public function test_text_rules(): void
    {
        $f = fn ($t) => AccountDetector::fromText($t, self::ACCTS);
        $this->assertSame(['8455', []], $f("Account Number: XXXXXX8455\nBeginning balance 1,234.56"));
        $this->assertSame(['9529', []], $f('Primary account ending in 9529'));
        $this->assertSame(['9511', []], $f('Acct # ****9511'));
        $this->assertSame(['9511', []], $f('Account 000123459511 statement'));
        $this->assertSame(['8455', []], $f("Chase Total Checking ...8455\nOnline transfer to CHK ...9529\nPayroll ...8455"), 'own account first and most often');
        $this->assertSame([null, ['8455', '9529']], $f("Transfer ...9529\nTransfer ...8455"), 'tie: not guessed');
        $this->assertSame([null, []], $f("Date,Amount\n11/01/2026,8455.00\n11/02/2026,\$9,529.10\nCheck 9511 amount 12"), 'amounts and loose numbers are not account numbers');
        $this->assertSame('8455', AccountDetector::fromName('Chase8455_Activity_20261119.CSV', self::ACCTS));
        $this->assertNull(AccountDetector::fromName('statement_2026_9529_9511.pdf', self::ACCTS), 'two numbers in the name: not guessed');
        $this->assertNull(AccountDetector::fromName('ref84551.csv', self::ACCTS), 'part of a longer number');
    }

    public function test_detect_endpoint_reads_contents_then_file_name_and_saves_nothing(): void
    {
        $this->pdfText(null);   // like live: no pdftotext
        $res = $this->actingAs($this->admin)->post('/bookkeeping/statements/detect', ['statements' => [
            $this->csv('activity.csv', "Details,Posting Date,Description,Amount\nDEBIT,11/03/2026,ONLINE TRANSFER FROM CHK ...9511,10.00\n"),
            $this->pdf('Statement_9529.pdf'),
            $this->pdf('statement.pdf'),
            $this->csv('x.csv', "Date,Amount\n\0\n"),
        ]])->assertOk()->json('files');
        $this->assertSame(['9511', '9529', null, null], array_column($res, 'account'));
        $this->assertSame(['contents', 'filename', null, null], array_column($res, 'source'));
        $this->assertStringContainsString("PDF text can't be read on this server", $res[2]['note']);
        $this->assertNotNull($res[3]['error'], 'CSV null-byte check still applies');
        $this->assertSame(0, BookkeepingStatement::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_pdf_contents_win_over_the_file_name_when_text_is_available(): void
    {
        $this->pdfText("FIRST BANK\nAccount number: XXXX XXXX 9511\nStatement period 11/01 - 11/30");
        $res = $this->actingAs($this->admin)->post('/bookkeeping/statements/detect', ['statements' => [$this->pdf('Statement_9529.pdf')]])->json('files');
        $this->assertSame(['9511', 'contents'], [$res[0]['account'], $res[0]['source']]);
    }

    public function test_real_pdftotext_when_installed(): void
    {
        if (! (new PdfText)->available()) {
            $this->markTestSkipped('pdftotext not installed here');
        }
        $dompdf = new \Dompdf\Dompdf;
        $dompdf->loadHtml('<p>Business Checking</p><p>Account ending in 9529</p><p>Balance $8,455.00</p>');
        $dompdf->render();
        $d = app(AccountDetector::class)->detect('scan.pdf', 'pdf', $dompdf->output());
        $this->assertSame(['9529', 'contents'], [$d['account'], $d['source']]);
    }

    public function test_upload_any_order_with_detection_and_one_correction(): void
    {
        $this->pdfText(null);
        $this->actingAs($this->admin)->post('/bookkeeping/statements', ['week' => '2026-11-19',
            'statements' => [
                $this->pdf('Statement_9529.pdf'),
                $this->csv('main.csv', "Account,Date,Amount\nXXXX8455,11/01/2026,5.00\n"),
                $this->pdf('scan.pdf'),
            ],
            'accounts' => ['', '', '9511'],   // the third one corrected by hand
        ])->assertRedirect()->assertSessionHasNoErrors();
        $rows = BookkeepingStatement::orderBy('id')->get();
        $this->assertSame([['Statement_9529.pdf', '9529'], ['main.csv', '8455'], ['scan.pdf', '9511']], $rows->map(fn ($r) => [$r->original_name, $r->account])->all());
        foreach ($rows as $r) {
            $this->assertMatchesRegularExpression('#^bookkeeping/statements/\d+/[0-9a-f-]{36}\.(csv|pdf)$#', $r->path);
            Storage::disk('local')->assertExists($r->path);
        }
        $this->assertSame('statements_received', $rows[0]->week->ledger_status);
    }

    public function test_server_blocks_unidentified_and_duplicate_accounts_and_saves_nothing(): void
    {
        $this->pdfText(null);
        $this->actingAs($this->admin)->post('/bookkeeping/statements', ['week' => '2026-11-19',
            'statements' => [$this->pdf('scan.pdf'), $this->csv('a.csv', "Date,Amount\n1,2\n")]])
            ->assertSessionHasErrors(['statements']);
        $errors = session('errors')->get('statements');
        $this->assertCount(2, $errors);
        $this->assertStringStartsWith('Pick the account for scan.pdf.', $errors[0]);

        $this->actingAs($this->admin)->post('/bookkeeping/statements', ['week' => '2026-11-19',
            'statements' => [$this->pdf('Chase8455.pdf'), $this->csv('other.csv', "Account ...9511\n")], 'accounts' => ['', '8455']])
            ->assertSessionHasErrors(['statements' => 'Chase8455.pdf and other.csv are both account 8455. Each account gets one statement.']);

        $this->actingAs($this->admin)->post('/bookkeeping/statements', ['week' => '2026-11-19',
            'statements' => [$this->pdf('Chase8455.pdf'), $this->csv('x.csv', "Date\n\0")], 'accounts' => ['', '9529']])
            ->assertSessionHasErrors('statements');
        $this->actingAs($this->admin)->post('/bookkeeping/statements', ['week' => '2026-11-19',
            'statements' => [$this->pdf('a8455.pdf'), $this->pdf('b9529.pdf'), $this->pdf('c9511.pdf'), $this->pdf('d.pdf')]])
            ->assertSessionHasErrors(['statements' => 'Upload at most one statement per account (3 files).']);
        $this->assertSame(0, BookkeepingStatement::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_page_has_one_drop_zone_and_no_account_slots(): void
    {
        $html = $this->actingAs($this->admin)->get('/bookkeeping')->assertOk()->getContent();
        $this->assertStringContainsString('name="statements[]" id="bkFile" multiple', $html);
        $this->assertStringContainsString('data-detect="'.route('bookkeeping.statements.detect').'"', $html);
        $this->assertStringContainsString('8455 (main)', $html);
        $this->assertStringNotContainsString('name="account"', $html);
        $this->assertStringNotContainsString('id="bkAccount"', $html);
    }

    public function test_subject_typo_fixed_and_saved_default_row_migrated_only_if_exact(): void
    {
        $this->assertSame('Outstanding Invoice(s) from Powerbrands', BookkeepingTemplate::subjectFor(''));
        $m = require database_path('migrations/2026_11_19_004000_fix_bookkeeping_subject_typo.php');
        DB::table('bookkeeping_templates')->insert([
            ['company_id' => '', 'subject' => 'Oustanding Invoice(s) from Powerbrands', 'body' => 'b', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 'green', 'subject' => 'Oustanding Invoice(s) from Powerbrands', 'body' => 'b', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $m->up();
        $this->assertSame('Outstanding Invoice(s) from Powerbrands', BookkeepingTemplate::subjectFor(''));
        $this->assertSame('Oustanding Invoice(s) from Powerbrands', DB::table('bookkeeping_templates')->where('company_id', 'green')->value('subject'), 'per-brand rows untouched');
        DB::table('bookkeeping_templates')->where('company_id', '')->update(['subject' => 'Oustanding invoices - custom']);
        $m->up();
        $this->assertSame('Oustanding invoices - custom', BookkeepingTemplate::subjectFor(''), 'a customised subject is left alone');
    }
}
