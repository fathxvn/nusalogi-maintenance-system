<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'Jika email terdaftar, tautan pemulihan password akan dikirim. Silakan periksa email Anda.';

    public function toResponse(mixed $request): Response
    {
        return $request->wantsJson()
            ? response()->json(['message' => self::MESSAGE])
            : redirect()->route('password.request')->with('status', self::MESSAGE);
    }
}
