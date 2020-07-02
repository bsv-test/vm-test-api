<?php

namespace Tests;

use App\EmailVerificationCode;
use App\Mail\VerificationCodeMailable;
use App\User;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Collection;

class EmailVerificationControllerTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->user = factory(User::class)->create();
    }

    public function testSendCode(): void
    {
        $this->post(route('send-code'), ['email' => $this->user->email])
            ->assertResponseOk();

        Mail::assertSent(VerificationCodeMailable::class);
    }

    public function testHourLimit()
    {
        $originalTime = Carbon::now();
        Carbon::setTestNow(Carbon::now()->subMinutes(55));
        Collection::times(4, function ($i) {
            EmailVerificationCode::createForUser($this->user);
        });
        Carbon::setTestNow($originalTime);
        EmailVerificationCode::createForUser($this->user);

        $this->post(route('send-code'), ['email' => $this->user->email])
            ->assertResponseStatus(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function test5MinutesLimit()
    {
        $originalTime = Carbon::now();
        Carbon::setTestNow(Carbon::now()->subMinutes(4));
        EmailVerificationCode::createForUser($this->user);
        Carbon::setTestNow($originalTime);

        $this->post(route('send-code'), ['email' => $this->user->email])
            ->assertResponseStatus(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testPreviousCodeInvalidation()
    {
        $code1 = EmailVerificationCode::createForUser($this->user);
        $this->assertEquals(EmailVerificationCode::STATE_CREATED, $code1->state);
        $code2 = EmailVerificationCode::createForUser($this->user);
        $code1->refresh();
        $this->assertEquals(EmailVerificationCode::STATE_INVALIDATED, $code1->state);
        $this->assertEquals(EmailVerificationCode::STATE_CREATED, $code2->state);
    }

    public function testCodeActivation()
    {
        $code = EmailVerificationCode::createForUser($this->user);
        $requestData = ['email' => $this->user->email, 'code' => $code->code];
        $this->post(route('check-code'), $requestData)->assertResponseOk();
        $this->post(route('check-code'), $requestData)->assertResponseStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $code->refresh();
        $this->assertEquals(EmailVerificationCode::STATE_ACTIVATED, $code->state);
    }

    public function testCodeLifetime()
    {
        $originalTime = Carbon::now();
        Carbon::setTestNow(Carbon::now()->subMinutes(6));
        $code = EmailVerificationCode::createForUser($this->user);
        Carbon::setTestNow($originalTime);
        $this->post(
            route('check-code'),
            ['email' => $this->user->email, 'code' => $code->code]
        )->assertResponseStatus(Response::HTTP_UNPROCESSABLE_ENTITY);

        $code->refresh();
        $this->assertEquals(EmailVerificationCode::STATE_EXPIRED, $code->state);
    }

    public function testWrongCodeAttempts()
    {
        $code = EmailVerificationCode::createForUser($this->user);
        $code->code = '0000';
        $code->save();
        $requestData = ['email' => $this->user->email, 'code' => '1234'];
        $this->post(route('check-code'), $requestData);
        $this->post(route('check-code'), $requestData);
        $this->post(route('check-code'), $requestData)
            ->assertResponseStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $code->refresh();
        $this->assertEquals(EmailVerificationCode::STATE_INVALIDATED, $code->state);
    }

    // FIXME: useless test
    public function testLimitAfterActivation()
    {
        $code = EmailVerificationCode::createForUser($this->user);
        $requestData = ['email' => $this->user->email, 'code' => $code->code];
        $this->post(route('send-code'), ['email' => $this->user->email])
            ->assertResponseStatus(Response::HTTP_TOO_MANY_REQUESTS);
        $this->post(route('check-code'), $requestData)->assertResponseOk();
        $this->post(route('send-code'), ['email' => $this->user->email])->assertResponseOk();
    }
}
