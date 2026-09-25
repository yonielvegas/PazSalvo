<?php

namespace Tests\Feature;

use App\Models\ClientExcelFile;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClientExcelFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('paz-salvo.disk'));
        $this->seed(MasterDataSeeder::class);
    }

    private function excel(string $name = 'clientes.xlsx'): UploadedFile
    {
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->setCellValue('A1', 'NAC');
        $path = tempnam(sys_get_temp_dir(), 'excel');
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_permissions_protect_each_endpoint_and_admin_gets_permissions_idempotently(): void
    {
        $user = User::factory()->create();
        $user->assignRole('operador');
        $this->actingAs($user)->get('/admin/clients-excel')->assertForbidden();
        $this->actingAs($user)->post('/admin/clients-excel', ['file' => $this->excel()])->assertForbidden();

        $this->seed(MasterDataSeeder::class);
        $this->assertSame(3, Permission::where('name', 'like', 'clients-excel.%')->count());
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get('/admin/clients-excel')->assertOk();

        $user->givePermissionTo('clients-excel.view');
        $this->actingAs($user)->get('/admin/clients-excel')->assertOk();
        $this->actingAs($user)->post('/admin/clients-excel', ['file' => $this->excel()])->assertRedirect();
        $file = ClientExcelFile::firstOrFail();
        $this->actingAs($user)->get("/admin/clients-excel/{$file->id}/download")->assertForbidden();
        $this->actingAs($user)->delete("/admin/clients-excel/{$file->id}")->assertForbidden();
        $user->givePermissionTo(['clients-excel.download', 'clients-excel.delete']);
        $this->actingAs($user)->get("/admin/clients-excel/{$file->id}/download")->assertOk()->assertDownload('clientes.xlsx');
        $this->actingAs($user)->delete("/admin/clients-excel/{$file->id}")->assertRedirect();
        $this->assertDatabaseMissing('client_excel_files', ['id' => $file->id]);
        Storage::disk(config('paz-salvo.disk'))->assertMissing($file->path);
    }

    public function test_upload_records_metadata_and_rejects_invalid_or_large_files(): void
    {
        $user = User::factory()->create();
        $user->assignRole('operador');
        $user->givePermissionTo('clients-excel.view');
        $this->actingAs($user)->post('/admin/clients-excel', ['file' => UploadedFile::fake()->create('bad.txt', 2, 'text/plain')])->assertSessionHasErrors('file');
        $this->actingAs($user)->post('/admin/clients-excel', ['file' => UploadedFile::fake()->create('fake.xlsx', 2, 'text/plain')])->assertSessionHasErrors('file');
        $this->actingAs($user)->post('/admin/clients-excel', ['file' => UploadedFile::fake()->create('large.xlsx', config('paz-salvo.clients_excel_max_kb') + 1)])->assertSessionHasErrors('file');
        $this->actingAs($user)->post('/admin/clients-excel', ['file' => $this->excel()])->assertSessionHasNoErrors();
        $file = ClientExcelFile::firstOrFail();
        $this->assertSame($user->id, $file->uploaded_by);
        $this->assertSame('clientes.xlsx', $file->original_name);
        $this->assertGreaterThan(0, $file->size);
        $this->assertNotEmpty($file->mime_type);
        Storage::disk(config('paz-salvo.disk'))->assertExists($file->path);
    }

    public function test_fourth_upload_removes_oldest_file_and_duplicate_names_do_not_overwrite(): void
    {
        $user = User::factory()->create();
        $user->assignRole('operador');
        $user->givePermissionTo('clients-excel.view');
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->post('/admin/clients-excel', ['file' => $this->excel()])->assertSessionHasNoErrors();
        }
        $first = ClientExcelFile::orderBy('id')->firstOrFail();
        $others = ClientExcelFile::whereKeyNot($first->id)->pluck('path')->all();
        $this->actingAs($user)->post('/admin/clients-excel', ['file' => $this->excel()])->assertSessionHasNoErrors();
        $this->assertSame(3, ClientExcelFile::count());
        $this->assertSame(3, ClientExcelFile::distinct()->count('path'));
        $this->assertDatabaseMissing('client_excel_files', ['id' => $first->id]);
        Storage::disk(config('paz-salvo.disk'))->assertMissing($first->path);
        foreach ($others as $path) {
            Storage::disk(config('paz-salvo.disk'))->assertExists($path);
        }
        $this->actingAs($user)->get('/admin/clients-excel')->assertInertia(fn ($page) => $page->component('admin/clients-excel/index')->has('files', 3)->where('files.0.is_current', true)->where('files.2.will_be_replaced', true));
    }
}
