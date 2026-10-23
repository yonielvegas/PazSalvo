<?php

namespace App\Http\Controllers;

use App\Http\Requests\SsoIdentityLinkRequest;
use App\Integrations\AaudAuth\Exceptions\AaudAuthRequestException;
use App\Integrations\AaudAuth\Exceptions\TechnicalAuthenticationException;
use App\Integrations\AaudAuth\Http\AaudAuthClient;
use App\Models\User;
use App\Services\UserSsoIdentityManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UserSsoIdentityController extends Controller
{
    public function show(User $user): JsonResponse
    {
        Gate::authorize('manage-users');
        $identity = $user->ssoIdentity()->first();

        return response()->json(['identity' => $identity ? [
            'id' => $identity->id, 'issuer' => $identity->issuer, 'subject' => $identity->subject,
            'linked_at' => $identity->created_at->toIso8601String(),
            'verified_profile' => $identity->verified_profile,
        ] : null, 'directory_available' => config('aaud-auth.enabled') === true && is_string(config('aaud-auth.issuer'))
            && config('aaud-auth.issuer') !== ''])->header('Cache-Control', 'no-store, private');
    }

    public function search(Request $request, AaudAuthClient $client, UserSsoIdentityManager $manager): JsonResponse
    {
        Gate::authorize('manage-users');
        $data = $request->validate($this->searchRules());
        try {
            $results = $client->searchDirectory($data['query'], $data['page'] ?? 1);
            $results['data'] = array_map(fn ($row) => [...$row, 'verified_profile' => $manager->verifiedProfile($row)], $results['data']);
        } catch (AaudAuthRequestException|TechnicalAuthenticationException) {
            return response()->json(['message' => $this->directoryError()], 503)->header('Cache-Control', 'no-store, private');
        }

        return response()->json($results)->header('Cache-Control', 'no-store, private');
    }

    public function store(SsoIdentityLinkRequest $request, User $user, UserSsoIdentityManager $manager): RedirectResponse
    {
        Gate::authorize('manage-users');
        $data = $request->validated();
        try {
            $manager->link($user, $request->user(), $data, $request);
        } catch (AaudAuthRequestException|TechnicalAuthenticationException) {
            throw ValidationException::withMessages(['identity' => $this->directoryError()]);
        }

        return back()->with('message', 'Identidad SSO vinculada correctamente.');
    }

    public function destroy(Request $request, User $user, UserSsoIdentityManager $manager): RedirectResponse
    {
        Gate::authorize('manage-users');
        $data = $request->validate(['identity_id' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'accepted']]);
        $manager->unlink($user, $request->user(), $data['identity_id'], $request);

        return back()->with('message', 'Identidad SSO desvinculada correctamente.');
    }

    private function searchRules(): array
    {
        return ['query' => ['required', 'string', 'min:3', 'max:80', 'regex:/\A[^\x00-\x1f\x7f]+\z/uD'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10']];
    }

    private function directoryError(): string
    {
        return 'No se pudo verificar el directorio. Revise la configuración de AAUD AUTH, el emisor y la autorización directory.read del cliente técnico. No se ha vinculado ninguna identidad.';
    }
}
