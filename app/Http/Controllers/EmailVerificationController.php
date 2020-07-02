<?php

namespace App\Http\Controllers;

use App\EmailVerificationCode;
use App\Http\Controllers\Controller;
use App\Mail\VerificationCodeMailable;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

class EmailVerificationController extends Controller
{
    public function sendCode(Request $request)
    {
        $this->validate($request, ['email' => 'required|email']);
        $user = User::where('email', $request->input('email'))->firstOrFail();
        if (!EmailVerificationCode::canUserGetNewCode($user)) {
            return response()->json(
                ['status' => 'error', 'message' => 'Limit per hour exceeded'],
                Response::HTTP_TOO_MANY_REQUESTS
            );
        }

        $code = EmailVerificationCode::createForUser($user);
        Mail::to($user)->send(new VerificationCodeMailable($code));
        $code->markAsSent();
        return response()->json(['status' => 'success']);
    }

    public function checkCode(Request $request)
    {
        $this->validate($request, [
            'email' => 'required|email',
            'code' => 'required|integer'
        ]);

        $checkResult = EmailVerificationCode::check($request->input('email'), $request->input('code'));
        if ($checkResult) {
            return response()->json(['status' => 'success']);
        }

        return response()->json(
            ['status' => 'error', 'message' => "Wrong verification code"],
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }
}
