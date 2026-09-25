<?php

namespace App\Services;

use App\Models\ClientExcelFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ClientExcelFileService
{
    public function latest(): ?ClientExcelFile
    {
        return ClientExcelFile::orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function upload(UploadedFile $upload, User $user): ClientExcelFile
    {
        $disk = Storage::disk(config('paz-salvo.disk'));
        $storedName = Str::uuid().'.'.strtolower($upload->getClientOriginalExtension());
        $path = config('paz-salvo.clients_excel_dir').'/'.$storedName;

        if (! $disk->putFileAs(config('paz-salvo.clients_excel_dir'), $upload, $storedName)) {
            throw new RuntimeException('No se pudo almacenar el Excel.');
        }

        try {
            return $this->withLockedFiles(function () use ($upload, $user, $path, $storedName) {
                $file = ClientExcelFile::create([
                    'original_name' => basename(str_replace('\\', '/', $upload->getClientOriginalName())),
                    'stored_name' => $storedName,
                    'path' => $path,
                    'mime_type' => $upload->getMimeType(),
                    'size' => $upload->getSize(),
                    'uploaded_by' => $user->id,
                ]);

                $old = ClientExcelFile::orderByDesc('created_at')->orderByDesc('id')
                    ->skip(config('paz-salvo.clients_excel_retention'))->get();
                foreach ($old as $record) {
                    $this->removeRecord($record);
                }

                return $file;
            });
        } catch (Throwable $e) {
            if (! $disk->delete($path)) {
                Log::error('Unable to clean up failed client Excel upload', ['path' => $path]);
            }
            throw $e;
        }
    }

    public function delete(ClientExcelFile $file): void
    {
        $this->withLockedFiles(function () use ($file): void {
            $locked = ClientExcelFile::findOrFail($file->id);
            $this->removeRecord($locked);
        });
    }

    private array $backups = [];

    private function withLockedFiles(callable $callback): mixed
    {
        $this->backups = [];
        try {
            $result = DB::transaction(function () use ($callback) {
                // All writers serialize on one transaction-scoped PostgreSQL lock.
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(20260925, 1)');
                }

                return $callback();
            });
        } catch (Throwable $e) {
            $this->restoreBackups();
            Log::error('Client Excel operation failed', ['exception' => $e]);
            throw $e;
        }

        $disk = Storage::disk(config('paz-salvo.disk'));
        foreach ($this->backups as $backup) {
            if (! $disk->delete($backup['backup'])) {
                Log::error('Unable to remove client Excel backup', $backup);
            }
        }

        return $result;
    }

    private function removeRecord(ClientExcelFile $file): void
    {
        $disk = Storage::disk(config('paz-salvo.disk'));
        if (! $disk->exists($file->path)) {
            throw new RuntimeException('El archivo físico no existe; no se eliminó el registro.');
        }

        $backup = config('paz-salvo.clients_excel_dir').'/.recovery/'.Str::uuid();
        if (! $disk->copy($file->path, $backup)) {
            throw new RuntimeException('No se pudo respaldar el archivo para eliminarlo.');
        }
        $this->backups[] = ['path' => $file->path, 'backup' => $backup];
        if (! $disk->delete($file->path)) {
            throw new RuntimeException('No se pudo eliminar el archivo físico.');
        }
        $file->delete();
    }

    private function restoreBackups(): void
    {
        $disk = Storage::disk(config('paz-salvo.disk'));
        foreach ($this->backups as $backup) {
            if (! $disk->copy($backup['backup'], $backup['path'])) {
                Log::critical('Unable to restore client Excel after database failure', $backup);

                continue;
            }
            if (! $disk->delete($backup['backup'])) {
                Log::error('Unable to remove restored client Excel backup', $backup);
            }
        }
    }
}
