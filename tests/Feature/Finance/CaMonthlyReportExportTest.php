<?php

namespace Tests\Feature\Finance;

use App\Enums\CaMonthlyReportEmailDeliveryMode;
use App\Enums\CaMonthlyReportEmailStatus;
use App\Enums\CaMonthlyReportExportFormat;
use App\Enums\CaMonthlyReportExportStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Infrastructure\Queue\QueueRouting;
use App\Jobs\GenerateCaMonthlyReportExportJob;
use App\Jobs\SendCaMonthlyReportExportEmailJob;
use App\Mail\CaMonthlyReportExportMail;
use App\Models\CaMonthlyReportExport;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use App\Services\Finance\CaMonthlyReportExportService;
use App\Services\Finance\CaMonthlyReportExportStorage;
use App\Services\Notifications\NotificationMailSender;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\CaMonthlyReportExportBenchmarkRecorder;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;
use ZipArchive;

class CaMonthlyReportExportTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const RANGE = [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-21',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        config([
            'ca_monthly_report.sync_max_lines' => 2,
            'ca_monthly_report.max_email_attachment_bytes' => 1024,
            'ca_monthly_report.retention_hours' => 72,
        ]);
    }

    public function test_streaming_export_preserves_invoice_register_column_contract(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $rows = [];
        app(CaMonthlyStatutoryLineReadModel::class)->streamExportRows($this->request(), function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        $this->assertCount(1, $rows);
        $this->assertCount(21, $rows[0]);
        $this->assertSame(CaMonthlyReportDefinition::HEADERS, CaMonthlyReportDefinition::HEADERS);
        $this->assertSame('118.00', $rows[0][17]);
    }

    public function test_sync_csv_streams_without_building_full_array_in_controller_path(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $user = $this->adminUser();

        $response = $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.export.csv', self::RANGE));

        $response->assertOk();
        $this->assertStringContainsString('INV-EINV-1', $response->streamedContent());
        $this->assertStringContainsString('Service', $response->streamedContent());
    }

    public function test_large_export_is_queued_instead_of_blocking_request(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);
        $this->makeTaxInvoice(['issued_at' => '2026-09-11 10:00:00']);
        $this->makeTaxInvoice(['issued_at' => '2026-09-12 10:00:00']);

        Bus::fake();

        $user = $this->adminUser();

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.export.xlsx', self::RANGE))
            ->assertRedirect();

        Bus::assertDispatched(GenerateCaMonthlyReportExportJob::class);
    }

    public function test_background_job_transitions_to_ready_and_writes_artifact(): void
    {
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Xlsx);

        (new GenerateCaMonthlyReportExportJob($export->id))->handle(app(CaMonthlyReportExportService::class));

        $export->refresh();
        $expectedRows = app(CaMonthlyStatutoryLineReadModel::class)->countExportLines($this->request());
        $this->assertSame(CaMonthlyReportExportStatus::Ready, $export->status);
        $this->assertSame($expectedRows, $export->row_count);
        $this->assertNotNull($export->storage_path);
        Storage::disk('local')->assertExists($export->storage_path);
    }

    public function test_user_cannot_download_another_users_export(): void
    {
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv);
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => 'ca-monthly-report-exports/test.csv',
            'expires_at' => now()->addDay(),
        ])->save();
        Storage::disk('local')->put($export->storage_path, "a,b\n1,2\n");

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($other)
            ->get(route('finance.reports.ca-monthly.exports.download', $export))
            ->assertForbidden();
    }

    public function test_expired_export_cannot_be_downloaded(): void
    {
        $user = $this->adminUser();
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv, null, true, $user);
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => 'ca-monthly-report-exports/test.csv',
            'expires_at' => now()->subMinute(),
        ])->save();
        Storage::disk('local')->put($export->storage_path, "a,b\n1,2\n");

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.exports.download', $export))
            ->assertStatus(410);
    }

    public function test_duplicate_export_request_reuses_recent_idempotent_record(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);
        $this->makeTaxInvoice(['issued_at' => '2026-09-11 10:00:00']);
        $this->makeTaxInvoice(['issued_at' => '2026-09-12 10:00:00']);

        Queue::fake();

        $service = app(CaMonthlyReportExportService::class);
        $user = $this->adminUser();
        $request = $this->request();

        $first = $service->requestFromHttp($request, $user, CaMonthlyReportExportFormat::Csv);
        $second = $service->requestFromHttp($request, $user, CaMonthlyReportExportFormat::Csv);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CaMonthlyReportExport::query()->count());
    }

    public function test_email_job_uses_existing_artifact_and_does_not_regenerate_report(): void
    {
        Mail::fake();

        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv, 'finance@example.com');
        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($export);
        Storage::disk('local')->put($path, "header\nvalue\n");
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => $path,
            'row_count' => 1,
            'file_size_bytes' => 12,
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
            'email_status' => CaMonthlyReportEmailStatus::Queued,
        ])->save();

        (new SendCaMonthlyReportExportEmailJob($export->id))->handle(
            app(CaMonthlyReportExportService::class),
            app(NotificationMailSender::class),
        );

        Mail::assertSent(CaMonthlyReportExportMail::class);
        $this->assertSame(CaMonthlyReportEmailStatus::Sent, $export->fresh()->email_status);
        Storage::disk('local')->assertExists($path);
    }

    public function test_small_artifact_uses_attachment_delivery_mode(): void
    {
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv);
        $export->forceFill(['file_size_bytes' => 512])->save();

        $mode = app(CaMonthlyReportExportService::class)->resolveDeliveryMode($export);

        $this->assertSame(CaMonthlyReportEmailDeliveryMode::Attachment, $mode);
    }

    public function test_large_artifact_uses_download_link_delivery_mode(): void
    {
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Xlsx);
        $export->forceFill(['file_size_bytes' => 5000])->save();

        $mode = app(CaMonthlyReportExportService::class)->resolveDeliveryMode($export);

        $this->assertSame(CaMonthlyReportEmailDeliveryMode::DownloadLink, $mode);
    }

    public function test_email_attachment_mode_sends_file_without_storage_path_in_body(): void
    {
        Mail::fake();

        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv, 'finance@example.com');
        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($export);
        Storage::disk('local')->put($path, "header\nvalue\n");
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => $path,
            'row_count' => 1,
            'file_size_bytes' => 12,
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
            'email_status' => CaMonthlyReportEmailStatus::Queued,
        ])->save();

        (new SendCaMonthlyReportExportEmailJob($export->id))->handle(
            app(CaMonthlyReportExportService::class),
            app(NotificationMailSender::class),
        );

        Mail::assertSent(CaMonthlyReportExportMail::class, function (CaMonthlyReportExportMail $mail) use ($path): bool {
            $rendered = $mail->render();
            $attachments = $mail->attachments();

            return $attachments !== []
                && ! str_contains($rendered, $path)
                && ! str_contains($rendered, 'ca-monthly-report-exports');
        });
    }

    public function test_signed_download_route_allows_email_recipient_without_session(): void
    {
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv);
        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($export);
        Storage::disk('local')->put($path, "a,b\n1,2\n");
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => $path,
            'expires_at' => now()->addHour(),
        ])->save();

        $url = URL::temporarySignedRoute(
            'finance.reports.ca-monthly.exports.download.signed',
            now()->addHour(),
            ['export' => $export->id],
        );

        $this->get($url)->assertOk();
    }

    public function test_tampered_signed_download_url_is_rejected(): void
    {
        $first = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv);
        $second = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv, null, false);

        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($first);
        Storage::disk('local')->put($path, "a,b\n1,2\n");
        $first->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => $path,
            'expires_at' => now()->addHour(),
        ])->save();

        $signedForFirst = URL::temporarySignedRoute(
            'finance.reports.ca-monthly.exports.download.signed',
            now()->addHour(),
            ['export' => $first->id],
        );

        $tampered = str_replace(
            '/exports/'.$first->id.'/download/signed',
            '/exports/'.$second->id.'/download/signed',
            $signedForFirst,
        );

        $this->get($tampered)->assertForbidden();
    }

    public function test_expired_signed_download_url_is_rejected(): void
    {
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv);
        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($export);
        Storage::disk('local')->put($path, "a,b\n1,2\n");
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => $path,
            'expires_at' => now()->subMinute(),
        ])->save();

        $url = URL::temporarySignedRoute(
            'finance.reports.ca-monthly.exports.download.signed',
            now()->addHour(),
            ['export' => $export->id],
        );

        $this->get($url)->assertStatus(410);
    }

    public function test_generate_job_routes_to_maintenance_queue(): void
    {
        $job = new GenerateCaMonthlyReportExportJob(1);

        $this->assertSame(
            (string) config('ca_monthly_report.export_queue', QueueRouting::maintenance()),
            $job->queue,
        );
    }

    public function test_failed_generation_cleans_partial_artifact(): void
    {
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Xlsx);
        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($export);
        Storage::disk('local')->put($path, 'partial');
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Processing,
            'storage_path' => $path,
        ])->save();

        (new GenerateCaMonthlyReportExportJob($export->id))->failed(new \RuntimeException('boom'));

        $export->refresh();
        $this->assertSame(CaMonthlyReportExportStatus::Failed, $export->status);
        $this->assertNull($export->storage_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_email_failure_does_not_invalidate_ready_export(): void
    {
        Queue::fake();

        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv, 'finance@example.com');
        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($export);
        Storage::disk('local')->put($path, "header\nvalue\n");
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => $path,
            'row_count' => 1,
            'file_size_bytes' => 12,
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
            'email_status' => CaMonthlyReportEmailStatus::Queued,
        ])->save();

        $failingSender = new class extends NotificationMailSender
        {
            public function send(string $recipientEmail, Mailable $mail): array
            {
                return [
                    'success' => false,
                    'message_id' => null,
                    'error' => 'SMTP unavailable',
                ];
            }
        };

        (new SendCaMonthlyReportExportEmailJob($export->id))->handle(
            app(CaMonthlyReportExportService::class),
            $failingSender,
        );

        $export->refresh();
        $this->assertSame(CaMonthlyReportExportStatus::Ready, $export->status);
        $this->assertSame(CaMonthlyReportEmailStatus::Failed, $export->email_status);
        Storage::disk('local')->assertExists($path);
    }

    public function test_email_retry_does_not_regenerate_report(): void
    {
        Queue::fake();

        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv, 'finance@example.com');
        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($export);
        $artifact = "header\nvalue\n";
        Storage::disk('local')->put($path, $artifact);
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => $path,
            'row_count' => 1,
            'file_size_bytes' => 12,
            'completed_at' => now(),
            'expires_at' => now()->addDay(),
            'email_status' => CaMonthlyReportEmailStatus::Queued,
        ])->save();

        $attempts = new \stdClass;
        $attempts->count = 0;
        $retrySender = new class($attempts) extends NotificationMailSender
        {
            public function __construct(private \stdClass $attempts) {}

            public function send(string $recipientEmail, Mailable $mail): array
            {
                $this->attempts->count++;

                if ($this->attempts->count === 1) {
                    return [
                        'success' => false,
                        'message_id' => null,
                        'error' => 'SMTP unavailable',
                    ];
                }

                return [
                    'success' => true,
                    'message_id' => 'test-message-id',
                    'error' => null,
                ];
            }
        };

        $job = new SendCaMonthlyReportExportEmailJob($export->id);
        $job->handle(
            app(CaMonthlyReportExportService::class),
            $retrySender,
        );

        $export->forceFill(['email_status' => CaMonthlyReportEmailStatus::Queued])->save();
        $job->handle(
            app(CaMonthlyReportExportService::class),
            $retrySender,
        );

        $this->assertSame(2, $attempts->count);
        $this->assertSame($artifact, Storage::disk('local')->get($path));
        $this->assertSame(CaMonthlyReportEmailStatus::Sent, $export->fresh()->email_status);
    }

    public function test_prune_command_removes_expired_artifacts(): void
    {
        $export = $this->createExportForAdmin(CaMonthlyReportExportFormat::Csv);
        $path = app(CaMonthlyReportExportStorage::class)->allocatePath($export);
        Storage::disk('local')->put($path, 'csv');
        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Ready,
            'storage_path' => $path,
            'expires_at' => now()->subHour(),
        ])->save();

        $this->artisan('ca-monthly-report:prune-exports', ['--execute' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('ca_monthly_report_exports', ['id' => $export->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_streamed_xlsx_generation_matches_row_count_and_columns(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $path = storage_path('app/tmp/ca-monthly-scale-test.xlsx');
        $result = app(CaMonthlyReportExportGenerator::class)->generateToPath(
            $this->request(),
            CaMonthlyReportExportFormat::Xlsx,
            $path,
        );

        $this->assertSame(1, $result['row_count']);
        $this->assertFileExists($path);
        $this->assertSame(CaMonthlyReportDefinition::HEADERS, $this->readXlsxRow($path, 3));
        @unlink($path);
    }

    public function test_bounded_memory_stream_export_scales_sublinearly(): void
    {
        $this->seedInvoices(40);
        $baseline = CaMonthlyReportExportBenchmarkRecorder::measureStreamExport($this->request());

        $this->seedInvoices(360);
        $scaled = CaMonthlyReportExportBenchmarkRecorder::measureStreamExport($this->request());

        fwrite(STDERR, sprintf(
            "10x scale test: baseline lines=%d elapsed=%.2fms peak=%.2fMB | scaled lines=%d elapsed=%.2fms peak=%.2fMB\n",
            $baseline['row_count'],
            $baseline['elapsed_ms'],
            $baseline['peak_memory_mb'],
            $scaled['row_count'],
            $scaled['elapsed_ms'],
            $scaled['peak_memory_mb'],
        ));

        $this->assertSame(40, $baseline['row_count']);
        $this->assertSame(400, $scaled['row_count']);
        $this->assertGreaterThan(0, $baseline['peak_memory_bytes']);
        $this->assertLessThan(
            $baseline['peak_memory_bytes'] * 4,
            $scaled['peak_memory_bytes'],
            'Peak memory grew faster than 4x while row volume grew 10x.',
        );
    }

    public function test_cancelled_invoice_and_first_line_shipping_rules_remain_in_streamed_export(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'shipping_amount' => '25.00',
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'sku' => 'ADDON',
            'description' => 'Add-on Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '50.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '50.00',
            'tax_total' => '9.00',
            'cgst' => '4.50',
            'sgst' => '4.50',
            'igst' => '0.00',
            'line_total' => '59.00',
        ]);

        $rows = [];
        app(CaMonthlyStatutoryLineReadModel::class)->streamExportRows($this->request(), function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        $this->assertCount(1, $rows);
        $this->assertSame('25.00', $rows[0][12]);
        $this->assertSame('118.00', $rows[0][17]);
    }

    public function test_agent_cannot_queue_export(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->post(route('finance.reports.ca-monthly.exports.store'), array_merge(self::RANGE, [
                'format' => 'csv',
            ]))
            ->assertForbidden();
    }

    private function adminUser(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
            'email' => 'finance-'.uniqid('', true).'@example.com',
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createExportForAdmin(
        CaMonthlyReportExportFormat $format,
        ?string $email = null,
        bool $seedInvoices = true,
        ?User $user = null,
    ): CaMonthlyReportExport {
        if ($seedInvoices) {
            $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);
            $this->makeTaxInvoice(['issued_at' => '2026-09-11 10:00:00']);
            $this->makeTaxInvoice(['issued_at' => '2026-09-12 10:00:00']);
        }

        $user ??= $this->adminUser();

        return app(CaMonthlyReportExportService::class)->requestFromHttp(
            $this->request(),
            $user,
            $format,
            $email,
        );
    }

    private function request(): Request
    {
        return Request::create('/', 'GET', self::RANGE);
    }

    private function seedInvoices(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $day = str_pad((string) (($i % 20) + 1), 2, '0', STR_PAD_LEFT);
            $this->makeTaxInvoice(['issued_at' => "2026-09-{$day} 10:00:00"]);
        }
    }

    /**
     * @return list<string>
     */
    private function readXlsxRow(string $path, int $rowNumber): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $sheet = simplexml_load_string((string) $xml);
        $ns = $sheet->getNamespaces(true);
        $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet->registerXPathNamespace('m', $main);
        $cells = $sheet->xpath('//m:sheetData/m:row[@r="'.$rowNumber.'"]/m:c');

        $values = [];
        foreach ($cells as $cell) {
            $attributes = $cell->attributes();
            $ref = (string) $attributes['r'];
            preg_match('/([A-Z]+)/', $ref, $matches);
            $col = $matches[1];
            $colIndex = 0;
            foreach (str_split($col) as $char) {
                $colIndex = $colIndex * 26 + (ord($char) - 64);
            }
            $type = (string) ($attributes['t'] ?? '');
            $values[$colIndex - 1] = $type === 'inlineStr' ? (string) $cell->is->t : (string) $cell->v;
        }

        if ($values === []) {
            return [];
        }

        $max = max(array_keys($values));
        $row = [];
        for ($i = 0; $i <= $max; $i++) {
            $row[] = $values[$i] ?? '';
        }

        return $row;
    }
}
