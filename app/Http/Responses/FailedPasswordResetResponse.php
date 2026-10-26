<?php

namespace App\Http\Responses;

use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Symfony\Component\HttpFoundation\Response;

class FailedPasswordResetResponse implements FailedPasswordResetResponseContract
{
    public function toResponse(mixed $request): Response
    {
        throw ValidationException::withMessages([
            'email' => 'Tautan pemulihan tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.',
        ]);
    }
}
