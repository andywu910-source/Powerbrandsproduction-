<?php

namespace Tests\Feature;

use App\Models\BookkeepingSend;
use App\Models\BookkeepingSetting;
use App\Models\BookkeepingStatement;
use App\Models\BookkeepingTemplate;
use App\Models\BookkeepingWeek;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingMailer;
use App\Support\Modules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeBookkeepingMailer;
use Tests\TestCase;

class BookkeepingTest extends TestCase
{
    use RefreshDatabase;

    private array $companies = [
        [
            'id' => 'green-mantis',
            'name' => 'Green Mantis',
            'legal_business_name' => 'Green Mantis LLC',
            'outstanding_balance' => '120.50',
            'invoice_email' => 'pay@green.example',
        ],
        [
            'id' => 'greenstar',
            'name' => 'Greenstar Advisory LLC',
            'legal_business_name' => 'Greenstar Advisory LLC',
            'outstanding_balance' => '0',
            'invoice_email' => null,
            'default_email' => null,
        ],
        [
            'id' => 'blue-heron',
            'name' => 'Blue Heron',
            'legal_business_name' => 'Blue Heron Inc',
            'outstanding_balance' => '15.00',
            'invoice_email' => 'blue@example.com',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 15:00:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_module_is_off_until_switched_on_and_non_admins_are_denied(): void
    {
        $this->assertFalse(Modules::enabled('bookkeeping'));
        $this->assertNotContains('bookkeeping', Modules::list());

        $admin = User::factory()->admin()->create();
        $operator = User::factory()->create();
        $supervisor = User::factory()->supervisor()->create();
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->get('/bookkeeping')->assertRedirect(route('login'));
        $this->post('/bookkeeping/statements', ['account' => '8455'])->assertRedirect(route('login'));
        $this->post('/bookkeeping/send', ['confirm' => 'yes'])->assertRedirect(route('login'));

        $this->actingAs($admin)->get('/bookkeeping')->assertNotFound();
        $this->actingAs($operator)->get('/bookkeeping')->assertForbidden();
        $this->actingAs($operator)->post('/bookkeeping/send', ['confirm' => 'yes'])->assertForbidden();
        $this->actingAs($supervisor)->get('/bookkeeping')->assertForbidden();
        $this->actingAs($viewer)->get('/bookkeeping')->assertForbidden();
        $this->actingAs($viewer)->post('/bookkeeping/payments')->assertForbidden();

        config(['pbp.modules' => 'production,bookkeeping', 'pbp.modules_off' => '']);
        $this->assertTrue(Modules::enabled('bookkeeping'));
        $this->actingAs($operator)->get('/bookkeeping')->assertForbidden();
        $this->actingAs($admin)->get('/bookkeeping')->assertOk();

        config(['pbp.modules_off' => 'bookkeeping']);
        $this->assertFalse(Modules::enabled('bookkeeping'));
        $this->actingAs($admin)->get('/bookkeeping')->assertNotFound();
    }

    public function test_admin_page_ledger_upload_and_private_storage(): void
    {
        $this->on();
        Storage::fake('local');
        $admin = User::factory()->admin()->create(['name' => 'Andy Wu']);

        $this->actingAs($admin)->get('/bookkeeping')
            ->assertOk()
            ->assertSee('Weekly Bookkeeping')
            ->assertSee('Status: Last updated 11/19/2026')
            ->assertSee('https://docs.google.com/spreadsheets/d/1OKJvvYKqRcXoxUjqEHFTlly9nwfiMDWIsX4_lGX5kkU/edit', false)
            ->assertSee("I've recorded payments in Distru", false)
            ->assertSee('Record payments in Distru to unlock this step')
            ->assertSee('name="_token"', false);

        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('>Bookkeeping<', false);
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertDontSee('>Bookkeeping<', false);

        $csv = fn () => UploadedFile::fake()->createWithContent('chase.csv', "Date,Amount\n11/01/2026,10.00\n");
        $pdf = UploadedFile::fake()->createWithContent('bank.pdf', "%PDF-1.4\n1 0 obj\n<<>>\ntrailer\n<<>>\n%%EOF\n");
        $this->actingAs($admin)->post('/bookkeeping/statements', [
            'week' => '2026-11-19', 'account' => '8455', 'statement' => $csv(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/bookkeeping/statements', [
            'week' => '2026-11-19', 'account' => '9529', 'statement' => $pdf,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/bookkeeping/statements', [
            'week' => '2026-11-19', 'account' => '9511', 'statement' => $csv(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/bookkeeping/statements', [
            'account' => '9999', 'statement' => $csv(),
        ])->assertSessionHasErrors('account');
        $this->actingAs($admin)->post('/bookkeeping/statements', [
            'account' => '8455', 'statement' => UploadedFile::fake()->createWithContent('x.pdf', 'not a pdf'),
        ])->assertSessionHasErrors('statement');
        $this->actingAs($admin)->post('/bookkeeping/statements', [
            'account' => '8455', 'statement' => UploadedFile::fake()->createWithContent('x.csv', "<?php echo 1;\n"),
        ])->assertSessionHasErrors('statement');

        $rows = BookkeepingStatement::orderBy('id')->get();
        $this->assertSame(['8455', '9529', '9511'], $rows->pluck('account')->all());
        $this->assertTrue($rows->every(fn ($row) => $row->uploaded_by === $admin->id && $row->created_at !== null));
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('#^bookkeeping/statements/\d+/[0-9a-f-]{36}\.(csv|pdf)$#', $row->path);
            $this->assertTrue(Storage::disk('local')->exists($row->path));
            $this->assertFalse(is_file(public_path($row->path)));
            Storage::disk('local')->assertExists($row->path);
        }
        $week = BookkeepingWeek::first();
        $this->assertSame('statements_received', $week->ledger_status);

        $page = $this->actingAs($admin)->get('/bookkeeping');
        $page->assertSee('Statements received, pending update');
        $page->assertSee('Status: Last updated 11/19/2026');
        $page->assertSee('Andy Wu');

        $download = $this->actingAs($admin)->get(route('bookkeeping.statements.download', $rows[1]));
        $download->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $download->streamedContent());
        $this->actingAs(User::factory()->create())->get(route('bookkeeping.statements.download', $rows[1]))->assertForbidden();
        $this->actingAsGuest()->get(route('bookkeeping.statements.download', $rows[1]))->assertRedirect(route('login'));

        $this->actingAs($admin)->post('/bookkeeping/ledger', [
            'week' => '2026-11-19',
            'updated_on' => '2026-11-19',
            'summary' => '59 rows added, 21 flagged',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/bookkeeping')
            ->assertSee('Status: Last updated 11/19/2026')
            ->assertSee('59 rows added, 21 flagged')
            ->assertDontSee('Statements received, pending update');
        $this->assertSame('2026-11-19', BookkeepingSetting::get('ledger_updated_on'));
        $this->assertSame('updated', $week->fresh()->ledger_status);
        $this->assertSame($admin->id, $week->fresh()->ledger_updated_by);

        $this->actingAs($admin)->post('/bookkeeping/ledger', [
            'updated_on' => '2026-11-20',
            'summary' => 'nope',
        ])->assertSessionHasErrors('updated_on');
    }

    public function test_payments_unlock_brand_search_template_and_guarded_send(): void
    {
        $this->on();
        $admin = User::factory()->admin()->create();
        $this->fakeDistru();

        $this->actingAs($admin)->getJson('/bookkeeping/brands?q=green')->assertForbidden();
        $this->actingAs($admin)->post('/bookkeeping/payments')->assertRedirect()->assertSessionHasNoErrors();
        $week = BookkeepingWeek::first();
        $this->assertNotNull($week->payments_recorded_at);
        $this->assertSame($admin->id, $week->payments_recorded_by);

        config(['bookkeeping.distru.api_key' => null]);
        $this->actingAs($admin)->get('/bookkeeping')
            ->assertOk()
            ->assertSee('Set DISTRU_API_KEY')
            ->assertSee('Email is not set up')
            ->assertSee('Oustanding Invoice(s) from Powerbrands')
            ->assertSee('Please review the attached invoice for the latest update.')
            ->assertSee('[signature]')
            ->assertDontSee('super-secret');
        $this->actingAs($admin)->getJson('/bookkeeping/brands?q=green')
            ->assertOk()
            ->assertJsonPath('ok', false);

        config(['bookkeeping.distru.api_key' => 'super-secret-distru-key']);
        $this->actingAs($admin)->get('/bookkeeping?q=green')
            ->assertOk()
            ->assertSee('Green Mantis')
            ->assertSee('Greenstar Advisory LLC')
            ->assertSee('$120.50 open')
            ->assertSee('No open invoices')
            ->assertDontSee('Blue Heron')
            ->assertDontSee('super-secret-distru-key');

        $this->actingAs($admin)->getJson('/bookkeeping/brands?q=GrEeN')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonFragment(['name' => 'Green Mantis'])
            ->assertJsonFragment(['name' => 'Greenstar Advisory LLC']);

        $this->actingAs($admin)->post('/bookkeeping/brands', ['company_id' => 'green-mantis'])->assertRedirect();
        $this->actingAs($admin)->post('/bookkeeping/brands', ['company_id' => 'greenstar'])->assertRedirect();
        $this->actingAs($admin)->post('/bookkeeping/brands', ['company_id' => 'blue-heron'])->assertRedirect();
        $this->actingAs($admin)->get('/bookkeeping')
            ->assertSee('INV-1001')
            ->assertSee('pay@green.example')
            ->assertSee('$80.00')
            ->assertSee('$40.50')
            ->assertSee('Total due')
            ->assertSee('Not on file')
            ->assertSee('blue@example.com');

        $this->actingAs($admin)->post('/bookkeeping/template', [
            'company_id' => 'green-mantis',
            'company_name' => 'Green Mantis',
            'subject' => 'Custom subject',
            'body' => "Hello Green\n\nThank you,\n[signature]",
        ])->assertSessionHasNoErrors();
        $this->assertTrue(BookkeepingTemplate::hasOverride('green-mantis'));
        $this->actingAs($admin)->post('/bookkeeping/template/revert', ['company_id' => 'green-mantis'])->assertSessionHasNoErrors();
        $this->assertFalse(BookkeepingTemplate::hasOverride('green-mantis'));
        $this->assertSame('Oustanding Invoice(s) from Powerbrands', BookkeepingTemplate::subjectFor('green-mantis'));

        $this->actingAs($admin)->post('/bookkeeping/signature', [
            'signature_html' => '<script>alert(1)</script><b>Andy Wu</b>',
        ])->assertSessionHasNoErrors();
        $preview = $this->actingAs($admin)->postJson('/bookkeeping/preview', [
            'subject' => 'Oustanding Invoice(s) from Powerbrands',
            'body' => config('bookkeeping.template.body'),
            'signature_html' => '<img src="javascript:alert(1)"><b>Andy Wu</b>',
            'invoice_numbers' => ['INV-1001', 'INV-1002'],
        ])->assertOk();
        $preview->assertJsonPath('indicator', 'Attached: INV-1001.pdf, INV-1002.pdf');
        $html = $preview->json('html');
        $this->assertStringContainsString('<b>Andy Wu</b>', $html);
        $this->assertStringContainsString('Please review the attached invoice for the latest update.', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);

        $page = $this->actingAs($admin)->get('/bookkeeping');
        $page->assertSee('id="bkSendButton"', false);
        $this->assertMatchesRegularExpression('/id="bkSendButton"[^>]*disabled/', $page->getContent());

        $this->actingAs($admin)->post('/bookkeeping/send', [
            'confirm' => 'yes',
            'companies' => ['green-mantis'],
        ])->assertSessionHasErrors('send');
        $this->assertSame(0, BookkeepingSend::count());

        $fake = new FakeBookkeepingMailer;
        $this->app->instance(BookkeepingMailer::class, $fake);
        $this->actingAs($admin)->post('/bookkeeping/send', [
            'companies' => ['green-mantis'],
        ])->assertSessionHasErrors('confirm');
        $this->assertSame([], $fake->sent);

        $this->actingAs($admin)->post('/bookkeeping/send', [
            'confirm' => 'yes',
            'companies' => ['green-mantis', 'blue-heron'],
            'exclude' => ['blue-heron'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertCount(1, $fake->sent);
        $message = $fake->sent[0];
        $this->assertSame('billing@powerbrandsny.com', $message->fromAddress);
        $this->assertSame('pay@green.example', $message->to);
        $this->assertSame('Oustanding Invoice(s) from Powerbrands', $message->subject);
        $this->assertStringContainsString('Please review the attached invoice for the latest update.', $message->text);
        $this->assertStringContainsString('Andy Wu', $message->text);
        $this->assertCount(2, $message->attachments);
        $this->assertStringStartsWith('%PDF-', $message->attachments[0]['bytes']);
        $this->assertSame('application/pdf', $message->attachments[0]['mime']);

        $log = BookkeepingSend::firstOrFail();
        $this->assertSame('sent', $log->status);
        $this->assertSame($admin->id, $log->sent_by);
        $this->assertSame('Green Mantis', $log->company_name);
        $this->assertSame(['pay@green.example'], $log->recipients);
        $this->assertEqualsCanonicalizing(['INV-1001', 'INV-1002'], collect($log->invoices)->pluck('number')->all());
        $this->assertNotNull($log->sent_at);

        $route = Route::getRoutes()->getByName('bookkeeping.send');
        $middleware = $route->gatherMiddleware();
        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertContains('role:admin', $middleware);
        $this->assertContains('module:bookkeeping', $middleware);
    }

    public function test_failed_pdf_is_logged_and_not_sent(): void
    {
        $this->on();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/bookkeeping/payments')->assertRedirect();
        config(['bookkeeping.distru.api_key' => 'test-key']);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/pdf')) {
                return Http::response('not a pdf', 200);
            }
            if (str_contains($url, '/invoices')) {
                return Http::response(['data' => [[
                    'id' => 'inv-1',
                    'invoice_number' => 'INV-1001',
                    'invoice_datetime' => '2026-11-01T15:00:00Z',
                    'remaining_amount' => '80.00',
                    'voided_datetime' => null,
                    'company' => ['id' => 'green-mantis', 'name' => 'Green Mantis'],
                ]], 'next_page' => null]);
            }

            return Http::response(['data' => [[
                'id' => 'green-mantis',
                'name' => 'Green Mantis',
                'legal_business_name' => 'Green Mantis LLC',
                'outstanding_balance' => '80',
                'invoice_email' => 'pay@green.example',
            ]], 'next_page' => null]);
        });
        $this->actingAs($admin)->post('/bookkeeping/brands', ['company_id' => 'green-mantis'])->assertRedirect();
        $fake = new FakeBookkeepingMailer;
        $this->app->instance(BookkeepingMailer::class, $fake);

        $this->actingAs($admin)->post('/bookkeeping/send', [
            'confirm' => 'yes',
            'companies' => ['green-mantis'],
        ])->assertRedirect()->assertSessionHasErrors('send');

        $this->assertSame([], $fake->sent);
        $log = BookkeepingSend::firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame($admin->id, $log->sent_by);
        $this->assertSame(['pay@green.example'], $log->recipients);
    }

    public function test_distru_pagination_cannot_leave_the_api_host(): void
    {
        $this->on();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/bookkeeping/payments')->assertRedirect();
        config(['bookkeeping.distru.api_key' => 'test-key']);
        Http::fake([
            'https://app.distru.com/*' => Http::response([
                'data' => [[
                    'id' => 'green-mantis',
                    'name' => 'Green Mantis',
                    'legal_business_name' => 'Green Mantis LLC',
                    'outstanding_balance' => '1',
                    'invoice_email' => 'pay@green.example',
                ]],
                'next_page' => 'https://evil.example/steal?key=1',
            ]),
        ]);

        $this->actingAs($admin)->get('/bookkeeping?q=green')
            ->assertOk()
            ->assertSee('unexpected link');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    }

    private function on(): void
    {
        config(['pbp.modules' => 'production,receiving,checklists,batchlog,labels,schedule,compliance,bookkeeping', 'pbp.modules_off' => '']);
    }

    private function fakeDistru(): void
    {
        config(['bookkeeping.distru.api_key' => 'test-key']);
        $companies = $this->companies;
        Http::fake(function ($request) use ($companies) {
            $url = $request->url();
            if (str_contains($url, '/pdf')) {
                return Http::response("%PDF-1.4\nfake\n", 200, ['Content-Type' => 'application/pdf']);
            }
            if (str_contains($url, '/invoices')) {
                return Http::response(['data' => [
                    [
                        'id' => 'inv-1',
                        'invoice_number' => 'INV-1001',
                        'invoice_datetime' => '2026-11-01T15:00:00Z',
                        'remaining_amount' => '80.00',
                        'voided_datetime' => null,
                        'company' => ['id' => 'green-mantis', 'name' => 'Green Mantis'],
                    ],
                    [
                        'id' => 'inv-2',
                        'invoice_number' => 'INV-1002',
                        'invoice_datetime' => '2026-11-10T15:00:00Z',
                        'remaining_amount' => '40.50',
                        'voided_datetime' => null,
                        'company' => ['id' => 'green-mantis', 'name' => 'Green Mantis'],
                    ],
                    [
                        'id' => 'inv-paid',
                        'invoice_number' => 'INV-PAID',
                        'invoice_datetime' => '2026-10-01T15:00:00Z',
                        'remaining_amount' => '0',
                        'voided_datetime' => null,
                        'company' => ['id' => 'green-mantis', 'name' => 'Green Mantis'],
                    ],
                    [
                        'id' => 'inv-blue',
                        'invoice_number' => 'INV-2001',
                        'invoice_datetime' => '2026-11-02T15:00:00Z',
                        'remaining_amount' => '15.00',
                        'voided_datetime' => null,
                        'company' => ['id' => 'blue-heron', 'name' => 'Blue Heron'],
                    ],
                ], 'next_page' => null]);
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $rows = $companies;
            if (isset($query['name'])) {
                $rows = array_values(array_filter($rows, fn ($row) => stripos($row['name'], $query['name']) !== false));
            } elseif (isset($query['legal_business_name'])) {
                $rows = array_values(array_filter($rows, fn ($row) => stripos((string) ($row['legal_business_name'] ?? ''), $query['legal_business_name']) !== false));
            } elseif (! empty($query['ids'])) {
                $ids = array_values((array) $query['ids']);
                $rows = array_values(array_filter($rows, fn ($row) => in_array($row['id'], $ids, true)));
            }

            return Http::response(['data' => $rows, 'next_page' => null]);
        });
    }
}
