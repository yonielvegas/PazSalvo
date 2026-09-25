<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientExcelRequest;
use App\Models\ClientExcelFile;
use App\Services\ClientExcelFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ClientExcelFileController extends Controller
{
    public function index(): Response
    {
        $files = ClientExcelFile::with('uploader:id,name')->orderByDesc('created_at')->orderByDesc('id')
            ->limit(config('paz-salvo.clients_excel_retention'))->get();

        return Inertia::render('admin/clients-excel/index', [
            'files' => $files->values()->map(fn (ClientExcelFile $file, int $index) => [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'size' => $file->size,
                'uploaded_by' => $file->uploader?->name ?? 'Usuario no disponible',
                'date' => $file->created_at->timezone(config('app.timezone'))->format('d/m/Y'),
                'time' => $file->created_at->timezone(config('app.timezone'))->format('h:i a'),
                'is_current' => $index === 0,
                'will_be_replaced' => $files->count() === config('paz-salvo.clients_excel_retention') && $index === $files->count() - 1,
            ]),
            'maxSizeMb' => config('paz-salvo.clients_excel_max_kb') / 1024,
        ]);
    }

    public function store(StoreClientExcelRequest $request, ClientExcelFileService $service): RedirectResponse
    {
        try {
            $service->upload($request->file('file'), $request->user());

            return back()->with('message', 'Excel de clientes cargado correctamente.');
        } catch (Throwable $e) {
            Log::error('Client Excel upload failed', ['exception' => $e]);

            return back()->with('error', 'No se pudo cargar el archivo. Inténtelo de nuevo.');
        }
    }

    public function download(ClientExcelFile $file): StreamedResponse
    {
        $disk = Storage::disk(config('paz-salvo.disk'));
        abort_unless($disk->exists($file->path), 404);

        return $disk->download($file->path, $file->original_name);
    }

    public function destroy(ClientExcelFile $file, ClientExcelFileService $service): RedirectResponse
    {
        try {
            $service->delete($file);

            return back()->with('message', 'Archivo eliminado correctamente.');
        } catch (Throwable $e) {
            Log::error('Client Excel deletion failed', ['exception' => $e]);

            return back()->with('error', 'No se pudo eliminar el archivo. Inténtelo de nuevo.');
        }
    }
}
