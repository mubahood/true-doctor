<?php

namespace App\Http\Controllers;

use App\Support\HumanCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The pictures behind the public forms' check, and a fresh one on request
 * ("Can't read it? New picture"). Never cached: a picture is one use.
 */
class HumanCheckController extends Controller
{
    /** The forms that ask — anything else is not a form of ours. */
    public const FORMS = ['register', 'contact', 'forgot-password', 'login'];

    public function image(string $id): Response
    {
        $code = HumanCheck::code($id);
        abort_if($code === null, 404);

        return response(HumanCheck::image($code), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    public function fresh(Request $request): JsonResponse
    {
        $form = (string) $request->query('form', '');
        abort_unless(in_array($form, self::FORMS, true), 404);

        $id = HumanCheck::issue($form);

        return response()->json(['id' => $id, 'src' => route('human-check.image', $id)])
            ->header('Cache-Control', 'no-store');
    }
}
